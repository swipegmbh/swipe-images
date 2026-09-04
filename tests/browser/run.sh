#!/usr/bin/env bash
# Browser-Test für den Abschnitt "swipe Bilder" auf Einstellungen -> Medien.
# Startet einen echten Chromium (Playwright) gegen die lokale Dev-Instanz.
set -euo pipefail

: "${SITE:=/Users/swipegmbh/Sites/swipe.wordpress-starter.local}"
: "${WP_URL:=https://swipe.wordpress-starter.local:8890}"
: "${WP_USER:=swipe}"
: "${WP_PASS:=swipe}"
: "${WP_CLI_PHP:=/Applications/MAMP/bin/php/php8.3.30/bin/php}"
: "${PLAYWRIGHT_NODE_MODULES:=$HOME/.claude/skills/playwright-skill/node_modules}"
export SITE WP_URL WP_USER WP_PASS WP_CLI_PHP
export NODE_PATH="$PLAYWRIGHT_NODE_MODULES"

HERE="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$HERE/tmp"

FIXTURE="$HERE/tmp/photo.jpg"
if [ ! -f "$FIXTURE" ]; then
  # 3000x2000-JPEG mit Rauschen, gleicher Ansatz wie tests/integration/run.sh
  "$WP_CLI_PHP" -r '
    $im = imagecreatetruecolor(3000, 2000);
    mt_srand(42);
    for ($y = 0; $y < 2000; $y += 8) { for ($x = 0; $x < 3000; $x += 8) {
      $c = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
      imagefilledrectangle($im, $x, $y, $x + 7, $y + 7, $c);
    } }
    imagejpeg($im, $argv[1], 92);' "$FIXTURE"
fi

cd "$SITE"
ORIG_THEME="$(wp theme list --status=active --field=name)"
PHOTO_IDS=()

cleanup() {
  # macOS-Bash 3.2: "${arr[@]}" bei leerem Array bricht mit set -u ab, darum die
  # unset-sichere Form.
  for id in "${PHOTO_IDS[@]+"${PHOTO_IDS[@]}"}"; do
    wp post delete "$id" --force >/dev/null 2>&1 || true
  done
  wp option update swipe_images_settings '{"quality_webp":82}' --format=json >/dev/null 2>&1 || true
  wp theme activate "$ORIG_THEME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Drei ausstehende Bilder: unter dem Legacy-Theme ist das Plugin blockiert,
# die Uploads bleiben JPEG und zählen als ausstehend.
wp theme activate datacenterthurgau >/dev/null
for _ in 1 2 3; do
  PHOTO_IDS+=("$(wp media import "$FIXTURE" --porcelain)")
done
wp theme activate "$ORIG_THEME" >/dev/null

node "$HERE/media-page.js"

echo "BROWSER-TEST OK"
