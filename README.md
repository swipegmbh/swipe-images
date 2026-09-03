# swipe-images

WordPress-Plugin der swipe GmbH. Bilder werden beim Upload direkt aus dem Original als WebP oder AVIF
geschrieben, die Qualität stellt ein Regler unter Einstellungen → Medien. Der Bestand lässt sich per
WP-CLI oder Backend regenerieren. Updates kommen aus den GitHub-Releases dieses Repos.

## Installation

    wp plugin install https://github.com/swipegmbh/swipe-images/releases/latest/download/swipe-images.zip --activate

Voraussetzungen: WordPress 6.5, PHP 8.1, GD oder Imagick mit WebP. AVIF nur, wenn der Bild-Editor es kann
(Status auf der Medien-Seite).

## Zwei Modi

Trägt das aktive Theme noch eine `functions-images.php` mit `swipe_get_webp_url()`, bleibt das Plugin
blockiert: kein Filter, keine Funktionsdeklaration, roter Hinweis. Die Migration läuft trotzdem.

## Migration einer bestehenden Site

1. Plugin installieren und aktivieren.
2. `wp swipe-images regenerate` (bei Shops nachts).
3. Im Theme `functions-parts/functions-images.php` und die `require_once`-Zeile entfernen, deployen.
4. `wp swipe-images status`, eine Seite auf `.webp` im srcset prüfen, Page-Cache leeren.
5. Später `wp swipe-images cleanup` und `wp swipe-images regenerate --delete-old`.

## WP-CLI

    wp swipe-images status
    wp swipe-images regenerate [--ids=<ids>] [--delete-old] [--yes]
    wp swipe-images cleanup [--dry-run] [--yes]

## Helfer für Themes

`swipe_responsive_image()`, `swipe_get_image_sizes()`, `swipe_get_image_srcset()`,
`swipe_get_image_dimensions()`, `swipe_preload_responsive_image()`. Die alten `swipe_get_webp_*`-Funktionen bleiben aus Kompatibilität erhalten: `swipe_get_webp_url()` gibt
die übergebene URL unverändert zurück, `swipe_get_webp_image()` und `swipe_get_webp_from_acf()` liefern
die reguläre Attachment-URL, weil die Dateien bereits im Zielformat liegen.

## Entwicklung

    composer install
    composer test
    composer lint
    bash tests/integration/run.sh

Release: Tag `vX.Y.Z` pushen, die GitHub-Action hängt `swipe-images.zip` an das Release.

Spec: `docs/superpowers/specs/2026-09-03-swipe-images-design.md` · Plan: `docs/superpowers/plans/2026-09-03-swipe-images.md`
