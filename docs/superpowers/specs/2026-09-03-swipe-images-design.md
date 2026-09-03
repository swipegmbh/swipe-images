# Spec: swipe-images

Stand 2026-09-03 · Status: Design freigegeben (Giovanni, 03.09.2026), Spec zur Prüfung

## 1. Ziel

Ein WordPress-Plugin `swipe-images` ersetzt die in rund 26 Themes kopierte `functions-parts/functions-images.php`
(WebP on-the-fly, 750 bis 1600 Zeilen je Theme, in 22 Themes mit identischen Funktionsnamen). Das Plugin
liefert modernes Bildformat, Qualitätsregler, Migration des Bestands und Updates aus einem Repo. Neue Projekte
bekommen es über das Starter-Theme, bestehende Sites schrittweise per Migration.

Nutzer sind die swipe-Entwickler (Setup, Migration, CLI) und Redaktionen der Kunden (Regler, Vorschau,
Regenerieren im Backend). Erfolg heisst: jede Site liefert Bilder in einem Format und einer Qualität, die zentral
gepflegt sind, und kein Theme trägt mehr eigenen Bildcode.

## 2. Ausgangslage

Sweep über `~/Sites` am 03.09.2026, Rohdaten in `scratchpad/webp-sweep.md` der Session:

| Kennzahl | Wert |
|---|---|
| WordPress-Installationen lokal | 35 |
| mit eigenem WebP-Code im Theme | 26 |
| mit Drittsoftware (wp-smush-pro, slicr) | 4 |
| ohne beides | 14 |

Vier Generationen: Gen 0 setzt nur `jpeg_quality` auf 100. Gen 1 ist die Starter-Kette, on-the-fly über sieben
Filter mit Backtrace-Trick, Quality 80 fest. Gen 2 (happy, baulueuet) hat Quality als Option, Async über den
Action Scheduler, Fehlerliste, Admin-Seite mit Batch, WP-CLI, Aufräumen beim Löschen, Alt-Text-Vererbung für
Crop-Attachments. Gen 3 (strickhof, 31.08.2026) konvertiert beim Upload und ersetzt den Backtrace-Trick durch
`wp_calculate_image_srcset_meta`.

Drei Lehren, die das Design bestimmen:

1. Die On-the-fly-Kette ist doppelt verlustbehaftet. WordPress schreibt erst JPEG 82, das Theme rekodiert
   danach. Egloffwoerwag kompensierte mit Quality 100, danach waren 837 von 861 WebP-Dateien grösser als
   das JPEG.
2. Theme-Filter `wp_editor_set_quality`, die 100 zurückgeben, treffen auch WebP und AVIF.
3. Das Starter-Repo auf GitHub hinkt dem lokalen DCT-Theme hinterher (srcset-Fix, `max_srcset_image_width`).
   Die Bugfix-Regel im Skill hat nicht gegriffen. Zentrales Update ist die einzige Abhilfe.

Die Helfer werden direkt in Blocks aufgerufen: `swipe_responsive_image` 74-mal in 56 Dateien,
`swipe_get_webp_url` 42-mal in 24 Dateien. Sie bleiben unter gleichem Namen erhalten.

## 3. Umfang

**v1**

- Native Konvertierung beim Upload über Core-Filter, Format WebP oder AVIF, Qualität je Format
- Zwei Betriebsmodi mit Kollisionsschutz gegen Theme-Code
- Kompatibilitäts-API mit den neun direkt genutzten Helfern plus Preload-Helfer und Alt-Text-Vererbung
- Backend-Abschnitt unter Einstellungen → Medien mit Reglern, Vorschau, Status, Regenerieren
- WP-CLI: `status`, `regenerate`, `cleanup`
- Site-Health-Test
- Updates aus GitHub-Releases über den Core-Mechanismus `Update URI`
- Starter-Theme ohne Bildcode, mit Hinweis bei fehlendem Plugin; Skills nachgeführt

**Nicht in v1:** die vier statischen PHP-Sites (eigene `inc/webp.php` bleibt), `<picture>` mit zwei Formaten,
Async über Action Scheduler, Versionsmeldung an monitor.swipe, Bericht «Bytes gespart», Cloudflare-Purge.

## 4. Architektur

### Betriebsmodi

Plugins laden vor dem Theme. Deshalb entscheidet das Plugin erst bei `after_setup_theme`, Priorität 100:

| Modus | Bedingung | Verhalten |
|---|---|---|
| aktiv | `function_exists('swipe_get_webp_url')` ist false | Konvertierungsfilter, Kompat-API, Backend, CLI, Site Health |
| blockiert | Theme deklariert die Funktion | kein Filter, keine Funktionsdeklaration, roter Admin-Hinweis mit Dateipfad und Anleitung, Site-Health-Fehler, CLI-Migration verfügbar |

Die Liste der Erkennungsfunktionen ist über den Filter `swipe_images_legacy_functions` erweiterbar. Aktivierung
ist in beiden Modi erlaubt; damit lässt sich das Plugin flottenweit ausrollen, bevor eine Site umgestellt ist.
Ein Fatal ist ausgeschlossen, weil im blockierten Modus keine globale Funktion deklariert wird.

### Konvertierung

Drei Core-Filter für die Konvertierung und zwei Wächter, keine URL-Umschreibung:

| Filter | Aufgabe | Priorität |
|---|---|---|
| `image_editor_output_format` | Mapping Quelle → Zielformat | 10 |
| `wp_editor_set_quality` | Qualität aus Option für das Zielformat | 999, schlägt Theme-Filter |
| `big_image_size_threshold` | Maximale Kantenlänge aus Option | 10 |
| `max_srcset_image_width` | srcset-Kandidaten bis Option, Default 2560 | 10 |
| `wp_get_attachment_metadata` | Metadaten-Guard gegen korrupte Sizes (aus bico) | 5 |

Mapping-Regeln:

| Quelle | Ziel |
|---|---|
| `image/jpeg` | Zielformat |
| `image/png` | Zielformat, wenn Option «PNG konvertieren» aktiv, sonst unverändert |
| `image/gif`, `image/svg+xml`, `image/webp`, `image/avif` | unverändert |

WordPress 6.9 konvertiert damit Full-Size, `-scaled` und alle Sub-Sizes direkt aus dem Original in einer
Verlustgeneration und behält das Original als `original_image` in den Metadaten (verifiziert in
`wp-admin/includes/image.php`, `wp_create_image_subsizes`). Ist AVIF gewählt und `wp_image_editor_supports()`
verneint, fällt das Mapping still auf WebP zurück; der Status zeigt den Fallback.

### Komponenten

| Klasse | Aufgabe | WordPress-Abhängigkeit |
|---|---|---|
| `Swipe_Images` | Bootstrap, Loader, Modus-Entscheid, Hook-Registrierung | ja |
| `Swipe_Images_Settings` | Option lesen, Defaults, Sanitizing | gering |
| `Swipe_Images_Converter` | Mapping und Quality-Logik als reine Funktionen | keine (unit-testbar) |
| `Swipe_Images_Detector` | Theme-Kollision, Server-Fähigkeiten, fremde Quality-Filter | ja |
| `Swipe_Images_Regenerator` | Zähler, Regenerieren je Attachment, Cleanup-Scan | ja |
| `Swipe_Images_Updater` | GitHub-Release → Update-Antwort | gering (unit-testbar) |
| `Swipe_Images_Admin` | Settings-Sektion, Notices, Site Health, AJAX für Vorschau und Batch | ja |
| `Swipe_Images_CLI` | WP-CLI-Befehle | ja |
| `functions-compat.php` | die globalen Helfer, nur im aktiven Modus geladen | ja |

Eine Option `swipe_images_settings` als Array:

```php
[
    'enabled'             => true,
    'format'              => 'webp',   // webp | avif
    'convert_png'         => true,
    'quality_webp'        => 82,
    'quality_avif'        => 65,
    'big_image_threshold' => 2560,
    'max_srcset_width'    => 2560,
]
```

### Datenfluss

Upload → `wp_generate_attachment_metadata` → `wp_create_image_subsizes` → `wp_get_image_editor` fragt
`image_editor_output_format` → Editor `save()` fragt `wp_editor_set_quality` → Dateien im Zielformat,
Metadaten zeigen darauf. Frontend: `wp_get_attachment_image`, ACF-Arrays und srcset lesen die Metadaten
unverändert; kein Filter greift in die Ausgabe ein.

## 5. Kompatibilitäts-API

Nur im aktiven Modus geladen, jede Funktion in `if (!function_exists())`.

| Funktion | Semantik im Plugin |
|---|---|
| `swipe_responsive_image($image, $size, $attr, $sizes)` | unverändert aus Starter; LCP-Regel `fetchpriority=high` → `loading=eager` |
| `swipe_get_image_sizes($layout)` | Presets unverändert, erweiterbar über Filter `swipe_images_sizes_presets` |
| `swipe_get_image_srcset`, `swipe_get_image_dimensions` | unverändert |
| `swipe_preload_responsive_image($image, $size, $media, $imagesizes = null)` | aus happy, Signatur unverändert: `<link rel=preload>` mit `imagesrcset`, `imagesizes`, `media` |
| `swipe_get_webp_url($url)` | gibt `$url` zurück; `@deprecated`, URLs sind bereits im Zielformat |
| `swipe_get_webp_image($id, $size)` | `wp_get_attachment_image_url`; `@deprecated` |
| `swipe_get_webp_from_acf($array, $size)` | Array-Lookup; `@deprecated` |
| `swipe_convert_to_webp($src, $dst, $q)` | über `wp_get_image_editor`, Ziel-Mime `image/webp`; `@deprecated` |
| `swipe_should_convert_to_webp()` | gibt `false` zurück; `@deprecated` |
| `swipe_aiarc_inherit_alt_text` | Alt-Text auf Crop-Attachments von acf-image-aspect-ratio-crop vererben |

Die acht `swipe_auto_webp_*`-Filtercallbacks entfallen; kein Block ruft sie direkt.

## 6. Backend

Abschnitt «swipe Bilder» auf `options-media.php` über die Settings-API, keine eigene Seite.

| Feld | Steuerelement | Regel |
|---|---|---|
| Aktiv | Checkbox | aus = keine Filter, Kompat-API bleibt |
| Format | Radio WebP / AVIF | AVIF ausgegraut mit Hinweis, wenn der Editor es nicht kann |
| PNG konvertieren | Checkbox | Default an |
| Qualität WebP | Range 40–100 plus Zahlenfeld | Default 82 |
| Qualität AVIF | Range 30–100 plus Zahlenfeld | Default 65 |
| Maximale Bildbreite | Zahl | Default 2560, 0 = aus |
| Vorschau | Mediathek-Auswahl, drei Kacheln | Reglerwert −10 / Wert / +10, je Dateigrösse und Bild bei 1200 px Breite |
| Status | Textkasten | GD/Imagick je Format, fremde Quality-Filter mit Callback-Name, Zähler gesamt / im Zielformat / ausstehend, Modus |
| Bestand regenerieren | Knopf, Fortschrittsbalken, Fehlerliste | AJAX-Batches à 5 Attachments, Nonce, `manage_options` |

Vorschau-Dateien liegen unter `uploads/swipe-images-preview/` und werden bei jeder neuen Vorschau überschrieben.
Blockierter Modus: Admin-Hinweis auf allen Admin-Seiten für `manage_options`, mit Pfad der Theme-Datei und den
zwei Schritten (regenerieren, Datei entfernen).

## 7. Migration und CLI

| Befehl | Wirkung |
|---|---|
| `wp swipe-images status` | Modus, Format, Server-Fähigkeiten, Zähler, fremde Filter |
| `wp swipe-images regenerate [--ids=<ids>] [--delete-old] [--yes]` | erzeugt Full, scaled und Sub-Sizes neu aus `wp_get_original_image_path()`; alte Dateien bleiben liegen, ausser `--delete-old` |
| `wp swipe-images cleanup [--dry-run]` | findet `.webp`-Geschwister aus der On-the-fly-Zeit, die kein Attachment referenziert; ohne `--dry-run` löschen, mit Bestätigung |

`regenerate` läuft auch im blockierten Modus; der Output-Format-Filter wird nur für die Laufzeit des Befehls
gesetzt. Der alte Theme-Code lässt `.webp`-URLs unangetastet, darum entsteht kein Zwischenzustand mit JPEG.

Ablauf pro Site:

1. Plugin installieren und aktivieren; Site zeigt den blockierten Modus.
2. `wp swipe-images regenerate`, bei Shops nachts.
3. Im Theme `functions-parts/functions-images.php` und die `require_once`-Zeile entfernen, deployen. Modus wechselt auf aktiv.
4. `wp swipe-images status`, eine Seite im Browser auf `.webp` im srcset prüfen, Page-Cache leeren.
5. Später `cleanup` und `regenerate --delete-old`.

Sites mit wp-smush-pro (ledermann, petrecycling): dessen WebP-Modul vor Schritt 2 abschalten.

## 8. Flotten-Update

Plugin-Header `Update URI: https://github.com/swipegmbh/swipe-images`. Core-Filter `update_plugins_github.com`
liest `https://api.github.com/repos/swipegmbh/swipe-images/releases/latest` (Transient 12 h), vergleicht
`tag_name` ohne `v` mit der installierten Version und liefert das Release-Asset `swipe-images.zip` als `package`.
Das Asset entsteht durch eine GitHub-Action auf Tags `v*` per `git archive --prefix=swipe-images/`. WordPress
zeigt das Update wie jedes andere; Auto-Update pro Site über die Plugin-Liste.

Voraussetzung: öffentliches Repo. Ein privates Repo bräuchte ein Token in jeder wp-config, das ist nach dem
Bitbucket-Vorfall ausgeschlossen. Alternative bei Bedarf: JSON plus Zip auf swipe.ch.

## 9. Starter-Theme und Skills

- `functions-parts/functions-images.php` löschen, `require_once` in `functions.php` entfernen.
- In `functions.php` bei `after_setup_theme`: fehlt `swipe_responsive_image`, Admin-Hinweis «Plugin swipe-images fehlt».
- `CLAUDE.md` und `README.md`: Abschnitt Bilder auf das Plugin umstellen.
- Skill `swipe-wordpress-theme`, `references/project-setup.md` Schritt 2: `wp plugin install <Release-Zip> --activate`.
- Skill `swipe-wordpress-theme`, «Starter-Theme Bugfix-Regel»: Bildfehler gehören ins Plugin-Repo.

## 10. Tech Stack, Befehle, Struktur, Stil

**Stack:** PHP ≥ 8.1, WordPress ≥ 6.5 (AVIF im Core, Full-Size-Konvertierung seit 6.1), WPPB-Struktur,
PHPUnit 10 + Brain Monkey (nur Dev), WP-CLI.

**Befehle**

```
composer install                       # Dev-Abhängigkeiten
composer test                          # vendor/bin/phpunit --testdox
composer lint                          # find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
bash tests/integration/run.sh          # wp-cli gegen das Starter-Local, siehe Abschnitt 11
```

**Struktur**

```
swipe-images/
├── swipe-images.php                          Bootstrap, Header mit Update URI, Konstanten
├── includes/
│   ├── class-swipe-images.php                Core, Modus-Entscheid, Hooks
│   ├── class-swipe-images-loader.php         WPPB-Loader
│   ├── class-swipe-images-activator.php
│   ├── class-swipe-images-deactivator.php
│   ├── class-swipe-images-i18n.php
│   ├── class-swipe-images-settings.php
│   ├── class-swipe-images-converter.php      reine Logik
│   ├── class-swipe-images-detector.php
│   ├── class-swipe-images-regenerator.php
│   ├── class-swipe-images-updater.php
│   ├── class-swipe-images-cli.php
│   └── functions-compat.php
├── admin/
│   ├── class-swipe-images-admin.php
│   ├── partials/                             Felder, Status, Vorschau
│   ├── css/swipe-images-admin.css
│   └── js/swipe-images-admin.js              Regler, Vorschau, Batch
├── languages/
├── tests/
│   ├── unit/                                 PHPUnit + Brain Monkey
│   ├── fixtures/                             Test-JPEG und -PNG
│   └── integration/run.sh
├── docs/superpowers/specs/
├── tasks/plan.md · tasks/todo.md
├── .github/workflows/release.yml             Zip-Asset bei Tag
├── composer.json · phpunit.xml · .gitignore · README.md
```

Kein `public/`-Verzeichnis: das Plugin liefert keine Frontend-Assets.

**Stil:** WordPress Coding Standards, Tabs, `Swipe_Images_*`-Klassen, `swipe_images_`-Präfix für Optionen und
Hooks, `swipe_`-Präfix für die Kompat-Funktionen, Kommentare auf Deutsch, Schweizer Schreibweise. Beispiel:

```php
/**
 * Mappt Quell-Mimes auf das Zielformat. Reine Funktion, keine WordPress-Aufrufe.
 *
 * @param array<string,string> $mapping   Bestehendes Mapping aus dem Filter.
 * @param string               $format    'webp' oder 'avif'.
 * @param bool                 $png       PNG mitkonvertieren.
 * @param bool                 $avif_ok   Editor kann AVIF.
 * @return array<string,string>
 */
public static function output_format( array $mapping, string $format, bool $png, bool $avif_ok ): array {
	$target = ( 'avif' === $format && $avif_ok ) ? 'image/avif' : 'image/webp';

	$mapping['image/jpeg'] = $target;
	if ( $png ) {
		$mapping['image/png'] = $target;
	}

	return $mapping;
}
```

## 11. Teststrategie

**Unit (PHPUnit + Brain Monkey), `tests/unit/`:**

- Converter: Mapping je Format, PNG-Schalter, AVIF-Fallback, Quality je Mime, Theme-Filter mit 100 wird überstimmt
- Settings: Defaults, Sanitizing der Grenzen (Quality 40–100 bzw. 30–100, Breite ≥ 0)
- Detector: Modus-Entscheid aus `function_exists`, erweiterbare Liste
- Updater: Release-JSON → Update-Array, ältere Version → false, fehlendes Asset → false

**Integration, `tests/integration/run.sh`, wp-cli gegen `~/Sites/swipe.wordpress-starter.local`.** Das Skript schaltet für den aktiven Modus auf `twentytwentyfive` (kein Theme-Bildcode) und für den blockierten Modus auf das DCT-Theme, danach zurück:

- Aktiver Modus: Fixture-JPEG 3000 px hochladen, prüfen: `foo.webp`, `foo-scaled.webp`, alle Sub-Sizes `.webp`,
  `original_image` in Metadaten, `wp_get_attachment_image` mit `src` und `srcset` nur `.webp`
- Quality: Upload bei 60 und bei 90, Datei bei 60 kleiner
- Blockierter Modus gegen das DCT-Theme: kein Fatal, Hinweis vorhanden, kein Output-Format-Filter registriert
- Regenerate: zehn Alt-Attachments, danach null ausstehend, alte Dateien vorhanden; `--delete-old` entfernt sie
- `debug.log` ohne Notices aus dem Plugin

Kein Coverage-Ziel; jede Logikklasse hat mindestens einen Test, der bricht, wenn die Logik bricht.

## 12. Fehlerbehandlung

- Editor-Fehler beim Upload: WordPress fällt auf das Quellformat zurück; das Plugin loggt bei `WP_DEBUG` und zählt das Attachment als ausstehend
- Regenerieren: `WP_Error` je Attachment in der Fehlerliste (Option `swipe_images_failed`, autoload aus), Lauf geht weiter
- AVIF ohne Serverunterstützung: stiller Fallback auf WebP, Hinweis im Status
- GitHub nicht erreichbar: Transient mit `false`, Plugin-Liste zeigt kein Update, kein Fehler im Backend
- AJAX: Nonce und `manage_options`, sonst 403

## 13. Boundaries

- **Immer:** Tests vor jedem Commit, Präfixe einhalten, Optionen sanitizen und escapen, Nonces und Capabilities bei AJAX, Dateilöschungen nur mit Dry-run oder explizitem Schalter
- **Vorher fragen:** neue Composer-Abhängigkeiten ausser phpunit und brain/monkey, Repo öffentlich stellen, Änderungen an anderen Themes als dem Starter, Löschverhalten der CLI ändern
- **Nie:** Tokens oder Secrets committen, WordPress-Core anfassen, `vendor/` editieren, in diesem Projekt gegen Produktions-Sites arbeiten, Regenerate auf Prod ohne Backup

## 14. Erfolgskriterien

1. Upload eines JPEG auf einer Site ohne Theme-Bildcode erzeugt ausschliesslich Zieldateien; Original bleibt als `original_image`.
2. Regler 60 erzeugt eine kleinere Datei als Regler 90, gleiche Quelle, gleicher Sub-Size.
3. Ein Theme-Filter `wp_editor_set_quality` mit Rückgabe 100 ändert die Zieldatei nicht.
4. Auf dem DCT-Theme mit altem Code: Plugin aktiv, kein Fatal, roter Hinweis, kein Konvertierungsfilter, keine Kompat-Funktion aus dem Plugin.
5. `regenerate` bringt zehn Alt-Attachments auf null ausstehend, alte Dateien liegen, `--delete-old` entfernt sie, `cleanup --dry-run` listet Waisen.
6. AVIF gewählt ohne Serverunterstützung: Dateien `.webp`, Status nennt den Fallback.
7. Ein Release `v1.0.1` erscheint auf einer Site mit 1.0.0 in der Plugin-Liste als Update und lässt sich installieren.
8. Starter-Theme ohne Plugin zeigt den Hinweis; mit Plugin rendern Blocks über `swipe_responsive_image`.
9. `composer test` grün, `composer lint` ohne Fehler, Integrationsskript grün.

## 15. Entscheidungen

| Entscheid | Alternative | Grund |
|---|---|---|
| Nativ über Core-Filter | On-the-fly-Kette portieren; «Modern Image Formats» als Abhängigkeit | eine Verlustgeneration, kein URL-Rewriting, kein Fremdplugin auf hundert Sites |
| Aktivierung im blockierten Modus erlaubt | Aktivierung abbrechen | flottenweiter Rollout vor der Migration, Status pro Site sichtbar |
| Eine Options-Array | je Option ein Eintrag | eine `register_setting`, eine Zeile in `wp_options` |
| AVIF als Option, WebP Default | AVIF Default | Server-Fähigkeit variiert, Quality-Skala von AVIF noch unerprobt in der Flotte |
| Kein `<picture>` mit zwei Formaten | Dual-Derivate | Core erzeugt ein Format je Quelle, doppelter Speicher, WebP überall unterstützt |
| Alte Dateien beim Regenerieren behalten | löschen | Inhalte und Fremdseiten verlinken auf alte URLs; Löschen ist ein eigener Schritt |
| Update über `Update URI` und Release-Asset | plugin-update-checker | Core-Mechanismus seit 5.8, keine Bibliothek |
| Kein Action Scheduler | Async-Queue | Abhängigkeit von WooCommerce; CLI und AJAX-Batch decken die Fälle |

## 16. Offene Punkte

- AVIF-Default 65 vor dem Release an echten Fotos über die Vorschau prüfen; die Skala ist nicht mit WebP vergleichbar.
- WPML-Sites: Attachments je Sprache dupliziert, Regenerate erfasst alle; Verhalten der Medienübersetzung im ersten Migrationsfall prüfen.
- Repo öffentlich: beim Anlegen bestätigen.
- Erster Migrationskandidat: eine kleine Site ohne Shop, Vorschlag egloffwoerwag, weil dort die Quality-Debatte offen ist.
