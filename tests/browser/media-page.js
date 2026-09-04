/**
 * Browser-Test für den Abschnitt "swipe Bilder" auf Einstellungen -> Medien.
 * Prüft echtes Rendering, den Regler, das Speichern, die Vorschau über die
 * Mediathek und den Batch-Regenerierungslauf in einem echten Chromium.
 */
'use strict';

const assert = require('assert');
const path = require('path');
const { chromium } = require('playwright');

const { WP_URL, WP_USER, WP_PASS } = process.env;
if (!WP_URL || !WP_USER || !WP_PASS) {
  console.error('FAIL: WP_URL, WP_USER oder WP_PASS fehlt in der Umgebung.');
  process.exit(1);
}

const SCREEN_DIR = path.join(__dirname, 'tmp');
const shot = (page, name) => page.screenshot({ path: path.join(SCREEN_DIR, name), fullPage: true });

// Wandelt die von WordPress' size_format() erzeugten Grössenangaben
// ("34 KB", "1,023 B", "1 MB", "Bytes"-Sonderfall) in Bytes um.
function parseSize(str) {
  const m = str.trim().match(/^([\d.,]+)\s*(TB|GB|MB|KB|Bytes?|B)$/i);
  assert(m, `Grösse nicht lesbar: "${str}"`);
  const num = parseFloat(m[1].replace(/,/g, ''));
  const unit = m[2].toUpperCase();
  const mult = { B: 1, BYTE: 1, BYTES: 1, KB: 1024, MB: 1024 ** 2, GB: 1024 ** 3, TB: 1024 ** 4 }[unit];
  return num * mult;
}

function ok(msg) {
  console.log('OK: ' + msg);
}

async function main() {
  const browser = await chromium.launch();
  let page;
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1400, height: 1000 } });
    page = await context.newPage();

    const errors = [];
    page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
    page.on('console', (msg) => { if (msg.type() === 'error') errors.push('console: ' + msg.text()); });

    // Login
    await page.goto(`${WP_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', WP_USER);
    await page.fill('#user_pass', WP_PASS);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    assert(page.url().includes('/wp-admin'), `Login hat nicht zu /wp-admin navigiert: ${page.url()}`);

    // a) Abschnitt auf Einstellungen -> Medien
    await page.goto(`${WP_URL}/wp-admin/options-media.php`, { waitUntil: 'domcontentloaded' });
    const heading = page.locator('h2', { hasText: 'swipe Bilder' }).first();
    await heading.waitFor({ state: 'visible' });
    ok('Abschnitt "swipe Bilder" ist sichtbar');

    // b) Statuskasten und AVIF-Verzweigung
    const statusBox = page.locator('.swipe-images-status');
    const statusText = await statusBox.innerText();
    assert(statusText.includes('Modus: aktiv'), `Statuskasten enthält kein "Modus: aktiv": ${statusText}`);
    const avifMatch = statusText.match(/AVIF (ja|nein)/);
    assert(avifMatch, `AVIF-Status nicht gefunden: ${statusText}`);
    const avifSupported = avifMatch[1] === 'ja';
    const avifRadio = page.locator('input[name="swipe_images_settings[format]"][value="avif"]');
    if (!avifSupported) {
      assert(await avifRadio.isDisabled(), 'AVIF-Radio sollte deaktiviert sein (kein AVIF-Editor)');
      await page.locator('text=kann kein AVIF schreiben').first().waitFor({ state: 'visible' });
      assert(await page.locator('#swipe-images-qa').isDisabled(), '#swipe-images-qa sollte deaktiviert sein');
    } else {
      assert(!(await avifRadio.isDisabled()), 'AVIF-Radio sollte aktiv sein (Editor kann AVIF)');
    }
    ok(`Statuskasten korrekt (AVIF ${avifSupported ? 'ja' : 'nein'})`);

    // c) Regler und Zahlenfeld sind gekoppelt
    await page.fill('#swipe-images-qw-n', '70');
    assert.strictEqual(await page.inputValue('#swipe-images-qw'), '70', 'Regler folgt dem Zahlenfeld nicht');
    await page.evaluate(() => {
      const el = document.querySelector('#swipe-images-qw');
      el.value = '75';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    assert.strictEqual(await page.inputValue('#swipe-images-qw-n'), '75', 'Zahlenfeld folgt dem Regler nicht');
    ok('Regler und Zahlenfeld sind gekoppelt');

    // d) Speichern
    await shot(page, '01-section.png');
    await Promise.all([page.waitForNavigation(), page.click('#submit')]);
    assert.strictEqual(await page.inputValue('#swipe-images-qw-n'), '75', 'Wert nach dem Speichern nicht 75');
    const success = page.locator('.notice-success, #setting-error-settings_updated').first();
    await success.waitFor({ state: 'visible' });
    ok('Einstellungen gespeichert, Erfolgsmeldung sichtbar');
    await shot(page, '02-saved.png');

    // e) Vorschau über die Mediathek
    await page.click('#swipe-images-pick');
    await page.locator('.media-modal').waitFor({ state: 'visible' });
    // sprachtolerant: Zielinstanz ist Englisch ("Media Library"), Deutsch nur als Fallback
    const libraryTab = page.locator('.media-menu-item', { hasText: /Media Library|Mediathek/ });
    if (await libraryTab.count() > 0) {
      await libraryTab.first().click();
    }
    await page.locator('.attachments .attachment').first().waitFor({ state: 'visible' });
    await page.locator('.attachments .attachment').first().click();
    await page.click('.media-button-select');
    await page.waitForFunction(
      () => document.querySelectorAll('#swipe-images-preview figure').length === 3,
      null,
      { timeout: 60000 }
    );
    const captions = await page.locator('#swipe-images-preview figcaption').allTextContents();
    const parsed = captions.map((c) => {
      const m = c.match(/^Qualität (\d+) · (.+)$/);
      assert(m, `figcaption nicht lesbar: "${c}"`);
      return { quality: parseInt(m[1], 10), bytes: parseSize(m[2]) };
    });
    assert.strictEqual(parsed.length, 3, `3 Vorschaubilder erwartet, ${parsed.length} erhalten`);
    for (let i = 1; i < parsed.length; i++) {
      assert(parsed[i].quality > parsed[i - 1].quality, `Qualität nicht aufsteigend: ${JSON.stringify(parsed)}`);
      assert(parsed[i].bytes > parsed[i - 1].bytes, `Dateigrösse nicht aufsteigend: ${JSON.stringify(parsed)}`);
    }
    ok(`Vorschau: 3 Bilder, Qualität und Grösse aufsteigend (${JSON.stringify(parsed)})`);
    await shot(page, '03-preview.png');

    // f) Bestand regenerieren
    const regenBtn = page.locator('#swipe-images-regen');
    assert(!(await regenBtn.isDisabled()), 'Regenerieren-Button sollte aktiv sein');
    const regenText = await regenBtn.innerText();
    assert(/\(3 ausstehend\)/.test(regenText), `Button nennt nicht 3 ausstehende Bilder: "${regenText}"`);
    await regenBtn.click();
    await page.waitForFunction(
      () => {
        const el = document.querySelector('#swipe-images-bar span');
        return !!el && el.textContent.trim() === '100%';
      },
      null,
      { timeout: 180000 }
    );
    const logText = await page.locator('#swipe-images-log').innerText();
    assert(logText.includes('3 regeneriert'), `Log ohne "3 regeneriert": "${logText}"`);
    assert.strictEqual((await page.locator('#swipe-images-pending').innerText()).trim(), '0', 'pending nicht auf 0');
    assert(!(await regenBtn.isDisabled()), 'Regenerieren-Button nach dem Lauf nicht wieder aktiv');
    ok('Bestand regeneriert: 3 regeneriert, 0 ausstehend, Button wieder aktiv');
    await shot(page, '04-batch.png');

    // g) keine JS-Fehler während des ganzen Laufs
    if (errors.length > 0) {
      errors.forEach((e) => console.error('  ' + e));
    }
    assert.strictEqual(errors.length, 0, `${errors.length} JS-Fehler aufgetreten`);
    ok('keine JavaScript-Fehler');
  } catch (err) {
    console.error('FAIL: ' + err.message);
    if (page) {
      try { await shot(page, '99-failure.png'); } catch (_) { /* egal */ }
    }
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
}

main();
