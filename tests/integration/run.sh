#!/usr/bin/env bash
# Integrationslauf gegen das Starter-Local. Schaltet Themes um und stellt sie am Ende zurück.
set -euo pipefail

SITE=/Users/swipegmbh/Sites/swipe.wordpress-starter.local
export WP_CLI_PHP=/Applications/MAMP/bin/php/php8.3.30/bin/php
TMP="$(cd "$(dirname "$0")" && pwd)/tmp"
mkdir -p "$TMP"
cd "$SITE"

fail() { echo "FAIL: $*" >&2; exit 1; }
ok()   { echo "OK: $*"; }

fixture() {  # $1 = Zielpfad, erzeugt ein 3000x2000-JPEG mit Rauschen, damit Quality die Grösse beeinflusst
  "$WP_CLI_PHP" -r '
    $im = imagecreatetruecolor(3000, 2000);
    mt_srand(42);
    for ($y = 0; $y < 2000; $y += 8) { for ($x = 0; $x < 3000; $x += 8) {
      $c = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
      imagefilledrectangle($im, $x, $y, $x + 7, $y + 7, $c);
    } }
    imagejpeg($im, $argv[1], 92);' "$1"
}

size_of_large() {  # $1 = Attachment-ID → Bytes der "large"-Datei
  wp eval "\$m = wp_get_attachment_metadata($1); \$f = dirname(get_attached_file($1)) . '/' . \$m['sizes']['large']['file']; echo filesize(\$f);"
}

png_fixture() {  # $1 = Zielpfad, erzeugt ein 800x600-PNG mit Rauschen
  "$WP_CLI_PHP" -r '
    $im = imagecreatetruecolor(800, 600);
    mt_srand(7);
    for ($y = 0; $y < 600; $y += 4) { for ($x = 0; $x < 800; $x += 4) {
      $c = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
      imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $c);
    } }
    imagepng($im, $argv[1]);' "$1"
}

pending_count() {  # ausstehende Bilder aus der Statuszeile
  wp swipe-images status | sed -n 's/.*, \([0-9][0-9]*\) ausstehend.*/\1/p'
}

set_setting() {  # $1 = Schluessel, $2 = PHP-Literal; merged mit den bestehenden Werten
  wp eval "\$s = Swipe_Images_Settings::get(); \$s['$1'] = $2; update_option('swipe_images_settings', \$s);"
}

MU="$SITE/wp-content/mu-plugins"; mkdir -p "$MU"

# M-9: Zeilenstand vor dem Lauf, damit am Ende nur neue Zeilen zaehlen.
DEBUG_LOG="$SITE/wp-content/debug.log"
DEBUG_LINES=0
[ -f "$DEBUG_LOG" ] && DEBUG_LINES=$(wc -l < "$DEBUG_LOG" | tr -d ' ')

ORIG_THEME="$(wp theme list --status=active --field=name)"
trap 'wp theme activate "$ORIG_THEME" >/dev/null 2>&1 || true; rm -f "$MU"/zz-swipe-images-test-*.php' EXIT

wp theme activate twentytwentyfive >/dev/null
wp plugin activate swipe-images >/dev/null 2>&1 || true
wp option update swipe_images_settings '{"enabled":true,"format":"webp","convert_png":true,"quality_webp":82}' --format=json >/dev/null

# 1) Upload im aktiven Modus: alles WebP, Original bleibt
[ "$(wp eval 'echo Swipe_Images::is_blocked() ? 1 : 0;')" = "0" ] || fail "Plugin blockiert, obwohl twentytwentyfive aktiv ist"
fixture "$TMP/photo.jpg"
ID=$(wp media import "$TMP/photo.jpg" --porcelain)
wp eval "
\$m = wp_get_attachment_metadata($ID); \$f = get_attached_file($ID);
if (substr(\$f, -5) !== '.webp') { echo 'attached file ist ' . \$f; exit(1); }
if (empty(\$m['original_image'])) { echo 'original_image fehlt'; exit(1); }
foreach (\$m['sizes'] as \$n => \$s) { if (substr(\$s['file'], -5) !== '.webp') { echo 'size ' . \$n . ' ist ' . \$s['file']; exit(1); } }
\$html = wp_get_attachment_image($ID, 'large');
if (strpos(\$html, 'srcset=') === false) { echo 'kein srcset'; exit(1); }
if (preg_match('/\.(jpe?g|png)\b/i', \$html)) { echo 'HTML enthaelt jpg/png: ' . \$html; exit(1); }
" || fail "Upload aktiver Modus"
ok "Upload aktiv: Full, scaled und Sizes sind WebP, srcset vorhanden"

# 2) Qualität wirkt: 60 kleiner als 90
wp option update swipe_images_settings '{"quality_webp":60}' --format=json >/dev/null
ID60=$(wp media import "$TMP/photo.jpg" --porcelain)
wp option update swipe_images_settings '{"quality_webp":90}' --format=json >/dev/null
ID90=$(wp media import "$TMP/photo.jpg" --porcelain)
S60=$(size_of_large "$ID60"); S90=$(size_of_large "$ID90")
[ "$S60" -lt "$S90" ] || fail "Quality 60 ($S60 B) nicht kleiner als 90 ($S90 B)"
ok "Quality wirkt: large bei 60 = $S60 B, bei 90 = $S90 B"

# 3) AVIF ohne Serverunterstützung fällt auf WebP zurück
wp option update swipe_images_settings '{"format":"avif","quality_webp":82}' --format=json >/dev/null
IDA=$(wp media import "$TMP/photo.jpg" --porcelain)
EXT=$(wp eval "echo pathinfo(get_attached_file($IDA), PATHINFO_EXTENSION);")
AVIF_OK=$(wp eval 'echo Swipe_Images_Detector::editor_supports("image/avif") ? 1 : 0;')
if [ "$AVIF_OK" = "1" ]; then [ "$EXT" = "avif" ] || fail "AVIF unterstützt, aber Datei ist .$EXT"; else [ "$EXT" = "webp" ] || fail "AVIF-Fallback lieferte .$EXT"; fi
ok "Format avif → .$EXT (Editor kann AVIF: $AVIF_OK)"
wp option update swipe_images_settings '{"format":"webp"}' --format=json >/dev/null

# --- TASK5 ---
# 4) Aktiver Modus liefert die Kompat-API
[ "$(wp eval 'echo (int) function_exists("swipe_responsive_image");')" = "1" ] || fail "swipe_responsive_image fehlt im aktiven Modus"
wp eval "\$html = swipe_responsive_image($ID, 'large', array('class' => 'x'), '100vw'); if (strpos(\$html, 'srcset=') === false || strpos(\$html, '.webp') === false) { echo \$html; exit(1); }" || fail "swipe_responsive_image ohne srcset/webp"
[ "$(wp eval 'echo swipe_get_webp_url("https://x.test/a.jpg");')" = "https://x.test/a.jpg" ] || fail "swipe_get_webp_url verändert die URL"
ok "Kompat-API im aktiven Modus"

# 4b) Ein Theme-Filter mit Rückgabe 100 wird überstimmt (Priorität 999)
printf '%s\n' '<?php' 'add_filter("wp_editor_set_quality", function () { return 100; }, 10);' > "$MU/zz-swipe-images-test-quality.php"
wp option update swipe_images_settings '{"quality_webp":60}' --format=json >/dev/null
IDF=$(wp media import "$TMP/photo.jpg" --porcelain)
SF=$(size_of_large "$IDF")
rm -f "$MU/zz-swipe-images-test-quality.php"
wp option update swipe_images_settings '{"quality_webp":82}' --format=json >/dev/null
[ "$SF" -eq "$S60" ] || fail "Theme-Filter 100 hat gewonnen: large bei 60 mit Filter = $SF B, ohne = $S60 B"
ok "Fremder Quality-Filter (100) wird überstimmt"
wp post delete "$IDF" --force >/dev/null

# 5) Blockierter Modus gegen das DCT-Theme: kein Fatal, kein Filter, Hinweis, Site Health critical
wp theme activate datacenterthurgau >/dev/null
[ "$(wp eval 'echo Swipe_Images::is_blocked() ? 1 : 0;')" = "1" ] || fail "DCT-Theme aktiv, Plugin aber nicht blockiert"
[ "$(wp eval 'echo (int) has_filter("image_editor_output_format");')" = "0" ] || fail "Output-Format-Filter im blockierten Modus registriert"
NOTICE=$(wp eval 'wp_set_current_user(1); $a = new Swipe_Images_Admin("swipe-images", "1.0.0"); ob_start(); $a->notice_blocked(); echo ob_get_clean();')
echo "$NOTICE" | grep -q "swipe Bilder ist inaktiv" || fail "Blockiert-Hinweis fehlt"
[ "$(wp eval '$a = new Swipe_Images_Admin("swipe-images", "1.0.0"); echo $a->site_health_test()["status"];')" = "critical" ] || fail "Site Health nicht critical"
ok "Blockierter Modus: kein Fatal, kein Filter, Hinweis und Site Health critical"
wp theme activate twentytwentyfive >/dev/null

# --- TASK6 ---
# 6) Status läuft in beiden Modi
wp swipe-images status | grep -q "Modus:      aktiv" || fail "status im aktiven Modus"

# 7) Regenerate im blockierten Modus: JPEG-Uploads unter dem DCT-Theme werden WebP, alte Dateien bleiben
wp theme activate datacenterthurgau >/dev/null
IDL1=$(wp media import "$TMP/photo.jpg" --porcelain)
IDL2=$(wp media import "$TMP/photo.jpg" --porcelain)
[ "$(wp eval "echo pathinfo(get_attached_file($IDL1), PATHINFO_EXTENSION);")" = "jpg" ] || fail "Upload im blockierten Modus sollte JPEG bleiben"
OLD_LARGE=$(wp eval "\$m = wp_get_attachment_metadata($IDL1); echo dirname(get_attached_file($IDL1)) . '/' . \$m['sizes']['large']['file'];")
wp swipe-images status | grep -q "Modus:      blockiert" || fail "status im blockierten Modus"
wp swipe-images regenerate --ids="$IDL1,$IDL2" --yes | grep -q "2 regeneriert, 0 Fehler" || fail "regenerate --ids"
[ "$(wp eval "echo pathinfo(get_attached_file($IDL1), PATHINFO_EXTENSION);")" = "webp" ] || fail "nach regenerate nicht webp"
[ -f "$OLD_LARGE" ] || fail "alte large-Datei wurde ohne --delete-old gelöscht"
ok "Regenerate im blockierten Modus, alte Dateien bleiben"

# 8) --delete-old entfernt die alte Datei, Original bleibt
wp swipe-images regenerate --ids="$IDL1" --delete-old --yes >/dev/null
[ ! -f "$OLD_LARGE" ] || fail "--delete-old hat die alte large-Datei nicht entfernt"
ORIG=$(wp eval "echo wp_get_original_image_path($IDL1);")
[ -f "$ORIG" ] || fail "Original $ORIG fehlt nach --delete-old"
ok "--delete-old entfernt alte Grössen, Original bleibt"

# 9) Alle ausstehenden: pending danach 0
wp swipe-images regenerate --yes >/dev/null
wp swipe-images status | grep -q " 0 ausstehend" || fail "nach regenerate noch ausstehende Bilder"
ok "regenerate ohne --ids bringt pending auf 0"

# 10) cleanup --dry-run findet eine Waise und löscht nichts
STRAY_DIR=$(dirname "$ORIG"); cp "$ORIG" "$STRAY_DIR/waise.jpg"; echo x > "$STRAY_DIR/waise.webp"
wp swipe-images cleanup --dry-run | grep -q "waise.webp" || fail "cleanup --dry-run findet die Waise nicht"
[ -f "$STRAY_DIR/waise.webp" ] || fail "dry-run hat gelöscht"
wp swipe-images cleanup --yes >/dev/null
[ ! -f "$STRAY_DIR/waise.webp" ] || fail "cleanup hat die Waise nicht gelöscht"
rm -f "$STRAY_DIR/waise.jpg"
ok "cleanup: dry-run listet, Lauf löscht"
wp theme activate twentytwentyfive >/dev/null
wp post delete "$IDL1" "$IDL2" --force >/dev/null

# --- FINAL ---
# 11) I-4: das Mapping steuert den Zaehler. PNG zaehlt nur, wenn convert_png an ist.
PEND0=$(pending_count)
[ "$PEND0" = "0" ] || fail "Ausgangslage nicht 0 ausstehend, sondern $PEND0"
set_setting convert_png false
png_fixture "$TMP/flat.png"
IDP=$(wp media import "$TMP/flat.png" --porcelain)
[ "$(wp eval "echo pathinfo(get_attached_file($IDP), PATHINFO_EXTENSION);")" = "png" ] || fail "PNG wurde trotz convert_png=false konvertiert"
[ "$(pending_count)" = "$PEND0" ] || fail "PNG zaehlt als ausstehend, obwohl convert_png aus ist ($(pending_count) statt $PEND0)"
set_setting convert_png true
[ "$(pending_count)" = "1" ] || fail "PNG zaehlt mit convert_png=true nicht als ausstehend ($(pending_count))"
wp swipe-images regenerate --yes >/dev/null
[ "$(wp eval "echo pathinfo(get_attached_file($IDP), PATHINFO_EXTENSION);")" = "webp" ] || fail "PNG nach regenerate nicht webp"
[ "$(pending_count)" = "0" ] || fail "nach dem PNG-Lauf noch ausstehende Bilder"
wp post delete "$IDP" --force >/dev/null
ok "I-4: Zaehler folgt dem Mapping, PNG erst mit convert_png=true"

# 12) M-2: die Vorschau schreibt drei feste Slots, egal wie oft und mit welcher Qualitaet
PREVIEW_DIR="$(wp eval 'echo wp_get_upload_dir()["basedir"];')/swipe-images-preview"
rm -rf "$PREVIEW_DIR"
preview() {  # $1 = Attachment-ID, $2 = Qualitaet; ajax_preview() endet in wp_die, darum || true
  wp eval "
    wp_set_current_user(1);
    \$_POST['attachment_id'] = $1; \$_POST['quality'] = $2;
    \$_REQUEST['nonce'] = \$_POST['nonce'] = wp_create_nonce('swipe_images_preview');
    \$a = new Swipe_Images_Admin('swipe-images', '1.0.0');
    \$a->ajax_preview();" 2>/dev/null || true
}
preview "$ID" 60 | grep -q '"success":true' || fail "Vorschau bei Qualitaet 60 fehlgeschlagen"
preview "$ID" 90 | grep -q '"success":true' || fail "Vorschau bei Qualitaet 90 fehlgeschlagen"
PREVIEW_FILES=$(find "$PREVIEW_DIR" -type f | wc -l | tr -d ' ')
[ "$PREVIEW_FILES" = "3" ] || fail "Vorschau hinterlaesst $PREVIEW_FILES Dateien statt 3"
ok "M-2: zwei Vorschaulaeufe hinterlassen drei Dateien"

# 13) I-1: ohne AVIF-Unterstuetzung tragen format und quality_avif ein verstecktes Feld
AVIF_OK=$(wp eval 'echo Swipe_Images_Detector::editor_supports("image/avif") ? 1 : 0;')
FIELDS=$(wp eval '$a = new Swipe_Images_Admin("swipe-images", "1.0.0"); ob_start(); $a->render_fields(); echo ob_get_clean();')
if [ "$AVIF_OK" = "0" ]; then
  echo "$FIELDS" | grep -qF 'type="hidden" name="swipe_images_settings[format]"' || fail "verstecktes Feld fuer format fehlt"
  echo "$FIELDS" | grep -qF 'type="hidden" name="swipe_images_settings[quality_avif]"' || fail "verstecktes Feld fuer quality_avif fehlt"
  ok "I-1: versteckte Felder fuer format und quality_avif vorhanden"
else
  ok "I-1: Editor kann AVIF, versteckte Felder werden korrekt nicht ausgegeben"
fi

# 14) C-1a: unlesbare Quelldatei meldet einen Fehler statt Erfolg
IDC=$(wp media import "$TMP/photo.jpg" --porcelain)
ORIGC=$(wp eval "echo wp_get_original_image_path($IDC);")
echo x > "$ORIGC"
OUTC=$(wp swipe-images regenerate --ids="$IDC" --yes 2>&1 || true)
echo "$OUTC" | grep -q "0 regeneriert, 1 Fehler" || fail "kaputtes Bild meldet keinen Fehler: $OUTC"
wp option get swipe_images_failed --format=json | grep -q "\"$IDC\"" || fail "ID $IDC fehlt in der Fehlerliste"
wp post delete "$IDC" --force >/dev/null
ok "C-1a: unlesbare Quelldatei landet als Fehler in der Fehlerliste"

# 14b) C-1: Ergebnis nicht im Zielformat. Ein mu-Plugin raeumt das Mapping ab, das Bild bleibt
#      lesbar und wird als JPEG gespeichert - genau der Pfad, den Core mit Erfolg quittiert.
printf '%s\n' '<?php' 'add_filter("image_editor_output_format", "__return_empty_array", 999);' > "$MU/zz-swipe-images-test-nomap.php"
IDN=$(wp media import "$TMP/photo.jpg" --porcelain)
[ "$(wp eval "echo pathinfo(get_attached_file($IDN), PATHINFO_EXTENSION);")" = "jpg" ] || fail "mu-Plugin hat das Mapping nicht abgeraeumt"
OUTN=$(wp swipe-images regenerate --ids="$IDN" --yes 2>&1 || true)
rm -f "$MU/zz-swipe-images-test-nomap.php"
echo "$OUTN" | grep -q "Nicht konvertiert" || fail "nicht konvertiertes Ergebnis wird als Erfolg gemeldet: $OUTN"
echo "$OUTN" | grep -q "0 regeneriert, 1 Fehler" || fail "Zaehler meldet Erfolg trotz fehlender Konvertierung: $OUTN"
wp option get swipe_images_failed --format=json | grep -q "\"$IDN\"" || fail "ID $IDN fehlt in der Fehlerliste"
wp post delete "$IDN" --force >/dev/null
ok "C-1: Ergebnis ausserhalb des Zielformats wird als Fehler gemeldet"
wp eval 'Swipe_Images_Regenerator::clear_failed();'

# 15) M-9: keine neuen Plugin-Zeilen im debug.log. Die eine Zeile aus dem WP_DEBUG-Log
#     (I-5) ist gewollt und wird vom C-1-Test provoziert, darum ausgenommen.
if [ -f "$DEBUG_LOG" ]; then
  NEW_LINES=$(tail -n +$((DEBUG_LINES + 1)) "$DEBUG_LOG" | grep -E 'swipe-images|Swipe_Images' | grep -v 'swipe-images: Attachment' || true)
  [ -z "$NEW_LINES" ] || fail "neue Plugin-Zeilen im debug.log: $NEW_LINES"
  ok "debug.log ohne neue Plugin-Zeilen"
else
  ok "debug.log ohne neue Plugin-Zeilen (keine Datei, WP_DEBUG_LOG ist aus)"
fi

# Aufräumen
wp post delete "$ID" "$ID60" "$ID90" "$IDA" --force >/dev/null
rm -rf "$PREVIEW_DIR"
wp option update swipe_images_settings '{"enabled":true,"format":"webp","convert_png":true,"quality_webp":82,"quality_avif":65,"big_image_threshold":2560,"max_srcset_width":2560}' --format=json >/dev/null
echo "ALLE INTEGRATIONSTESTS OK"
