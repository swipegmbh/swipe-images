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

ORIG_THEME="$(wp theme list --status=active --field=name)"
trap 'wp theme activate "$ORIG_THEME" >/dev/null 2>&1 || true' EXIT

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

# --- TASK6 ---

# Aufräumen
wp post delete "$ID" "$ID60" "$ID90" "$IDA" --force >/dev/null
echo "ALLE INTEGRATIONSTESTS OK"
