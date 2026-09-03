# swipe-images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein WPPB-Plugin `swipe-images`, das Bilder beim Upload nativ als WebP oder AVIF erzeugt, einen Qualitätsregler unter Einstellungen → Medien anbietet, den Bestand per CLI und Backend regeneriert, sich aus GitHub-Releases aktualisiert und bei Themes mit altem Bildcode in einen blockierten Modus geht statt zu fatalen.

**Architecture:** Drei Core-Filter (`image_editor_output_format`, `wp_editor_set_quality`, `big_image_size_threshold`) plus `max_srcset_image_width` und ein Metadaten-Guard; keine URL-Umschreibung. Modus-Entscheid bei `after_setup_theme` Priorität 100 über `function_exists('swipe_get_webp_url')`. Reine Logik in `Swipe_Images_Converter`, `Swipe_Images_Settings` und `Swipe_Images_Updater` (unit-testbar), WordPress-Anbindung in `Swipe_Images`, `Swipe_Images_Detector`, `Swipe_Images_Regenerator`, `Swipe_Images_Admin`, `Swipe_Images_CLI`.

**Tech Stack:** PHP ≥ 8.1, WordPress ≥ 6.5, WPPB (DevinVinson), PHPUnit 9.6 + Brain Monkey 2.6 (Dev), WP-CLI 2.12, MAMP PRO lokal (PHP 8.3.30, GD mit WebP, ohne AVIF).

**Spec:** `docs/superpowers/specs/2026-09-03-swipe-images-design.md`

## Global Constraints

- Slug `swipe-images`, Klassenpräfix `Swipe_Images_`, Option `swipe_images_settings`, Hooks/Filter mit Präfix `swipe_images_`, Kompat-Funktionen mit Präfix `swipe_`
- `Requires at least: 6.5`, `Requires PHP: 8.1`, Header `Update URI: https://github.com/swipegmbh/swipe-images`
- WPPB-Struktur: `includes/`, `admin/`, Loader; kein `public/`
- Composer nur Dev: `phpunit/phpunit ^9.6`, `brain/monkey ^2.6`, `mockery/mockery ^1.6` (wie `swipe-connect-quform-hubspot`); keine weiteren Abhängigkeiten ohne Rückfrage
- Defaults: `enabled` true, `format` webp, `convert_png` true, `quality_webp` 82, `quality_avif` 65, `big_image_threshold` 2560, `max_srcset_width` 2560
- Quality-Grenzen: WebP 40–100, AVIF 30–100; Breiten ≥ 0
- Im blockierten Modus: kein Konvertierungsfilter, keine globale Funktionsdeklaration, roter Hinweis, Site-Health-Fehler, CLI und AJAX-Regenerieren setzen die Filter nur für ihre Laufzeit
- Dateilöschungen nur mit `--delete-old` bzw. nach Dry-run; AJAX nur mit Nonce und `manage_options`
- Kommentare Deutsch, Schweizer Schreibweise (ss), WordPress Coding Standards, Tabs
- Commits nach dem `commit`-Skill (Sentry-Format), Footer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` und `Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn`
- Vor jedem Commit: `composer test` grün und `composer lint` ohne Fehler
- Lokale Testumgebung: `~/Sites/swipe.wordpress-starter.local`, MAMP-PHP `/Applications/MAMP/bin/php/php8.3.30/bin/php`, MySQL-Socket `/Applications/MAMP/tmp/mysql/mysql.sock`, URL `https://swipe.wordpress-starter.local:8890` (MAMP-Konvention, Annahme)
- Nie gegen Produktions-Sites arbeiten; Starter-Theme-Änderungen nur im Klon `~/Sites/swipe-starter-theme` auf einem Branch

## Dateiübersicht

| Datei | Verantwortung |
|---|---|
| `swipe-images.php` | Plugin-Header, Konstanten, Activator/Deactivator, `run_swipe_images()` |
| `includes/class-swipe-images.php` | Core: Dependencies laden, CLI registrieren, `boot()` bei `after_setup_theme`, Modus, Hook-Registrierung, `register_conversion_filters()` |
| `includes/class-swipe-images-loader.php` | WPPB-Loader (unverändert) |
| `includes/class-swipe-images-activator.php`, `-deactivator.php`, `-i18n.php` | WPPB, minimal |
| `includes/class-swipe-images-settings.php` | Option lesen, `defaults()`, `sanitize()` (rein) |
| `includes/class-swipe-images-converter.php` | reine Statics `target_mime()`, `output_format()`, `quality()`; WP-Callbacks `filter_output_format()`, `filter_quality()`, `filter_threshold()`, `filter_max_srcset()`, `sanitize_metadata()` |
| `includes/class-swipe-images-detector.php` | `theme_has_legacy_code()`, `editor_supports()`, `capabilities()`, `foreign_quality_filters()` |
| `includes/class-swipe-images-regenerator.php` | `counts()`, `pending_ids()`, `regenerate()`, `orphan_webp_files()` |
| `includes/class-swipe-images-updater.php` | `build_update()` (rein), `check()` (WP-Callback) |
| `includes/class-swipe-images-cli.php` | `wp swipe-images status|regenerate|cleanup` |
| `includes/functions-compat.php` | die globalen Helfer, nur im aktiven Modus |
| `admin/class-swipe-images-admin.php` | Settings-Sektion, Notice, Site Health, AJAX, Enqueue |
| `admin/partials/settings-fields.php`, `settings-status.php`, `settings-preview.php`, `settings-regenerate.php` | Markup |
| `admin/css/swipe-images-admin.css`, `admin/js/swipe-images-admin.js` | Regler-Kopplung, Vorschau, Batch |
| `tests/bootstrap.php`, `tests/unit/*Test.php` | PHPUnit + Brain Monkey |
| `tests/integration/run.sh` | wp-cli-Integrationslauf, wächst mit den Tasks |
| `.github/workflows/release.yml` | Zip-Asset bei Tag `v*` |
| `composer.json`, `phpunit.xml`, `.gitignore`, `README.md` | Tooling |

---

### Task 1: WPPB-Gerüst, Tooling, erster grüner Test

**Files:**
- Create: `swipe-images.php`, `includes/class-swipe-images.php`, `includes/class-swipe-images-loader.php`, `includes/class-swipe-images-activator.php`, `includes/class-swipe-images-deactivator.php`, `includes/class-swipe-images-i18n.php`, `includes/index.php`, `admin/index.php`, `index.php`, `uninstall.php`, `languages/swipe-images.pot`
- Create: `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `tests/unit/SanityTest.php`, `.gitignore`, `README.md`

**Interfaces:**
- Produces: Konstanten `SWIPE_IMAGES_VERSION`, `SWIPE_IMAGES_FILE`, `SWIPE_IMAGES_PATH`, `SWIPE_IMAGES_BASENAME`; Klasse `Swipe_Images` mit `run()`; `Swipe_Images_Loader` mit `add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 )`, `add_filter(...)`, `run()`

- [ ] **Step 1: WPPB holen und Platzhalter ersetzen**

```bash
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local/wp-content/plugins/swipe-images
git clone -q --depth 1 https://github.com/DevinVinson/WordPress-Plugin-Boilerplate.git /tmp/wppb-src
cp -R /tmp/wppb-src/plugin-name/. .
rm -rf /tmp/wppb-src public admin/css admin/js admin/partials admin/class-plugin-name-admin.php LICENSE.txt README.txt
for f in $(find . -name '*plugin-name*' -not -path './.git/*'); do mv "$f" "${f//plugin-name/swipe-images}"; done
find . -type f \( -name '*.php' -o -name '*.pot' \) -not -path './.git/*' -not -path './docs/*' \
  -exec sed -i '' -e 's/Plugin_Name/Swipe_Images/g' -e 's/PLUGIN_NAME/SWIPE_IMAGES/g' -e 's/plugin-name/swipe-images/g' -e 's/plugin_name/swipe_images/g' {} \;
ls includes admin
```
Expected: `includes/` enthält `class-swipe-images.php`, `class-swipe-images-loader.php`, `class-swipe-images-activator.php`, `class-swipe-images-deactivator.php`, `class-swipe-images-i18n.php`, `index.php`; `admin/` nur `index.php`.

- [ ] **Step 2: Plugin-Header und Konstanten setzen**

`swipe-images.php` vollständig ersetzen:

```php
<?php
/**
 * swipe Bilder
 *
 * @package Swipe_Images
 *
 * @wordpress-plugin
 * Plugin Name:       swipe Bilder
 * Plugin URI:        https://github.com/swipegmbh/swipe-images
 * Description:       Bilder beim Upload als WebP oder AVIF, Qualitätsregler, Migration des Bestands. Ersetzt die functions-images.php der swipe-Themes.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            swipe GmbH
 * Author URI:        https://swipe.ch
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       swipe-images
 * Domain Path:       /languages
 * Update URI:        https://github.com/swipegmbh/swipe-images
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'SWIPE_IMAGES_VERSION', '1.0.0' );
define( 'SWIPE_IMAGES_FILE', __FILE__ );
define( 'SWIPE_IMAGES_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWIPE_IMAGES_BASENAME', plugin_basename( __FILE__ ) );

function activate_swipe_images() {
	require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-activator.php';
	Swipe_Images_Activator::activate();
}

function deactivate_swipe_images() {
	require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-deactivator.php';
	Swipe_Images_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_swipe_images' );
register_deactivation_hook( __FILE__, 'deactivate_swipe_images' );

require SWIPE_IMAGES_PATH . 'includes/class-swipe-images.php';

function run_swipe_images() {
	$plugin = new Swipe_Images();
	$plugin->run();
}
run_swipe_images();
```

- [ ] **Step 3: Core-Klasse auf das Nötige reduzieren**

`includes/class-swipe-images.php` vollständig ersetzen (Task 4 erweitert `boot()`):

```php
<?php
/**
 * Core-Klasse: lädt Abhängigkeiten, entscheidet den Modus, registriert Hooks.
 *
 * @package Swipe_Images
 */

class Swipe_Images {

	protected Swipe_Images_Loader $loader;
	protected string $plugin_name = 'swipe-images';
	protected string $version     = SWIPE_IMAGES_VERSION;
	protected bool $blocked       = false;

	public function __construct() {
		$this->load_dependencies();
		$this->loader = new Swipe_Images_Loader();
	}

	private function load_dependencies(): void {
		require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-loader.php';
		require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-i18n.php';
	}

	/**
	 * Registriert den Boot bei after_setup_theme, weil erst dann das Theme geladen ist.
	 */
	public function run(): void {
		add_action( 'after_setup_theme', array( $this, 'boot' ), 100 );
	}

	public function boot(): void {
		$i18n = new Swipe_Images_i18n();
		$this->loader->add_action( 'init', $i18n, 'load_plugin_textdomain' );
		$this->loader->run();
	}

	public function is_blocked(): bool {
		return $this->blocked;
	}

	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	public function get_version(): string {
		return $this->version;
	}

	public function get_loader(): Swipe_Images_Loader {
		return $this->loader;
	}
}
```

`includes/class-swipe-images-i18n.php`: WPPB-Stand behalten, nur sicherstellen, dass `load_plugin_textdomain( 'swipe-images', false, dirname( SWIPE_IMAGES_BASENAME ) . '/languages/' )` steht.

`includes/class-swipe-images-activator.php` und `-deactivator.php`: WPPB-Stand behalten (leere `activate()`/`deactivate()`).

`uninstall.php` vollständig ersetzen:

```php
<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'swipe_images_settings' );
delete_option( 'swipe_images_failed' );
delete_transient( 'swipe_images_update' );
```

- [ ] **Step 4: Composer, PHPUnit, Bootstrap, Sanity-Test**

`composer.json`:

```json
{
  "name": "swipe/swipe-images",
  "description": "WebP/AVIF beim Upload, Qualitätsregler, Migration. swipe GmbH.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": { "php": ">=8.1" },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "brain/monkey": "^2.6",
    "mockery/mockery": "^1.6"
  },
  "autoload": { "classmap": ["includes/", "admin/"] },
  "scripts": {
    "test": "phpunit --testdox",
    "lint": "find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax errors' || true"
  },
  "config": { "platform": { "php": "8.1" } }
}
```

`phpunit.xml`:

```xml
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
  <testsuites>
    <testsuite name="unit"><directory>tests/unit</directory></testsuite>
  </testsuites>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php
require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! defined( 'SWIPE_IMAGES_VERSION' ) ) {
	define( 'SWIPE_IMAGES_VERSION', '1.0.0' );
}
if ( ! defined( 'SWIPE_IMAGES_PATH' ) ) {
	define( 'SWIPE_IMAGES_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SWIPE_IMAGES_FILE' ) ) {
	define( 'SWIPE_IMAGES_FILE', SWIPE_IMAGES_PATH . 'swipe-images.php' );
}
if ( ! defined( 'SWIPE_IMAGES_BASENAME' ) ) {
	define( 'SWIPE_IMAGES_BASENAME', 'swipe-images/swipe-images.php' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

foreach ( glob( dirname( __DIR__ ) . '/includes/class-swipe-images-*.php' ) as $file ) {
	require_once $file;
}
```

`tests/unit/SanityTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class SanityTest extends TestCase {
	public function test_loader_class_exists(): void {
		$this->assertTrue( class_exists( 'Swipe_Images_Loader' ) );
	}
}
```

`.gitignore`:

```
/vendor/
/node_modules/
.DS_Store
/tests/integration/tmp/
```

`README.md` (Kurzform, wird in Task 12 ergänzt):

```markdown
# swipe-images

WordPress-Plugin der swipe GmbH: Bilder beim Upload als WebP oder AVIF, Qualitätsregler unter
Einstellungen → Medien, Migration des Bestands per WP-CLI, Updates aus GitHub-Releases.

Spec: `docs/superpowers/specs/2026-09-03-swipe-images-design.md` · Plan: `docs/superpowers/plans/2026-09-03-swipe-images.md`

## Entwicklung

    composer install
    composer test
    composer lint
    bash tests/integration/run.sh
```

- [ ] **Step 5: Test laufen lassen**

Run: `cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local/wp-content/plugins/swipe-images && composer install -q && composer test && composer lint`
Expected: `SanityTest` grün, `lint` ohne Ausgabe.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: Scaffold plugin from WPPB with test tooling" -m "Generate the WPPB skeleton, drop public/, set header with Update URI and constants, add PHPUnit and Brain Monkey as in swipe-connect-quform-hubspot." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 2: Settings mit Defaults und Sanitizing

**Files:**
- Create: `includes/class-swipe-images-settings.php`
- Test: `tests/unit/SettingsTest.php`

**Interfaces:**
- Produces: `Swipe_Images_Settings::OPTION` (`'swipe_images_settings'`), `Swipe_Images_Settings::defaults(): array`, `Swipe_Images_Settings::sanitize( $input ): array` (rein), `Swipe_Images_Settings::get(): array` (liest Option, merged Defaults), `Swipe_Images_Settings::quality_bounds( string $format ): array{min:int,max:int}`

- [ ] **Step 1: Failing Test schreiben**

`tests/unit/SettingsTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	public function test_defaults(): void {
		$d = Swipe_Images_Settings::defaults();
		$this->assertSame( true, $d['enabled'] );
		$this->assertSame( 'webp', $d['format'] );
		$this->assertSame( true, $d['convert_png'] );
		$this->assertSame( 82, $d['quality_webp'] );
		$this->assertSame( 65, $d['quality_avif'] );
		$this->assertSame( 2560, $d['big_image_threshold'] );
		$this->assertSame( 2560, $d['max_srcset_width'] );
	}

	public function test_sanitize_clamps_quality_and_rejects_unknown_format(): void {
		$s = Swipe_Images_Settings::sanitize( array(
			'format'       => 'jpeg2000',
			'quality_webp' => 5,
			'quality_avif' => 500,
			'enabled'      => '1',
			'convert_png'  => '',
		) );
		$this->assertSame( 'webp', $s['format'] );
		$this->assertSame( 40, $s['quality_webp'] );
		$this->assertSame( 100, $s['quality_avif'] );
		$this->assertTrue( $s['enabled'] );
		$this->assertFalse( $s['convert_png'] );
	}

	public function test_sanitize_fills_missing_keys_with_defaults(): void {
		$s = Swipe_Images_Settings::sanitize( array( 'format' => 'avif' ) );
		$this->assertSame( 'avif', $s['format'] );
		$this->assertSame( 82, $s['quality_webp'] );
		$this->assertSame( 2560, $s['big_image_threshold'] );
	}

	public function test_sanitize_widths_never_negative(): void {
		$s = Swipe_Images_Settings::sanitize( array( 'big_image_threshold' => -1, 'max_srcset_width' => '0' ) );
		$this->assertSame( 0, $s['big_image_threshold'] );
		$this->assertSame( 0, $s['max_srcset_width'] );
	}

	public function test_sanitize_non_array_returns_defaults(): void {
		$this->assertSame( Swipe_Images_Settings::defaults(), Swipe_Images_Settings::sanitize( 'nope' ) );
	}
}
```

- [ ] **Step 2: Test ausführen, muss fehlschlagen**

Run: `composer test`
Expected: FAIL, `Class "Swipe_Images_Settings" not found`.

- [ ] **Step 3: Implementieren**

`includes/class-swipe-images-settings.php`:

```php
<?php
/**
 * Einstellungen: eine Option als Array, Defaults, Sanitizing.
 *
 * sanitize() ist rein (keine WordPress-Aufrufe) und damit unit-testbar.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Settings {

	const OPTION = 'swipe_images_settings';

	public static function defaults(): array {
		return array(
			'enabled'             => true,
			'format'              => 'webp',
			'convert_png'         => true,
			'quality_webp'        => 82,
			'quality_avif'        => 65,
			'big_image_threshold' => 2560,
			'max_srcset_width'    => 2560,
		);
	}

	/**
	 * @return array{min:int,max:int}
	 */
	public static function quality_bounds( string $format ): array {
		return 'avif' === $format ? array( 'min' => 30, 'max' => 100 ) : array( 'min' => 40, 'max' => 100 );
	}

	/**
	 * Sanitizing für register_setting() und für get().
	 *
	 * @param mixed $input Rohwerte aus dem Formular oder der Datenbank.
	 */
	public static function sanitize( $input ): array {
		$d = self::defaults();
		if ( ! is_array( $input ) ) {
			return $d;
		}

		$out = $d;

		$out['enabled']     = array_key_exists( 'enabled', $input ) ? ! empty( $input['enabled'] ) : $d['enabled'];
		$out['convert_png'] = array_key_exists( 'convert_png', $input ) ? ! empty( $input['convert_png'] ) : $d['convert_png'];

		$format        = isset( $input['format'] ) ? (string) $input['format'] : $d['format'];
		$out['format'] = in_array( $format, array( 'webp', 'avif' ), true ) ? $format : 'webp';

		foreach ( array( 'webp', 'avif' ) as $f ) {
			$key = 'quality_' . $f;
			$b   = self::quality_bounds( $f );
			$q   = isset( $input[ $key ] ) ? (int) $input[ $key ] : $d[ $key ];
			$out[ $key ] = max( $b['min'], min( $b['max'], $q ) );
		}

		foreach ( array( 'big_image_threshold', 'max_srcset_width' ) as $key ) {
			$v           = isset( $input[ $key ] ) ? (int) $input[ $key ] : $d[ $key ];
			$out[ $key ] = max( 0, $v );
		}

		return $out;
	}

	/**
	 * Liest die Option; Defaults füllen fehlende Schlüssel.
	 */
	public static function get(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}
}
```

- [ ] **Step 4: Test grün**

Run: `composer test`
Expected: 5 Tests in `SettingsTest` grün.

- [ ] **Step 5: Commit**

```bash
git add includes/class-swipe-images-settings.php tests/unit/SettingsTest.php
git commit -m "feat(settings): Add option defaults and sanitizing" -m "One option array swipe_images_settings; sanitize() clamps quality to the per-format bounds and falls back to webp for unknown formats." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 3: Converter, reine Logik

**Files:**
- Create: `includes/class-swipe-images-converter.php`
- Test: `tests/unit/ConverterTest.php`

**Interfaces:**
- Consumes: `Swipe_Images_Settings::get()`
- Produces: `Swipe_Images_Converter::target_mime( string $format, bool $avif_ok ): string`, `::output_format( array $mapping, string $format, bool $png, bool $avif_ok ): array`, `::quality( int $default, string $mime, array $settings ): int`; Instanz-Callbacks `filter_output_format( $mapping, $filename, $mime )`, `filter_quality( $quality, $mime )`, `filter_threshold( $threshold )`, `filter_max_srcset( $max )`, `sanitize_metadata( $data, $attachment_id )`. Der Konstruktor nimmt `array $settings` und `bool $avif_ok`.

- [ ] **Step 1: Failing Test schreiben**

`tests/unit/ConverterTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class ConverterTest extends TestCase {

	public function test_target_mime_avif_only_when_supported(): void {
		$this->assertSame( 'image/webp', Swipe_Images_Converter::target_mime( 'webp', true ) );
		$this->assertSame( 'image/avif', Swipe_Images_Converter::target_mime( 'avif', true ) );
		$this->assertSame( 'image/webp', Swipe_Images_Converter::target_mime( 'avif', false ) );
	}

	public function test_output_format_maps_jpeg_and_optionally_png(): void {
		$m = Swipe_Images_Converter::output_format( array(), 'webp', true, false );
		$this->assertSame( 'image/webp', $m['image/jpeg'] );
		$this->assertSame( 'image/webp', $m['image/png'] );

		$m = Swipe_Images_Converter::output_format( array(), 'webp', false, false );
		$this->assertArrayNotHasKey( 'image/png', $m );
	}

	public function test_output_format_keeps_existing_mappings_and_never_touches_gif(): void {
		$m = Swipe_Images_Converter::output_format( array( 'image/heic' => 'image/jpeg' ), 'webp', true, false );
		$this->assertSame( 'image/jpeg', $m['image/heic'] );
		$this->assertArrayNotHasKey( 'image/gif', $m );
	}

	public function test_quality_uses_setting_for_target_mimes_only(): void {
		$s = array( 'quality_webp' => 70, 'quality_avif' => 50 );
		$this->assertSame( 70, Swipe_Images_Converter::quality( 100, 'image/webp', $s ) );
		$this->assertSame( 50, Swipe_Images_Converter::quality( 100, 'image/avif', $s ) );
		$this->assertSame( 82, Swipe_Images_Converter::quality( 82, 'image/jpeg', $s ) );
	}

	public function test_sanitize_metadata_drops_sizes_without_numeric_dimensions(): void {
		$c    = new Swipe_Images_Converter( Swipe_Images_Settings::defaults(), false );
		$meta = array(
			'sizes' => array(
				'ok'     => array( 'file' => 'a.webp', 'width' => 10, 'height' => 5 ),
				'kaputt' => array( 'file' => 'b.webp' ),
				'string' => 'nope',
			),
		);
		$out = $c->sanitize_metadata( $meta, 1 );
		$this->assertSame( array( 'ok' ), array_keys( $out['sizes'] ) );
		$this->assertFalse( $c->sanitize_metadata( false, 1 ) );
	}
}
```

- [ ] **Step 2: Test ausführen, muss fehlschlagen**

Run: `composer test`
Expected: FAIL, `Class "Swipe_Images_Converter" not found`.

- [ ] **Step 3: Implementieren**

`includes/class-swipe-images-converter.php`:

```php
<?php
/**
 * Konvertierungslogik.
 *
 * Statics sind rein und unit-testbar; die filter_*-Methoden sind die WordPress-Callbacks.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Converter {

	private array $settings;
	private bool $avif_ok;

	public function __construct( array $settings, bool $avif_ok ) {
		$this->settings = $settings;
		$this->avif_ok  = $avif_ok;
	}

	/** Zielformat als Mime; AVIF nur, wenn der Editor es kann. */
	public static function target_mime( string $format, bool $avif_ok ): string {
		return ( 'avif' === $format && $avif_ok ) ? 'image/avif' : 'image/webp';
	}

	/** Mapping Quelle → Ziel für image_editor_output_format. GIF, SVG, WebP, AVIF bleiben unberührt. */
	public static function output_format( array $mapping, string $format, bool $png, bool $avif_ok ): array {
		$target                = self::target_mime( $format, $avif_ok );
		$mapping['image/jpeg'] = $target;
		if ( $png ) {
			$mapping['image/png'] = $target;
		}
		return $mapping;
	}

	/** Qualität aus den Einstellungen, nur für WebP und AVIF; alles andere bleibt beim Default. */
	public static function quality( int $default, string $mime, array $settings ): int {
		if ( 'image/webp' === $mime && isset( $settings['quality_webp'] ) ) {
			return (int) $settings['quality_webp'];
		}
		if ( 'image/avif' === $mime && isset( $settings['quality_avif'] ) ) {
			return (int) $settings['quality_avif'];
		}
		return $default;
	}

	// ---- WordPress-Callbacks -------------------------------------------------

	public function filter_output_format( $mapping, $filename = '', $mime = '' ) {
		return self::output_format( (array) $mapping, $this->settings['format'], (bool) $this->settings['convert_png'], $this->avif_ok );
	}

	public function filter_quality( $quality, $mime = '' ) {
		return self::quality( (int) $quality, (string) $mime, $this->settings );
	}

	public function filter_threshold( $threshold ) {
		$t = (int) $this->settings['big_image_threshold'];
		return $t > 0 ? $t : false;
	}

	public function filter_max_srcset( $max ) {
		$m = (int) $this->settings['max_srcset_width'];
		return $m > 0 ? max( (int) $max, $m ) : $max;
	}

	/**
	 * Metadaten-Guard aus bico: Sizes ohne numerische Breite/Höhe entfernen,
	 * sonst wirft wp_calculate_image_srcset Notices oder liefert leere srcsets.
	 */
	public function sanitize_metadata( $data, $attachment_id ) {
		if ( ! is_array( $data ) || empty( $data['sizes'] ) || ! is_array( $data['sizes'] ) ) {
			return $data;
		}
		foreach ( $data['sizes'] as $name => $size ) {
			if ( ! is_array( $size ) || ! isset( $size['width'], $size['height'] ) || ! is_numeric( $size['width'] ) || ! is_numeric( $size['height'] ) ) {
				unset( $data['sizes'][ $name ] );
			}
		}
		return $data;
	}
}
```

- [ ] **Step 4: Test grün**

Run: `composer test`
Expected: `ConverterTest` 5 Tests grün, gesamt 11.

- [ ] **Step 5: Commit**

```bash
git add includes/class-swipe-images-converter.php tests/unit/ConverterTest.php
git commit -m "feat(converter): Add output-format mapping and quality logic" -m "Pure statics for the mapping, the AVIF fallback and the per-format quality, plus the WordPress callbacks and the metadata guard taken from the bico theme." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 4: Detector, Modus-Boot, Konvertierung live, Integrationsumgebung

**Files:**
- Create: `includes/class-swipe-images-detector.php`, `tests/integration/run.sh`
- Modify: `includes/class-swipe-images.php` (Task 1, ganze Datei)
- Test: `tests/unit/DetectorTest.php`

**Interfaces:**
- Consumes: `Swipe_Images_Settings::get()`, `Swipe_Images_Converter` (Konstruktor `array $settings, bool $avif_ok`, Callbacks aus Task 3)
- Produces: `Swipe_Images_Detector::legacy_functions(): array`, `::theme_has_legacy_code( ?callable $exists = null ): bool`, `::legacy_file(): string`, `::editor_supports( string $mime ): bool`, `::capabilities(): array`, `::describe_callbacks( array $callbacks, string $own_class ): array` (rein), `::foreign_quality_filters(): array`; `Swipe_Images::is_blocked(): bool` (static), `Swipe_Images::register_conversion_filters(): bool` (static, idempotent, false wenn `enabled` aus)

- [ ] **Step 1: Failing Test schreiben**

`tests/unit/DetectorTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class DetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_legacy_detection_uses_injected_checker(): void {
		$this->assertTrue( Swipe_Images_Detector::theme_has_legacy_code( fn( $fn ) => 'swipe_get_webp_url' === $fn ) );
		$this->assertFalse( Swipe_Images_Detector::theme_has_legacy_code( fn( $fn ) => false ) );
	}

	public function test_describe_callbacks_skips_own_class_and_names_the_rest(): void {
		$own = new Swipe_Images_Converter( Swipe_Images_Settings::defaults(), false );
		$callbacks = array(
			10  => array(
				'a' => array( 'function' => 'theme_quality_100', 'accepted_args' => 1 ),
				'b' => array( 'function' => function () { return 100; }, 'accepted_args' => 1 ),
			),
			999 => array(
				'c' => array( 'function' => array( $own, 'filter_quality' ), 'accepted_args' => 2 ),
			),
		);
		$out = Swipe_Images_Detector::describe_callbacks( $callbacks, 'Swipe_Images_Converter' );
		$this->assertSame( array( 'theme_quality_100 (Priorität 10)', 'Closure (Priorität 10)' ), $out );
	}
}
```

- [ ] **Step 2: Test ausführen, muss fehlschlagen**

Run: `composer test`
Expected: FAIL, `Class "Swipe_Images_Detector" not found`.

- [ ] **Step 3: Detector implementieren**

`includes/class-swipe-images-detector.php`:

```php
<?php
/**
 * Erkennung: Theme mit altem Bildcode, Server-Fähigkeiten, fremde Quality-Filter.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Detector {

	/** Funktionsnamen, die ein Theme mit eigenem Bildcode deklariert. Erweiterbar per Filter. */
	public static function legacy_functions(): array {
		return (array) apply_filters( 'swipe_images_legacy_functions', array( 'swipe_get_webp_url' ) );
	}

	/**
	 * @param callable|null $exists Prüffunktion, Default function_exists; injizierbar für Tests.
	 */
	public static function theme_has_legacy_code( ?callable $exists = null ): bool {
		$exists = $exists ?? 'function_exists';
		foreach ( self::legacy_functions() as $fn ) {
			if ( $exists( $fn ) ) {
				return true;
			}
		}
		return false;
	}

	/** Pfad der Theme-Datei mit altem Code, leer wenn keine der bekannten Varianten existiert. */
	public static function legacy_file(): string {
		$dir = get_stylesheet_directory();
		foreach ( array( '/functions-parts/functions-images.php', '/function-parts/functions-images.php', '/functions-images.php' ) as $rel ) {
			if ( file_exists( $dir . $rel ) ) {
				return $dir . $rel;
			}
		}
		return '';
	}

	public static function editor_supports( string $mime ): bool {
		static $cache = array();
		if ( ! isset( $cache[ $mime ] ) ) {
			$cache[ $mime ] = (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
		}
		return $cache[ $mime ];
	}

	/** @return array{gd:array{webp:bool,avif:bool},imagick:array{webp:bool,avif:bool},editor:array{webp:bool,avif:bool}} */
	public static function capabilities(): array {
		$imagick = array( 'webp' => false, 'avif' => false );
		if ( class_exists( 'Imagick' ) ) {
			$formats = array_map( 'strtoupper', (array) Imagick::queryFormats() );
			$imagick = array( 'webp' => in_array( 'WEBP', $formats, true ), 'avif' => in_array( 'AVIF', $formats, true ) );
		}
		return array(
			'gd'      => array( 'webp' => function_exists( 'imagewebp' ), 'avif' => function_exists( 'imageavif' ) ),
			'imagick' => $imagick,
			'editor'  => array( 'webp' => self::editor_supports( 'image/webp' ), 'avif' => self::editor_supports( 'image/avif' ) ),
		);
	}

	/**
	 * Beschreibt Hook-Callbacks lesbar und lässt die eigenen aus. Rein, testbar.
	 *
	 * @param array  $callbacks Struktur wie $wp_filter[ $hook ]->callbacks (Priorität => Liste).
	 * @param string $own_class Klassenname, dessen Methoden übersprungen werden.
	 */
	public static function describe_callbacks( array $callbacks, string $own_class ): array {
		$out = array();
		foreach ( $callbacks as $priority => $list ) {
			foreach ( (array) $list as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[0] ) && ( $fn[0] instanceof $own_class || $fn[0] === $own_class ) ) {
					continue;
				}
				if ( is_string( $fn ) ) {
					$name = $fn;
				} elseif ( is_array( $fn ) ) {
					$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . $fn[1];
				} else {
					$name = 'Closure';
				}
				$out[] = sprintf( '%s (Priorität %d)', $name, $priority );
			}
		}
		return $out;
	}

	/** Fremde Callbacks auf wp_editor_set_quality und jpeg_quality, je Hook. */
	public static function foreign_quality_filters(): array {
		global $wp_filter;
		$result = array();
		foreach ( array( 'wp_editor_set_quality', 'jpeg_quality' ) as $hook ) {
			$callbacks = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
			$found     = self::describe_callbacks( (array) $callbacks, 'Swipe_Images_Converter' );
			if ( $found ) {
				$result[ $hook ] = $found;
			}
		}
		return $result;
	}
}
```

- [ ] **Step 4: Unit-Test grün**

Run: `composer test`
Expected: `DetectorTest` 2 Tests grün, gesamt 13.

- [ ] **Step 5: Core-Klasse mit Modus und Filtern**

`includes/class-swipe-images.php` vollständig ersetzen:

```php
<?php
/**
 * Core-Klasse: lädt Abhängigkeiten, entscheidet den Modus, registriert Hooks.
 *
 * Plugins laden vor dem Theme. Deshalb fällt der Modus-Entscheid erst bei
 * after_setup_theme (Priorität 100): dann ist functions.php des Themes durch.
 *
 * @package Swipe_Images
 */

class Swipe_Images {

	protected Swipe_Images_Loader $loader;
	protected string $plugin_name = 'swipe-images';
	protected string $version     = SWIPE_IMAGES_VERSION;
	protected static bool $blocked = false;

	public function __construct() {
		$this->load_dependencies();
		$this->loader = new Swipe_Images_Loader();
	}

	private function load_dependencies(): void {
		foreach ( array( 'loader', 'i18n', 'settings', 'converter', 'detector' ) as $part ) {
			require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-' . $part . '.php';
		}
	}

	public function run(): void {
		add_action( 'after_setup_theme', array( $this, 'boot' ), 100 );
	}

	public function boot(): void {
		self::$blocked = Swipe_Images_Detector::theme_has_legacy_code();

		$i18n = new Swipe_Images_i18n();
		$this->loader->add_action( 'init', $i18n, 'load_plugin_textdomain' );

		if ( ! self::$blocked ) {
			self::register_conversion_filters();
		}

		$this->loader->run();
	}

	/**
	 * Setzt die Konvertierungsfilter. Idempotent je Request; CLI und AJAX rufen das
	 * auch im blockierten Modus für die Dauer eines Regenerate-Laufs.
	 *
	 * @return bool false, wenn das Plugin in den Einstellungen deaktiviert ist.
	 */
	public static function register_conversion_filters(): bool {
		static $done = false;
		if ( $done ) {
			return true;
		}
		$settings = Swipe_Images_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}
		$converter = new Swipe_Images_Converter( $settings, Swipe_Images_Detector::editor_supports( 'image/avif' ) );
		add_filter( 'image_editor_output_format', array( $converter, 'filter_output_format' ), 10, 3 );
		add_filter( 'wp_editor_set_quality', array( $converter, 'filter_quality' ), 999, 2 );
		add_filter( 'big_image_size_threshold', array( $converter, 'filter_threshold' ), 10, 1 );
		add_filter( 'max_srcset_image_width', array( $converter, 'filter_max_srcset' ), 10, 1 );
		add_filter( 'wp_get_attachment_metadata', array( $converter, 'sanitize_metadata' ), 5, 2 );
		$done = true;
		return true;
	}

	public static function is_blocked(): bool {
		return self::$blocked;
	}

	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	public function get_version(): string {
		return $this->version;
	}

	public function get_loader(): Swipe_Images_Loader {
		return $this->loader;
	}
}
```

- [ ] **Step 6: Lokale Site installieren**

Das Starter-Local hat eine leere Datenbank und in `wp-config.php` einen falschen `DB_HOST`. Einmalig:

```bash
export WP_CLI_PHP=/Applications/MAMP/bin/php/php8.3.30/bin/php
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local
wp config set DB_HOST 'localhost:/Applications/MAMP/tmp/mysql/mysql.sock' --type=constant
wp core is-installed || wp core install --url=https://swipe.wordpress-starter.local:8890 --title="swipe Starter" \
  --admin_user=swipe --admin_password=swipe --admin_email=info@swipe.ch --skip-email
wp theme activate twentytwentyfive
wp plugin activate swipe-images
wp eval 'echo Swipe_Images::is_blocked() ? "blockiert" : "aktiv", "\n";'
```
Expected: letzte Zeile `aktiv`.

- [ ] **Step 7: Integrationsskript anlegen**

`tests/integration/run.sh` (wird in Task 5 und 6 erweitert; die Marker `# --- TASK5 ---` und `# --- TASK6 ---` bleiben stehen):

```bash
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
```

- [ ] **Step 8: Integrationslauf**

Run: `chmod +x tests/integration/run.sh && bash tests/integration/run.sh`
Expected: drei `OK:`-Zeilen und `ALLE INTEGRATIONSTESTS OK`.

- [ ] **Step 9: debug.log prüfen**

Run: `tail -20 /Users/swipegmbh/Sites/swipe.wordpress-starter.local/wp-content/debug.log 2>/dev/null | grep -i swipe-images || echo "keine Plugin-Notices"`
Expected: `keine Plugin-Notices`.

- [ ] **Step 10: Commit**

```bash
git add includes/class-swipe-images-detector.php includes/class-swipe-images.php tests/unit/DetectorTest.php tests/integration/run.sh
git commit -m "feat(core): Boot with mode detection and native conversion filters" -m "Decide at after_setup_theme whether the theme still ships image functions. In active mode register image_editor_output_format, wp_editor_set_quality (priority 999), big_image_size_threshold, max_srcset_image_width and the metadata guard. Add the wp-cli integration run." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 5: Kompat-API, blockierter Modus mit Hinweis und Site Health

**Files:**
- Create: `includes/functions-compat.php`, `admin/class-swipe-images-admin.php`
- Modify: `includes/class-swipe-images.php` (`load_dependencies()`, `boot()`), `tests/integration/run.sh` (Block `# --- TASK5 ---`)

**Interfaces:**
- Consumes: `Swipe_Images::is_blocked()`, `Swipe_Images_Detector::legacy_file()`, `::capabilities()`, `Swipe_Images_Settings::get()`
- Produces: globale Funktionen `swipe_responsive_image`, `swipe_get_image_srcset`, `swipe_get_image_dimensions`, `swipe_get_image_sizes`, `swipe_preload_responsive_image`, `swipe_get_webp_url`, `swipe_get_webp_image`, `swipe_get_webp_from_acf`, `swipe_convert_to_webp`, `swipe_should_convert_to_webp`, `swipe_aiarc_inherit_alt_text`; Klasse `Swipe_Images_Admin` mit `__construct( string $plugin_name, string $version )`, `notice_blocked(): void`, `site_health_tests( array $tests ): array`, `site_health_test(): array`

- [ ] **Step 1: Kompat-Datei anlegen**

`includes/functions-compat.php`. Vier Funktionen werden **wörtlich** aus
`/Users/swipegmbh/Sites/swipe.wordpress-starter.local/wp-content/themes/datacenterthurgau/functions-parts/functions-images.php`
übernommen, jede in einen `if ( ! function_exists( '…' ) )`-Block gehüllt: `swipe_responsive_image`,
`swipe_get_image_srcset`, `swipe_get_image_dimensions`, `swipe_get_image_sizes`. In `swipe_get_image_sizes`
wird die letzte Zeile zu:

```php
	$sizes_map = apply_filters( 'swipe_images_sizes_presets', $sizes_map );
	return $sizes_map[ $layout ] ?? $sizes_map['full'];
```

Aus `/Users/swipegmbh/Sites/happy.local/wp-content/themes/happy/functions-parts/functions-images.php` werden
`swipe_preload_responsive_image` (Zeile `$src = swipe_get_webp_url($src);` entfernen) und
`swipe_aiarc_inherit_alt_text` samt der zwei `add_action`-Zeilen übernommen, ebenfalls in `function_exists`-Blöcken.

Dazu kommen die dünnen Deprecated-Helfer, Kopf der Datei:

```php
<?php
/**
 * Kompatibilitäts-API: die Helfer, die swipe-Blocks direkt aufrufen.
 *
 * Wird nur im aktiven Modus geladen. Jede Funktion steht in function_exists(),
 * damit ein Theme mit Restcode nie zum Fatal führt.
 *
 * @package Swipe_Images
 */

if ( ! function_exists( 'swipe_get_webp_url' ) ) {
	/**
	 * @deprecated 1.0.0 URLs liegen bereits im Zielformat vor; gibt die URL unverändert zurück.
	 */
	function swipe_get_webp_url( $image_url, $quality = null ) {
		return $image_url;
	}
}

if ( ! function_exists( 'swipe_get_webp_image' ) ) {
	/** @deprecated 1.0.0 Nutze wp_get_attachment_image_url(). */
	function swipe_get_webp_image( $image_id, $size = 'full', $quality = null ) {
		$url = wp_get_attachment_image_url( (int) $image_id, $size );
		return $url ? $url : '';
	}
}

if ( ! function_exists( 'swipe_get_webp_from_acf' ) ) {
	/** @deprecated 1.0.0 Liest die URL aus dem ACF-Array. */
	function swipe_get_webp_from_acf( $image_array, $size = 'full', $quality = null ) {
		if ( ! is_array( $image_array ) ) {
			return '';
		}
		if ( 'full' !== $size && ! empty( $image_array['sizes'][ $size ] ) ) {
			return $image_array['sizes'][ $size ];
		}
		return $image_array['url'] ?? '';
	}
}

if ( ! function_exists( 'swipe_convert_to_webp' ) ) {
	/**
	 * @deprecated 1.0.0 Konvertiert eine Datei über den WordPress-Editor nach WebP.
	 */
	function swipe_convert_to_webp( $source_path, $destination_path, $quality = null ) {
		if ( ! file_exists( $source_path ) ) {
			return false;
		}
		$editor = wp_get_image_editor( $source_path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		if ( null !== $quality ) {
			$editor->set_quality( (int) $quality );
		}
		$saved = $editor->save( $destination_path, 'image/webp' );
		return ! is_wp_error( $saved );
	}
}

if ( ! function_exists( 'swipe_should_convert_to_webp' ) ) {
	/** @deprecated 1.0.0 Es gibt keine Laufzeit-Konvertierung mehr. */
	function swipe_should_convert_to_webp() {
		return false;
	}
}
```

- [ ] **Step 2: Admin-Klasse mit Hinweis und Site Health**

`admin/class-swipe-images-admin.php` (Task 7 und 8 erweitern die Klasse):

```php
<?php
/**
 * Backend: Hinweis im blockierten Modus, Site Health, später Settings und AJAX.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Admin {

	private string $plugin_name;
	private string $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/** Roter Hinweis auf allen Admin-Seiten, solange das Theme eigenen Bildcode trägt. */
	public function notice_blocked(): void {
		if ( ! Swipe_Images::is_blocked() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$file = Swipe_Images_Detector::legacy_file();
		$file = $file ? str_replace( WP_CONTENT_DIR, 'wp-content', $file ) : 'functions-parts/functions-images.php im Theme';
		printf(
			'<div class="notice notice-error swipe-images-notice"><p><strong>swipe Bilder ist inaktiv.</strong> Das Theme bringt eigene Bildfunktionen mit (<code>%s</code>). '
			. 'So geht die Umstellung: 1. <code>wp swipe-images regenerate</code> ausführen. 2. Die Datei und ihre <code>require_once</code>-Zeile aus dem Theme entfernen und deployen. '
			. 'Danach übernimmt das Plugin.</p></div>',
			esc_html( $file )
		);
	}

	public function site_health_tests( array $tests ): array {
		$tests['direct']['swipe_images'] = array(
			'label' => 'swipe Bilder',
			'test'  => array( $this, 'site_health_test' ),
		);
		return $tests;
	}

	public function site_health_test(): array {
		$base = array(
			'label'       => 'swipe Bilder: Bildformat',
			'badge'       => array( 'label' => 'Performance', 'color' => 'blue' ),
			'test'        => 'swipe_images',
			'actions'     => '',
			'description' => '',
			'status'      => 'good',
		);

		if ( Swipe_Images::is_blocked() ) {
			$base['status']      = 'critical';
			$base['label']       = 'swipe Bilder ist blockiert: Theme trägt eigenen Bildcode';
			$base['description'] = '<p>Die Datei functions-images.php im Theme muss entfernt werden, sonst bleibt das Plugin inaktiv.</p>';
			return $base;
		}

		$settings = Swipe_Images_Settings::get();
		$caps     = Swipe_Images_Detector::capabilities();
		$format   = $settings['format'];
		if ( empty( $settings['enabled'] ) ) {
			$base['status']      = 'recommended';
			$base['label']       = 'swipe Bilder ist deaktiviert';
			$base['description'] = '<p>Neue Uploads bleiben JPEG/PNG.</p>';
			return $base;
		}
		if ( 'avif' === $format && empty( $caps['editor']['avif'] ) ) {
			$base['status']      = 'recommended';
			$base['label']       = 'AVIF gewählt, Server liefert WebP';
			$base['description'] = '<p>Weder GD noch Imagick können auf diesem Server AVIF schreiben. Das Plugin fällt auf WebP zurück.</p>';
			return $base;
		}
		$base['label']       = sprintf( 'swipe Bilder erzeugt %s (Qualität %d)', strtoupper( $format ), $settings[ 'quality_' . $format ] );
		$base['description'] = '<p>Neue Uploads werden direkt aus dem Original in das Zielformat geschrieben.</p>';
		return $base;
	}
}
```

- [ ] **Step 3: Core einbinden**

In `includes/class-swipe-images.php`:

`load_dependencies()` bekommt nach der Schleife:

```php
		require_once SWIPE_IMAGES_PATH . 'admin/class-swipe-images-admin.php';
```

`boot()` wird zu:

```php
	public function boot(): void {
		self::$blocked = Swipe_Images_Detector::theme_has_legacy_code();

		$i18n = new Swipe_Images_i18n();
		$this->loader->add_action( 'init', $i18n, 'load_plugin_textdomain' );

		if ( ! self::$blocked ) {
			self::register_conversion_filters();
			require_once SWIPE_IMAGES_PATH . 'includes/functions-compat.php';
		}

		$admin = new Swipe_Images_Admin( $this->plugin_name, $this->version );
		$this->loader->add_action( 'admin_notices', $admin, 'notice_blocked' );
		$this->loader->add_filter( 'site_status_tests', $admin, 'site_health_tests' );

		$this->loader->run();
	}
```

- [ ] **Step 4: Integrationstest für beide Modi**

In `tests/integration/run.sh` den Marker `# --- TASK5 ---` ersetzen durch:

```bash
# --- TASK5 ---
# 4) Aktiver Modus liefert die Kompat-API
[ "$(wp eval 'echo (int) function_exists("swipe_responsive_image");')" = "1" ] || fail "swipe_responsive_image fehlt im aktiven Modus"
wp eval "\$html = swipe_responsive_image($ID, 'large', array('class' => 'x'), '100vw'); if (strpos(\$html, 'srcset=') === false || strpos(\$html, '.webp') === false) { echo \$html; exit(1); }" || fail "swipe_responsive_image ohne srcset/webp"
[ "$(wp eval 'echo swipe_get_webp_url("https://x.test/a.jpg");')" = "https://x.test/a.jpg" ] || fail "swipe_get_webp_url verändert die URL"
ok "Kompat-API im aktiven Modus"

# 4b) Ein Theme-Filter mit Rückgabe 100 wird überstimmt (Priorität 999)
MU="$SITE/wp-content/mu-plugins"; mkdir -p "$MU"
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
```

- [ ] **Step 5: Lauf**

Run: `composer test && composer lint && bash tests/integration/run.sh`
Expected: Unit grün, sechs `OK:`-Zeilen, `ALLE INTEGRATIONSTESTS OK`.

- [ ] **Step 6: Commit**

```bash
git add includes/functions-compat.php admin/class-swipe-images-admin.php includes/class-swipe-images.php tests/integration/run.sh
git commit -m "feat: Add compat helpers, blocked-mode notice and Site Health test" -m "Ship the nine helpers blocks call directly plus the preload helper and alt-text inheritance, loaded only when the theme has no image code. In blocked mode show an admin notice with the migration steps and flag Site Health as critical." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 6: Regenerator und WP-CLI

**Files:**
- Create: `includes/class-swipe-images-regenerator.php`, `includes/class-swipe-images-cli.php`
- Modify: `includes/class-swipe-images.php` (`load_dependencies()`, `run()`), `tests/integration/run.sh` (Block `# --- TASK6 ---`)
- Test: `tests/unit/RegeneratorTest.php`

**Interfaces:**
- Consumes: `Swipe_Images::register_conversion_filters()`, `Swipe_Images::is_blocked()`, `Swipe_Images_Settings::get()`, `Swipe_Images_Detector::capabilities()`, `::foreign_quality_filters()`
- Produces: `Swipe_Images_Regenerator::counts(): array{total:int,converted:int,pending:int}`, `::pending_ids( int $limit = 0 ): int[]`, `::regenerate( int $attachment_id, bool $delete_old = false ): true|WP_Error`, `::files_from_meta( array $meta, string $basedir ): string[]` (rein), `::is_target_file( string $path ): bool` (rein), `::orphan_webp_files(): string[]`, `::mark_failed( int $id, string $msg ): void`, `::failed(): array`, `::clear_failed(): void`; CLI `wp swipe-images status|regenerate|cleanup`

- [ ] **Step 1: Failing Test schreiben**

`tests/unit/RegeneratorTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class RegeneratorTest extends TestCase {

	public function test_files_from_meta_resolves_full_sizes_and_original(): void {
		$meta  = array(
			'file'           => '2026/09/photo-scaled.webp',
			'original_image' => 'photo.jpg',
			'sizes'          => array(
				'large'  => array( 'file' => 'photo-1024x683.webp' ),
				'kaputt' => array(),
			),
		);
		$files = Swipe_Images_Regenerator::files_from_meta( $meta, '/up' );
		$this->assertSame(
			array( '/up/2026/09/photo-scaled.webp', '/up/2026/09/photo.jpg', '/up/2026/09/photo-1024x683.webp' ),
			$files
		);
	}

	public function test_files_from_meta_without_file_is_empty(): void {
		$this->assertSame( array(), Swipe_Images_Regenerator::files_from_meta( array(), '/up' ) );
	}

	public function test_is_target_file(): void {
		$this->assertTrue( Swipe_Images_Regenerator::is_target_file( 'a/b.webp' ) );
		$this->assertTrue( Swipe_Images_Regenerator::is_target_file( 'a/b.AVIF' ) );
		$this->assertFalse( Swipe_Images_Regenerator::is_target_file( 'a/b.jpg' ) );
	}
}
```

- [ ] **Step 2: Test ausführen, muss fehlschlagen**

Run: `composer test`
Expected: FAIL, `Class "Swipe_Images_Regenerator" not found`.

- [ ] **Step 3: Regenerator implementieren**

`includes/class-swipe-images-regenerator.php`:

```php
<?php
/**
 * Bestand: zählen, regenerieren, Waisen aus der On-the-fly-Zeit finden.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Regenerator {

	const FAILED_OPTION = 'swipe_images_failed';
	const IMAGE_MIMES   = "'image/jpeg','image/png','image/webp','image/avif'";

	public static function is_target_file( string $path ): bool {
		return (bool) preg_match( '/\.(webp|avif)$/i', $path );
	}

	/** @return array{total:int,converted:int,pending:int} */
	public static function counts(): array {
		global $wpdb;
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . self::IMAGE_MIMES . ')' );
		$converted = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type IN (" . self::IMAGE_MIMES . ")
			 AND (m.meta_value LIKE '%.webp' OR m.meta_value LIKE '%.avif')"
		);
		return array( 'total' => $total, 'converted' => $converted, 'pending' => max( 0, $total - $converted ) );
	}

	/** IDs der Attachments, deren Datei noch nicht im Zielformat liegt. 0 = alle. */
	public static function pending_ids( int $limit = 0 ): array {
		global $wpdb;
		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN (" . self::IMAGE_MIMES . ")
			AND m.meta_value NOT LIKE '%.webp' AND m.meta_value NOT LIKE '%.avif'
			ORDER BY p.ID ASC";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Absolute Pfade aller Dateien, die ein Metadaten-Array referenziert. Rein.
	 *
	 * @param string $basedir Upload-Basisverzeichnis ohne Slash am Ende.
	 */
	public static function files_from_meta( array $meta, string $basedir ): array {
		if ( empty( $meta['file'] ) ) {
			return array();
		}
		$full  = $basedir . '/' . ltrim( $meta['file'], '/' );
		$dir   = dirname( $full );
		$files = array( $full );
		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . '/' . $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . '/' . $size['file'];
			}
		}
		return $files;
	}

	/**
	 * Erzeugt Full, scaled und Sub-Sizes neu aus dem Original. Die Konvertierungsfilter
	 * müssen vorher gesetzt sein (Swipe_Images::register_conversion_filters()).
	 *
	 * @return true|WP_Error
	 */
	public static function regenerate( int $attachment_id, bool $delete_old = false ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file = wp_get_original_image_path( $attachment_id );
		if ( ! $file ) {
			$file = get_attached_file( $attachment_id );
		}
		if ( ! $file || ! file_exists( $file ) ) {
			$e = new WP_Error( 'swipe_images_missing', 'Quelldatei fehlt: ' . (string) $file );
			self::mark_failed( $attachment_id, $e->get_error_message() );
			return $e;
		}

		$basedir   = wp_get_upload_dir()['basedir'];
		$old_meta  = (array) wp_get_attachment_metadata( $attachment_id );
		$old_files = self::files_from_meta( $old_meta, $basedir );

		$new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( empty( $new_meta['file'] ) ) {
			$e = new WP_Error( 'swipe_images_editor', 'Editor lieferte keine Metadaten' );
			self::mark_failed( $attachment_id, $e->get_error_message() );
			return $e;
		}
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		if ( $delete_old ) {
			$keep   = self::files_from_meta( $new_meta, $basedir );
			$keep[] = $file;
			foreach ( array_diff( $old_files, $keep ) as $path ) {
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		self::clear_failed_single( $attachment_id );
		return true;
	}

	/** .webp-Dateien im Upload-Ordner, die kein Attachment referenziert und neben denen ein JPG/PNG liegt. */
	public static function orphan_webp_files(): array {
		global $wpdb;
		$basedir    = wp_get_upload_dir()['basedir'];
		$referenced = array();
		foreach ( (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'" ) as $raw ) {
			$meta = maybe_unserialize( $raw );
			if ( is_array( $meta ) ) {
				foreach ( self::files_from_meta( $meta, $basedir ) as $p ) {
					$referenced[ $p ] = true;
				}
			}
		}

		$orphans  = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			$path = $entry->getPathname();
			if ( ! preg_match( '/\.webp$/i', $path ) || isset( $referenced[ $path ] ) || str_contains( $path, '/swipe-images-preview/' ) ) {
				continue;
			}
			$stem = preg_replace( '/\.webp$/i', '', $path );
			foreach ( array( '.jpg', '.jpeg', '.png', '.JPG', '.JPEG', '.PNG' ) as $ext ) {
				if ( file_exists( $stem . $ext ) ) {
					$orphans[] = $path;
					break;
				}
			}
		}
		sort( $orphans );
		return $orphans;
	}

	public static function mark_failed( int $id, string $msg ): void {
		$failed        = (array) get_option( self::FAILED_OPTION, array() );
		$failed[ $id ] = $msg;
		update_option( self::FAILED_OPTION, $failed, false );
	}

	public static function clear_failed_single( int $id ): void {
		$failed = (array) get_option( self::FAILED_OPTION, array() );
		if ( isset( $failed[ $id ] ) ) {
			unset( $failed[ $id ] );
			update_option( self::FAILED_OPTION, $failed, false );
		}
	}

	public static function failed(): array {
		return (array) get_option( self::FAILED_OPTION, array() );
	}

	public static function clear_failed(): void {
		update_option( self::FAILED_OPTION, array(), false );
	}
}
```

- [ ] **Step 4: Unit-Test grün**

Run: `composer test`
Expected: `RegeneratorTest` 3 Tests grün, gesamt 16.

- [ ] **Step 5: CLI implementieren**

`includes/class-swipe-images-cli.php`:

```php
<?php
/**
 * WP-CLI: wp swipe-images status | regenerate | cleanup
 *
 * @package Swipe_Images
 */

class Swipe_Images_CLI {

	/**
	 * Zeigt Modus, Format, Server-Fähigkeiten, Zähler und fremde Quality-Filter.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swipe-images status
	 */
	public function status( $args, $assoc_args ) {
		$s    = Swipe_Images_Settings::get();
		$caps = Swipe_Images_Detector::capabilities();
		$c    = Swipe_Images_Regenerator::counts();

		WP_CLI::log( 'Modus:      ' . ( Swipe_Images::is_blocked() ? 'blockiert (Theme trägt eigenen Bildcode: ' . Swipe_Images_Detector::legacy_file() . ')' : 'aktiv' ) );
		WP_CLI::log( 'Aktiv:      ' . ( $s['enabled'] ? 'ja' : 'nein' ) );
		WP_CLI::log( sprintf( 'Format:     %s (Qualität WebP %d, AVIF %d)', $s['format'], $s['quality_webp'], $s['quality_avif'] ) );
		WP_CLI::log( sprintf( 'Editor:     WebP %s, AVIF %s', $caps['editor']['webp'] ? 'ja' : 'nein', $caps['editor']['avif'] ? 'ja' : 'nein' ) );
		WP_CLI::log( sprintf( 'GD:         WebP %s, AVIF %s · Imagick: WebP %s, AVIF %s', $caps['gd']['webp'] ? 'ja' : 'nein', $caps['gd']['avif'] ? 'ja' : 'nein', $caps['imagick']['webp'] ? 'ja' : 'nein', $caps['imagick']['avif'] ? 'ja' : 'nein' ) );
		WP_CLI::log( sprintf( 'Bilder:     %d gesamt, %d im Zielformat, %d ausstehend', $c['total'], $c['converted'], $c['pending'] ) );
		foreach ( Swipe_Images_Detector::foreign_quality_filters() as $hook => $list ) {
			WP_CLI::warning( sprintf( 'Fremder Filter auf %s: %s', $hook, implode( ', ', $list ) ) );
		}
		$failed = Swipe_Images_Regenerator::failed();
		if ( $failed ) {
			WP_CLI::warning( count( $failed ) . ' Attachments in der Fehlerliste (wp swipe-images regenerate --ids=… oder Backend).' );
		}
	}

	/**
	 * Erzeugt Full, scaled und alle Grössen neu aus dem Original im Zielformat.
	 *
	 * Läuft auch im blockierten Modus; die Filter gelten nur für diesen Lauf.
	 * Alte Dateien bleiben liegen, ausser mit --delete-old.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Kommagetrennte Attachment-IDs. Ohne Angabe alle ausstehenden.
	 *
	 * [--delete-old]
	 * : Dateien aus den alten Metadaten löschen, die die neuen nicht mehr referenzieren.
	 *
	 * [--yes]
	 * : Keine Rückfrage.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swipe-images regenerate
	 *     wp swipe-images regenerate --ids=12,15 --delete-old
	 */
	public function regenerate( $args, $assoc_args ) {
		$ids = ! empty( $assoc_args['ids'] )
			? array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['ids'] ) ) )
			: Swipe_Images_Regenerator::pending_ids();
		if ( ! $ids ) {
			WP_CLI::success( 'Nichts zu tun, alle Bilder liegen im Zielformat.' );
			return;
		}
		$delete_old = ! empty( $assoc_args['delete-old'] );
		WP_CLI::confirm( sprintf( '%d Bilder regenerieren%s?', count( $ids ), $delete_old ? ' und alte Dateien löschen' : '' ), $assoc_args );

		if ( ! Swipe_Images::register_conversion_filters() ) {
			WP_CLI::error( 'Das Plugin ist in den Einstellungen deaktiviert.' );
		}

		$done     = 0;
		$errors   = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Regeneriere', count( $ids ) );
		foreach ( $ids as $id ) {
			$r = Swipe_Images_Regenerator::regenerate( $id, $delete_old );
			if ( is_wp_error( $r ) ) {
				++$errors;
				WP_CLI::warning( sprintf( 'ID %d: %s', $id, $r->get_error_message() ) );
			} else {
				++$done;
			}
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success( sprintf( '%d regeneriert, %d Fehler.', $done, $errors ) );
	}

	/**
	 * Findet .webp-Dateien aus der On-the-fly-Zeit, die kein Attachment referenziert.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Nur auflisten.
	 *
	 * [--yes]
	 * : Keine Rückfrage vor dem Löschen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swipe-images cleanup --dry-run
	 *     wp swipe-images cleanup
	 */
	public function cleanup( $args, $assoc_args ) {
		$orphans = Swipe_Images_Regenerator::orphan_webp_files();
		if ( ! $orphans ) {
			WP_CLI::success( 'Keine verwaisten WebP-Dateien.' );
			return;
		}
		$bytes = array_sum( array_map( 'filesize', $orphans ) );
		foreach ( $orphans as $p ) {
			WP_CLI::log( $p );
		}
		WP_CLI::log( sprintf( '%d Dateien, %s', count( $orphans ), size_format( $bytes ) ) );
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			return;
		}
		WP_CLI::confirm( sprintf( '%d Dateien löschen?', count( $orphans ) ), $assoc_args );
		foreach ( $orphans as $p ) {
			wp_delete_file( $p );
		}
		WP_CLI::success( sprintf( '%d Dateien gelöscht, %s frei.', count( $orphans ), size_format( $bytes ) ) );
	}
}
```

- [ ] **Step 6: Core: Regenerator laden, CLI registrieren**

In `includes/class-swipe-images.php`, `load_dependencies()`: die Schleife um `'regenerator'` ergänzen:

```php
		foreach ( array( 'loader', 'i18n', 'settings', 'converter', 'detector', 'regenerator' ) as $part ) {
```

`run()` wird zu:

```php
	public function run(): void {
		add_action( 'after_setup_theme', array( $this, 'boot' ), 100 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-cli.php';
			WP_CLI::add_command( 'swipe-images', 'Swipe_Images_CLI' );
		}
	}
```

- [ ] **Step 7: Integrationstest**

In `tests/integration/run.sh` den Marker `# --- TASK6 ---` ersetzen durch:

```bash
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
```

- [ ] **Step 8: Lauf**

Run: `composer test && composer lint && bash tests/integration/run.sh`
Expected: Unit 16 grün, elf `OK:`-Zeilen, `ALLE INTEGRATIONSTESTS OK`.

- [ ] **Step 9: Commit**

```bash
git add includes/class-swipe-images-regenerator.php includes/class-swipe-images-cli.php includes/class-swipe-images.php tests/unit/RegeneratorTest.php tests/integration/run.sh
git commit -m "feat(cli): Add regenerate, cleanup and status commands" -m "Regenerate rebuilds full, scaled and sub-sizes from the original with the conversion filters set for the run only, so migration works while the theme still ships its own code. Old files stay unless --delete-old; cleanup lists orphaned on-the-fly WebP files before deleting." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 7: Settings-Sektion unter Einstellungen → Medien mit Status

**Files:**
- Create: `admin/partials/settings-fields.php`, `admin/partials/settings-status.php`, `admin/css/swipe-images-admin.css`, `admin/js/swipe-images-admin.js`
- Modify: `admin/class-swipe-images-admin.php` (Task 5), `includes/class-swipe-images.php` (`boot()`)

**Interfaces:**
- Consumes: `Swipe_Images_Settings::OPTION`, `::get()`, `::sanitize()`, `::quality_bounds()`, `Swipe_Images_Detector::capabilities()`, `::foreign_quality_filters()`, `Swipe_Images_Regenerator::counts()`, `::failed()`
- Produces: `Swipe_Images_Admin::register_settings()`, `::enqueue( string $hook )`, `::render_section()`, `::render_fields()`, `::render_status()`; JS-Objekt `swipeImages` mit `ajaxUrl`, `nonce`, `previewNonce`

- [ ] **Step 1: Admin-Klasse erweitern**

In `admin/class-swipe-images-admin.php` nach `site_health_test()` einfügen:

```php
	/** Settings-API auf der Medien-Seite. */
	public function register_settings(): void {
		register_setting(
			'media',
			Swipe_Images_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Swipe_Images_Settings', 'sanitize' ),
				'default'           => Swipe_Images_Settings::defaults(),
			)
		);
		add_settings_section( 'swipe_images', 'swipe Bilder', array( $this, 'render_section' ), 'media' );
		add_settings_field( 'swipe_images_fields', 'Format und Qualität', array( $this, 'render_fields' ), 'media', 'swipe_images' );
		add_settings_field( 'swipe_images_status', 'Status', array( $this, 'render_status' ), 'media', 'swipe_images' );
		add_settings_field( 'swipe_images_preview', 'Vorschau', array( $this, 'render_preview' ), 'media', 'swipe_images' );
		add_settings_field( 'swipe_images_regenerate', 'Bestand', array( $this, 'render_regenerate' ), 'media', 'swipe_images' );
	}

	public function render_section(): void {
		echo '<p>Neue Uploads werden direkt aus dem Original als WebP oder AVIF geschrieben. Eine Verlustgeneration, kein Umschreiben von URLs.</p>';
		if ( Swipe_Images::is_blocked() ) {
			echo '<p class="swipe-images-warn">Das Theme trägt noch eigenen Bildcode. Einstellungen werden gespeichert, wirken aber erst nach der Umstellung; Regenerieren funktioniert jetzt schon.</p>';
		}
	}

	public function render_fields(): void {
		$settings = Swipe_Images_Settings::get();
		$caps     = Swipe_Images_Detector::capabilities();
		include SWIPE_IMAGES_PATH . 'admin/partials/settings-fields.php';
	}

	public function render_status(): void {
		$settings = Swipe_Images_Settings::get();
		$caps     = Swipe_Images_Detector::capabilities();
		$counts   = Swipe_Images_Regenerator::counts();
		$foreign  = Swipe_Images_Detector::foreign_quality_filters();
		$failed   = Swipe_Images_Regenerator::failed();
		include SWIPE_IMAGES_PATH . 'admin/partials/settings-status.php';
	}

	public function render_preview(): void {
		include SWIPE_IMAGES_PATH . 'admin/partials/settings-preview.php';
	}

	public function render_regenerate(): void {
		$counts = Swipe_Images_Regenerator::counts();
		include SWIPE_IMAGES_PATH . 'admin/partials/settings-regenerate.php';
	}

	public function enqueue( string $hook ): void {
		if ( 'options-media.php' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'swipe-images-admin', plugins_url( 'admin/css/swipe-images-admin.css', SWIPE_IMAGES_FILE ), array(), $this->version );
		wp_enqueue_script( 'swipe-images-admin', plugins_url( 'admin/js/swipe-images-admin.js', SWIPE_IMAGES_FILE ), array( 'jquery' ), $this->version, true );
		wp_localize_script(
			'swipe-images-admin',
			'swipeImages',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'swipe_images_regenerate' ),
				'previewNonce' => wp_create_nonce( 'swipe_images_preview' ),
			)
		);
	}
```

Die Partials `settings-preview.php` und `settings-regenerate.php` entstehen in Task 8; für diesen Task zwei Platzhalterdateien mit je einer Zeile `<?php // Task 8 ?>` anlegen, damit `include` nicht warnt.

- [ ] **Step 2: Felder-Partial**

`admin/partials/settings-fields.php`:

```php
<?php
/**
 * Felder: Aktiv, Format, PNG, zwei Regler, Breite.
 *
 * @var array $settings
 * @var array $caps
 */
$o    = Swipe_Images_Settings::OPTION;
$bw   = Swipe_Images_Settings::quality_bounds( 'webp' );
$ba   = Swipe_Images_Settings::quality_bounds( 'avif' );
$avif = ! empty( $caps['editor']['avif'] );
?>
<fieldset class="swipe-images-fields">
	<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>> Aktiv</label><br>

	<p><strong>Format</strong></p>
	<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[format]" value="webp" <?php checked( 'webp', $settings['format'] ); ?>> WebP</label>&nbsp;&nbsp;
	<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[format]" value="avif" <?php checked( 'avif', $settings['format'] ); ?> <?php disabled( ! $avif ); ?>> AVIF</label>
	<?php if ( ! $avif ) : ?>
		<span class="description">Der Bild-Editor dieses Servers kann kein AVIF schreiben.</span>
	<?php endif; ?><br>

	<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[convert_png]" value="1" <?php checked( $settings['convert_png'] ); ?>> PNG mitkonvertieren</label>

	<p><label for="swipe-images-qw"><strong>Qualität WebP</strong></label><br>
	<input type="range" id="swipe-images-qw" min="<?php echo (int) $bw['min']; ?>" max="<?php echo (int) $bw['max']; ?>" value="<?php echo (int) $settings['quality_webp']; ?>" data-target="swipe-images-qw-n">
	<input type="number" id="swipe-images-qw-n" name="<?php echo esc_attr( $o ); ?>[quality_webp]" min="<?php echo (int) $bw['min']; ?>" max="<?php echo (int) $bw['max']; ?>" value="<?php echo (int) $settings['quality_webp']; ?>" class="small-text"></p>

	<p><label for="swipe-images-qa"><strong>Qualität AVIF</strong></label><br>
	<input type="range" id="swipe-images-qa" min="<?php echo (int) $ba['min']; ?>" max="<?php echo (int) $ba['max']; ?>" value="<?php echo (int) $settings['quality_avif']; ?>" data-target="swipe-images-qa-n" <?php disabled( ! $avif ); ?>>
	<input type="number" id="swipe-images-qa-n" name="<?php echo esc_attr( $o ); ?>[quality_avif]" min="<?php echo (int) $ba['min']; ?>" max="<?php echo (int) $ba['max']; ?>" value="<?php echo (int) $settings['quality_avif']; ?>" class="small-text" <?php disabled( ! $avif ); ?>></p>

	<p><label for="swipe-images-bt"><strong>Maximale Bildbreite beim Upload</strong></label><br>
	<input type="number" id="swipe-images-bt" name="<?php echo esc_attr( $o ); ?>[big_image_threshold]" min="0" step="1" value="<?php echo (int) $settings['big_image_threshold']; ?>" class="small-text"> px, 0 = aus</p>

	<input type="hidden" name="<?php echo esc_attr( $o ); ?>[max_srcset_width]" value="<?php echo (int) $settings['max_srcset_width']; ?>">
</fieldset>
```

Hinweis: Deaktivierte `<input>`-Felder werden nicht gesendet; `sanitize()` füllt sie aus den Defaults.
Damit AVIF-Qualität und -Format bei fehlender Serverunterstützung nicht verloren gehen, bleibt der
gespeicherte Wert erhalten, weil `sanitize()` fehlende Schlüssel mit Defaults füllt. Das ist akzeptabel:
ohne AVIF-Unterstützung ist der Wert wirkungslos.

- [ ] **Step 3: Status-Partial**

`admin/partials/settings-status.php`:

```php
<?php
/**
 * Statuskasten.
 *
 * @var array $settings
 * @var array $caps
 * @var array $counts
 * @var array $foreign
 * @var array $failed
 */
$ja = static fn( $b ) => $b ? 'ja' : 'nein';
?>
<div class="swipe-images-status">
	<p><strong>Modus:</strong> <?php echo Swipe_Images::is_blocked() ? '<span class="swipe-images-warn">blockiert (Theme trägt eigenen Bildcode)</span>' : 'aktiv'; ?></p>
	<p><strong>Editor kann:</strong> WebP <?php echo esc_html( $ja( $caps['editor']['webp'] ) ); ?>, AVIF <?php echo esc_html( $ja( $caps['editor']['avif'] ) ); ?>
		<span class="description">(GD WebP <?php echo esc_html( $ja( $caps['gd']['webp'] ) ); ?>/AVIF <?php echo esc_html( $ja( $caps['gd']['avif'] ) ); ?> · Imagick WebP <?php echo esc_html( $ja( $caps['imagick']['webp'] ) ); ?>/AVIF <?php echo esc_html( $ja( $caps['imagick']['avif'] ) ); ?>)</span></p>
	<?php if ( 'avif' === $settings['format'] && empty( $caps['editor']['avif'] ) ) : ?>
		<p class="swipe-images-warn">AVIF gewählt, der Server schreibt WebP.</p>
	<?php endif; ?>
	<p><strong>Bilder:</strong> <?php echo (int) $counts['total']; ?> gesamt, <?php echo (int) $counts['converted']; ?> im Zielformat, <span id="swipe-images-pending"><?php echo (int) $counts['pending']; ?></span> ausstehend</p>
	<?php foreach ( $foreign as $hook => $list ) : ?>
		<p class="swipe-images-warn"><strong>Fremder Filter auf <code><?php echo esc_html( $hook ); ?></code>:</strong> <?php echo esc_html( implode( ', ', $list ) ); ?>. Das Plugin gewinnt mit Priorität 999.</p>
	<?php endforeach; ?>
	<?php if ( $failed ) : ?>
		<p class="swipe-images-warn"><strong><?php echo count( $failed ); ?> Bilder mit Fehlern:</strong>
		<?php foreach ( array_slice( $failed, 0, 10, true ) as $id => $msg ) : ?>
			<br>ID <?php echo (int) $id; ?>: <?php echo esc_html( $msg ); ?>
		<?php endforeach; ?></p>
	<?php endif; ?>
</div>
```

- [ ] **Step 4: CSS und JS**

`admin/css/swipe-images-admin.css`:

```css
.swipe-images-fields input[type="range"] { width: 260px; vertical-align: middle; }
.swipe-images-warn { color: #b32d2e; }
.swipe-images-status p { margin: 0 0 6px; }
.swipe-images-preview { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
.swipe-images-preview figure { margin: 0; width: 260px; }
.swipe-images-preview img { width: 100%; height: auto; border: 1px solid #c3c4c7; }
.swipe-images-preview figcaption { font-size: 12px; margin-top: 4px; }
.swipe-images-bar { height: 18px; background: #f0f0f1; border: 1px solid #c3c4c7; margin: 8px 0; max-width: 480px; }
.swipe-images-bar span { display: block; height: 100%; width: 0; background: #2271b1; color: #fff; font-size: 11px; line-height: 18px; text-align: center; }
```

`admin/js/swipe-images-admin.js` (Task 8 ergänzt Vorschau und Batch unter dem Marker):

```js
/* global jQuery, swipeImages */
jQuery(function ($) {
	// Regler und Zahlenfeld gekoppelt.
	$('.swipe-images-fields input[type="range"]').each(function () {
		var $range = $(this), $num = $('#' + $range.data('target'));
		$range.on('input', function () { $num.val($range.val()); });
		$num.on('input', function () { $range.val($num.val()); });
	});

	// --- TASK8 ---
});
```

- [ ] **Step 5: Core-Hooks**

In `includes/class-swipe-images.php`, `boot()`, nach den zwei Admin-Zeilen aus Task 5:

```php
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue' );
```

- [ ] **Step 6: Im Browser prüfen**

Run: `composer lint` ohne Fehler, dann `https://swipe.wordpress-starter.local:8890/wp-admin/options-media.php` (Login swipe/swipe) im Chrome öffnen.
Expected: Abschnitt «swipe Bilder» mit Checkbox, Format-Radio (AVIF ausgegraut mit Hinweis), zwei gekoppelten Reglern, Breite, Statuskasten mit «Editor kann: WebP ja, AVIF nein» und Zählern. Regler WebP auf 70 stellen, «Änderungen speichern», Seite zeigt 70. Konsole ohne Fehler.

- [ ] **Step 7: Sanitizing über die Seite prüfen**

Run: `wp option get swipe_images_settings --format=json`
Expected: `"quality_webp":70`, alle sieben Schlüssel vorhanden.

- [ ] **Step 8: Commit**

```bash
git add admin includes/class-swipe-images.php
git commit -m "feat(admin): Add settings section with sliders and status on the Media page" -m "Settings API section on options-media.php: enabled, format (AVIF greyed out without editor support), PNG toggle, two coupled sliders, upload width, plus a status box with server capabilities, counters and foreign quality filters." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 8: AJAX-Vorschau und Regenerieren im Backend

**Files:**
- Create: `admin/partials/settings-preview.php`, `admin/partials/settings-regenerate.php` (ersetzen die Platzhalter aus Task 7)
- Modify: `admin/class-swipe-images-admin.php`, `admin/js/swipe-images-admin.js` (Marker `// --- TASK8 ---`), `includes/class-swipe-images-regenerator.php` (`pending_ids()`), `includes/class-swipe-images.php` (`boot()`)
- Test: `tests/unit/RegeneratorTest.php` bleibt; Browser- und curl-Prüfung

**Interfaces:**
- Consumes: `Swipe_Images_Regenerator::regenerate()`, `::counts()`, `::failed()`, `Swipe_Images::register_conversion_filters()`, `Swipe_Images_Converter::target_mime()`, `Swipe_Images_Detector::editor_supports()`
- Produces: `Swipe_Images_Regenerator::pending_ids( int $limit = 0, array $exclude = array() ): int[]` (Signatur erweitert), `Swipe_Images_Admin::ajax_preview(): void`, `::ajax_regenerate(): void`; AJAX-Actions `swipe_images_preview` (POST `nonce`, `attachment_id`, `quality`) und `swipe_images_regenerate` (POST `nonce`) mit JSON `{done, errors, pending, has_more}`

- [ ] **Step 1: pending_ids um Ausschlussliste erweitern**

In `includes/class-swipe-images-regenerator.php` die Methode `pending_ids()` ersetzen:

```php
	/**
	 * IDs der Attachments, deren Datei noch nicht im Zielformat liegt.
	 *
	 * @param int   $limit   0 = alle.
	 * @param int[] $exclude IDs, die übersprungen werden (Fehlerliste), damit ein Batch-Lauf endet.
	 */
	public static function pending_ids( int $limit = 0, array $exclude = array() ): array {
		global $wpdb;
		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN (" . self::IMAGE_MIMES . ")
			AND m.meta_value NOT LIKE '%.webp' AND m.meta_value NOT LIKE '%.avif'";
		$exclude = array_filter( array_map( 'intval', $exclude ) );
		if ( $exclude ) {
			$sql .= ' AND p.ID NOT IN (' . implode( ',', $exclude ) . ')';
		}
		$sql .= ' ORDER BY p.ID ASC';
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}
```

- [ ] **Step 2: AJAX-Handler in der Admin-Klasse**

In `admin/class-swipe-images-admin.php` nach `enqueue()` einfügen:

```php
	/** Vorschau: ein Bild bei Reglerwert −10, Reglerwert und +10 im Zielformat, 1200 px breit. */
	public function ajax_preview(): void {
		check_ajax_referer( 'swipe_images_preview', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Keine Berechtigung.', 403 );
		}
		$id      = absint( $_POST['attachment_id'] ?? 0 );
		$quality = absint( $_POST['quality'] ?? 0 );
		$file    = wp_get_original_image_path( $id );
		if ( ! $file ) {
			$file = get_attached_file( $id );
		}
		if ( ! $file || ! file_exists( $file ) ) {
			wp_send_json_error( 'Bild nicht gefunden.' );
		}

		$settings = Swipe_Images_Settings::get();
		$mime     = Swipe_Images_Converter::target_mime( $settings['format'], Swipe_Images_Detector::editor_supports( 'image/avif' ) );
		$ext      = 'image/avif' === $mime ? 'avif' : 'webp';
		$bounds   = Swipe_Images_Settings::quality_bounds( $ext );
		$upload   = wp_get_upload_dir();
		$dir      = $upload['basedir'] . '/swipe-images-preview';
		wp_mkdir_p( $dir );

		$out = array();
		foreach ( array( $quality - 10, $quality, $quality + 10 ) as $q ) {
			$q      = max( $bounds['min'], min( $bounds['max'], $q ) );
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				wp_send_json_error( $editor->get_error_message() );
			}
			$editor->resize( 1200, 1200 );
			$editor->set_quality( $q );
			$path  = sprintf( '%s/preview-%d-%d.%s', $dir, get_current_user_id(), $q, $ext );
			$saved = $editor->save( $path, $mime );
			if ( is_wp_error( $saved ) ) {
				wp_send_json_error( $saved->get_error_message() );
			}
			$bytes = (int) filesize( $saved['path'] );
			$out[] = array(
				'quality' => $q,
				'url'     => $upload['baseurl'] . '/swipe-images-preview/' . basename( $saved['path'] ) . '?t=' . time(),
				'bytes'   => $bytes,
				'size'    => size_format( $bytes ),
			);
		}
		wp_send_json_success( $out );
	}

	/** Ein Batch von fünf ausstehenden Bildern; die Fehlerliste wird übersprungen, damit der Lauf endet. */
	public function ajax_regenerate(): void {
		check_ajax_referer( 'swipe_images_regenerate', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Keine Berechtigung.', 403 );
		}
		if ( ! Swipe_Images::register_conversion_filters() ) {
			wp_send_json_error( 'Das Plugin ist in den Einstellungen deaktiviert.' );
		}
		$exclude = array_keys( Swipe_Images_Regenerator::failed() );
		$done    = 0;
		$errors  = 0;
		foreach ( Swipe_Images_Regenerator::pending_ids( 5, $exclude ) as $id ) {
			$r = Swipe_Images_Regenerator::regenerate( $id );
			if ( is_wp_error( $r ) ) {
				++$errors;
				$exclude[] = $id;
			} else {
				++$done;
			}
		}
		wp_send_json_success(
			array(
				'done'     => $done,
				'errors'   => $errors,
				'pending'  => Swipe_Images_Regenerator::counts()['pending'],
				'has_more' => (bool) Swipe_Images_Regenerator::pending_ids( 1, $exclude ),
			)
		);
	}
```

- [ ] **Step 3: Partials**

`admin/partials/settings-preview.php`:

```php
<?php
/** Vorschau: Bild aus der Mediathek, drei Qualitätsstufen. */
?>
<p><button type="button" class="button" id="swipe-images-pick">Bild wählen</button>
<span class="description">zeigt das Bild bei Reglerwert −10, Reglerwert und +10 mit Dateigrösse (1200 px breit)</span></p>
<div class="swipe-images-preview" id="swipe-images-preview"></div>
```

`admin/partials/settings-regenerate.php`:

```php
<?php
/**
 * Bestand regenerieren, AJAX-Batches.
 *
 * @var array $counts
 */
?>
<p><button type="button" class="button button-secondary" id="swipe-images-regen" data-pending="<?php echo (int) $counts['pending']; ?>" <?php disabled( 0 === (int) $counts['pending'] ); ?>>
	Bestand regenerieren (<?php echo (int) $counts['pending']; ?> ausstehend)</button></p>
<div class="swipe-images-bar" id="swipe-images-bar" hidden><span></span></div>
<p id="swipe-images-log" class="description"></p>
<p class="description">Alte Dateien bleiben liegen. Aufräumen per WP-CLI: <code>wp swipe-images regenerate --delete-old</code> und <code>wp swipe-images cleanup</code>.</p>
```

- [ ] **Step 4: JavaScript**

In `admin/js/swipe-images-admin.js` den Marker `// --- TASK8 ---` ersetzen durch:

```js
	// Vorschau über die Mediathek.
	var frame;
	$('#swipe-images-pick').on('click', function (e) {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({ title: 'Bild für die Vorschau', multiple: false, library: { type: ['image/jpeg', 'image/png', 'image/webp', 'image/avif'] } });
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				var isAvif = $('.swipe-images-fields input[name$="[format]"]:checked').val() === 'avif';
				var q = parseInt($('#swipe-images-' + (isAvif ? 'qa' : 'qw') + '-n').val(), 10);
				var $box = $('#swipe-images-preview').html('<p>Rechne…</p>');
				$.post(swipeImages.ajaxUrl, { action: 'swipe_images_preview', nonce: swipeImages.previewNonce, attachment_id: att.id, quality: q }, function (r) {
					if (!r.success) { $box.html('<p class="swipe-images-warn">' + r.data + '</p>'); return; }
					$box.empty();
					r.data.forEach(function (v) {
						$box.append('<figure><img src="' + v.url + '" alt=""><figcaption>Qualität ' + v.quality + ' · ' + v.size + '</figcaption></figure>');
					});
				}).fail(function () { $box.html('<p class="swipe-images-warn">Vorschau fehlgeschlagen.</p>'); });
			});
		}
		frame.open();
	});

	// Bestand regenerieren in Batches à fünf Bilder.
	$('#swipe-images-regen').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var total = parseInt($btn.data('pending'), 10) || 0, done = 0, errors = 0;
		var $bar = $('#swipe-images-bar').prop('hidden', false), $fill = $bar.find('span'), $log = $('#swipe-images-log');
		function step() {
			$.post(swipeImages.ajaxUrl, { action: 'swipe_images_regenerate', nonce: swipeImages.nonce }, function (r) {
				if (!r.success) { $log.html('<span class="swipe-images-warn">' + r.data + '</span>'); $btn.prop('disabled', false); return; }
				done += r.data.done; errors += r.data.errors;
				var pct = total ? Math.min(100, Math.round((done + errors) / total * 100)) : 100;
				$fill.css('width', pct + '%').text(pct + '%');
				$('#swipe-images-pending').text(r.data.pending);
				$log.text(done + ' regeneriert' + (errors ? ', ' + errors + ' Fehler (siehe Status nach dem Neuladen)' : ''));
				if (r.data.has_more) { step(); } else { $fill.css('width', '100%').text('100%'); $btn.prop('disabled', false); }
			}).fail(function () { $log.html('<span class="swipe-images-warn">Netzwerkfehler, bitte erneut starten.</span>'); $btn.prop('disabled', false); });
		}
		step();
	});
```

- [ ] **Step 5: Hooks im Core**

In `includes/class-swipe-images.php`, `boot()`, nach den Admin-Zeilen aus Task 7:

```php
		$this->loader->add_action( 'wp_ajax_swipe_images_preview', $admin, 'ajax_preview' );
		$this->loader->add_action( 'wp_ajax_swipe_images_regenerate', $admin, 'ajax_regenerate' );
```

- [ ] **Step 6: Ausstehende Bilder erzeugen und im Browser prüfen**

```bash
export WP_CLI_PHP=/Applications/MAMP/bin/php/php8.3.30/bin/php
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local
wp theme activate datacenterthurgau
for i in 1 2 3 4 5 6 7; do wp media import wp-content/plugins/swipe-images/tests/integration/tmp/photo.jpg --porcelain; done
wp theme activate twentytwentyfive
wp swipe-images status | grep ausstehend
```
Expected: `7 ausstehend`.

Dann `options-media.php` im Chrome: «Bild wählen», ein Bild anklicken.
Expected: drei Kacheln, Dateigrössen aufsteigend von links nach rechts. Dann «Bestand regenerieren (7 ausstehend)».
Expected: Balken bis 100 %, Log «7 regeneriert», Zähler «0 ausstehend», Knopf wieder aktiv. Konsole ohne Fehler.

- [ ] **Step 7: Nonce-Schutz prüfen**

Run: `curl -sk -o /dev/null -w '%{http_code}\n' -X POST https://swipe.wordpress-starter.local:8890/wp-admin/admin-ajax.php -d action=swipe_images_regenerate`
Expected: `403`.

- [ ] **Step 8: Unit und Integration weiter grün**

Run: `composer test && composer lint && bash tests/integration/run.sh`
Expected: alles grün.

- [ ] **Step 9: Commit**

```bash
git add admin includes/class-swipe-images.php includes/class-swipe-images-regenerator.php
git commit -m "feat(admin): Add quality preview and batch regenerate" -m "Preview renders the chosen image at slider −10, slider and +10 in the target format with file sizes so a quality decision is made against real bytes. Regenerate runs five attachments per AJAX call, skips the failed list and reports progress." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 9: Updates aus GitHub-Releases

**Files:**
- Create: `includes/class-swipe-images-updater.php`, `.github/workflows/release.yml`, `.gitattributes`
- Modify: `includes/class-swipe-images.php` (`load_dependencies()`, `run()`)
- Test: `tests/unit/UpdaterTest.php`

**Interfaces:**
- Consumes: Konstanten `SWIPE_IMAGES_VERSION`, `SWIPE_IMAGES_BASENAME`
- Produces: `Swipe_Images_Updater::build_update( $release, string $current, string $plugin_file ): array|false` (rein), `Swipe_Images_Updater->check( $update, $plugin_data, $plugin_file, $locales )` als Callback für `update_plugins_github.com`

- [ ] **Step 1: Failing Test schreiben**

`tests/unit/UpdaterTest.php`:

```php
<?php
use PHPUnit\Framework\TestCase;

class UpdaterTest extends TestCase {

	private function release( string $tag, bool $with_asset = true ): array {
		$r = array( 'tag_name' => $tag, 'html_url' => 'https://github.com/swipegmbh/swipe-images/releases/tag/' . $tag, 'assets' => array() );
		if ( $with_asset ) {
			$r['assets'][] = array( 'name' => 'swipe-images.zip', 'browser_download_url' => 'https://github.com/swipegmbh/swipe-images/releases/download/' . $tag . '/swipe-images.zip' );
		}
		return $r;
	}

	public function test_newer_release_with_asset_yields_update(): void {
		$u = Swipe_Images_Updater::build_update( $this->release( 'v1.0.1' ), '1.0.0', 'swipe-images/swipe-images.php' );
		$this->assertSame( '1.0.1', $u['version'] );
		$this->assertSame( 'swipe-images', $u['slug'] );
		$this->assertSame( 'swipe-images/swipe-images.php', $u['plugin'] );
		$this->assertStringEndsWith( '/v1.0.1/swipe-images.zip', $u['package'] );
	}

	public function test_same_or_older_release_is_no_update(): void {
		$this->assertFalse( Swipe_Images_Updater::build_update( $this->release( 'v1.0.0' ), '1.0.0', 'x' ) );
		$this->assertFalse( Swipe_Images_Updater::build_update( $this->release( '0.9.0' ), '1.0.0', 'x' ) );
	}

	public function test_missing_asset_or_garbage_is_no_update(): void {
		$this->assertFalse( Swipe_Images_Updater::build_update( $this->release( 'v2.0.0', false ), '1.0.0', 'x' ) );
		$this->assertFalse( Swipe_Images_Updater::build_update( array(), '1.0.0', 'x' ) );
		$this->assertFalse( Swipe_Images_Updater::build_update( 'kaputt', '1.0.0', 'x' ) );
	}
}
```

- [ ] **Step 2: Test ausführen, muss fehlschlagen**

Run: `composer test`
Expected: FAIL, `Class "Swipe_Images_Updater" not found`.

- [ ] **Step 3: Updater implementieren**

`includes/class-swipe-images-updater.php`:

```php
<?php
/**
 * Updates aus GitHub-Releases über den Core-Mechanismus «Update URI».
 *
 * WordPress ruft update_plugins_github.com für jedes Plugin mit einem Update URI auf
 * github.com auf; wir antworten nur für unser eigenes.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Updater {

	const REPO      = 'swipegmbh/swipe-images';
	const URI       = 'https://github.com/swipegmbh/swipe-images';
	const ASSET     = 'swipe-images.zip';
	const TRANSIENT = 'swipe_images_update';

	/**
	 * Baut die Update-Antwort aus dem Release-JSON. Rein, testbar.
	 *
	 * @param mixed  $release     Dekodiertes JSON von /releases/latest.
	 * @param string $current     Installierte Version.
	 * @param string $plugin_file Plugin-Basename.
	 * @return array|false
	 */
	public static function build_update( $release, string $current, string $plugin_file ) {
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return false;
		}
		$version = ltrim( (string) $release['tag_name'], 'vV' );
		if ( ! version_compare( $version, $current, '>' ) ) {
			return false;
		}
		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			return false;
		}
		return array(
			'id'           => self::URI,
			'slug'         => 'swipe-images',
			'plugin'       => $plugin_file,
			'version'      => $version,
			'url'          => (string) ( $release['html_url'] ?? self::URI ),
			'package'      => $package,
			'requires'     => '6.5',
			'requires_php' => '8.1',
		);
	}

	/** Callback für update_plugins_github.com. */
	public function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( SWIPE_IMAGES_BASENAME !== $plugin_file ) {
			return $update;
		}
		$release = get_transient( self::TRANSIENT );
		if ( false === $release ) {
			$response = wp_remote_get(
				'https://api.github.com/repos/' . self::REPO . '/releases/latest',
				array(
					'timeout' => 8,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'swipe-images/' . SWIPE_IMAGES_VERSION,
					),
				)
			);
			$release = array();
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$release = (array) json_decode( wp_remote_retrieve_body( $response ), true );
			}
			set_transient( self::TRANSIENT, $release, 12 * HOUR_IN_SECONDS );
		}
		$built = self::build_update( $release, SWIPE_IMAGES_VERSION, $plugin_file );
		return $built ? $built : $update;
	}
}
```

- [ ] **Step 4: Unit-Test grün**

Run: `composer test`
Expected: `UpdaterTest` 3 Tests grün, gesamt 19.

- [ ] **Step 5: Core: Updater laden und Filter setzen**

In `includes/class-swipe-images.php`: `load_dependencies()`-Schleife um `'updater'` ergänzen:

```php
		foreach ( array( 'loader', 'i18n', 'settings', 'converter', 'detector', 'regenerator', 'updater' ) as $part ) {
```

`run()` bekommt vor dem CLI-Block:

```php
		add_filter( 'update_plugins_github.com', array( new Swipe_Images_Updater(), 'check' ), 10, 4 );
```

Der Filter hängt nicht am Modus und muss auch im Cron greifen, darum in `run()` statt in `boot()`.

- [ ] **Step 6: Release-Workflow und Export-Ausschlüsse**

`.gitattributes`:

```
/tests        export-ignore
/docs         export-ignore
/tasks        export-ignore
/.github      export-ignore
/.gitattributes export-ignore
/.gitignore   export-ignore
/composer.json export-ignore
/phpunit.xml  export-ignore
```

`.github/workflows/release.yml`:

```yaml
name: Release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
jobs:
  zip:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Zip aus git archive (export-ignore greift)
        run: git archive --format=zip --prefix=swipe-images/ -o swipe-images.zip "$GITHUB_REF_NAME"
      - name: Release anlegen und Asset anhängen
        env:
          GH_TOKEN: ${{ github.token }}
        run: gh release create "$GITHUB_REF_NAME" swipe-images.zip --title "$GITHUB_REF_NAME" --generate-notes
```

- [ ] **Step 7: Update-Anzeige lokal simulieren**

```bash
export WP_CLI_PHP=/Applications/MAMP/bin/php/php8.3.30/bin/php
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local
wp eval 'set_transient("swipe_images_update", array("tag_name" => "v9.9.9", "html_url" => "https://github.com/swipegmbh/swipe-images", "assets" => array(array("name" => "swipe-images.zip", "browser_download_url" => "https://example.com/swipe-images.zip"))), 3600);
delete_site_transient("update_plugins"); wp_update_plugins();
$u = get_site_transient("update_plugins"); echo $u->response["swipe-images/swipe-images.php"]->new_version, "\n";
delete_transient("swipe_images_update"); delete_site_transient("update_plugins");'
```
Expected: `9.9.9`.

- [ ] **Step 8: Commit**

```bash
git add includes/class-swipe-images-updater.php includes/class-swipe-images.php tests/unit/UpdaterTest.php .github .gitattributes
git commit -m "feat(updater): Serve updates from GitHub releases via Update URI" -m "Answer update_plugins_github.com for our own basename only, cache the latest release for twelve hours and point the package at the swipe-images.zip asset that the tag workflow builds with git archive." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 10: Starter-Theme ohne Bildcode

**Files (im Klon `~/Sites/swipe-starter-theme`, Branch `feat/swipe-images`):**
- Delete: `functions-parts/functions-images.php`
- Modify: `functions.php` (require-Zeile, `swipe_preload_hero_image()`, neue Funktion `swipe_require_images_plugin()`), `README.md` (Zeile «Bilder»), `CLAUDE.md` (Zeile «Images»)

**Interfaces:**
- Consumes: Kompat-API aus Task 5 (`swipe_responsive_image`, `swipe_get_webp_url`)
- Produces: Theme-Funktion `swipe_require_images_plugin()` bei `after_setup_theme` Priorität 200 mit Admin-Hinweis und Minimal-Fallback für `swipe_responsive_image`

- [ ] **Step 1: Klon und Branch**

```bash
cd /Users/swipegmbh/Sites
[ -d swipe-starter-theme ] || git clone -q https://github.com/swipegmbh/swipe-starter-theme.git
cd swipe-starter-theme && git checkout -q main && git pull -q && git checkout -q -b feat/swipe-images
git rm -q functions-parts/functions-images.php
```

- [ ] **Step 2: functions.php anpassen**

Die Zeile `require_once locate_template('/functions-parts/functions-images.php');` entfernen.

In `swipe_preload_hero_image()` den Block ab `$image_desktop_url = …` bis zum Ende der Funktion ersetzen durch:

```php
	$image_desktop_url = wp_get_attachment_image_url($image_desktop_id, 'full');
	$image_mobile_url = $image_mobile_id ? wp_get_attachment_image_url($image_mobile_id, 'full') : null;
	if (!$image_desktop_url) {
		return;
	}

	// Die URLs liegen durch das Plugin swipe-images bereits im Zielformat vor.
	if ($image_mobile_url) {
		echo '<link rel="preload" as="image" href="' . esc_url($image_mobile_url) . '" media="(max-width: 575px)">' . "\n";
		echo '<link rel="preload" as="image" href="' . esc_url($image_desktop_url) . '" media="(min-width: 576px)">' . "\n";
	} else {
		echo '<link rel="preload" as="image" href="' . esc_url($image_desktop_url) . '">' . "\n";
	}
}
add_action('wp_head', 'swipe_preload_hero_image', 1);
```

Direkt nach dem `add_action('wp_head', 'swipe_preload_hero_image', 1);` einfügen:

```php
/**
 * Das Theme braucht das Plugin swipe-images (Bildformat, srcset-Helfer).
 * Fehlt es: Hinweis im Backend und ein Minimal-Fallback, damit Blocks nicht fatalen.
 * Priorität 200, weil das Plugin seine Helfer bei after_setup_theme 100 deklariert.
 */
function swipe_require_images_plugin()
{
	if (function_exists('swipe_responsive_image')) {
		return;
	}
	add_action('admin_notices', static function () {
		if (!current_user_can('activate_plugins')) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Plugin «swipe Bilder» fehlt.</strong> Die Blocks dieses Themes brauchen es. Installieren: '
			. '<code>wp plugin install https://github.com/swipegmbh/swipe-images/releases/latest/download/swipe-images.zip --activate</code></p></div>';
	});

	function swipe_responsive_image($image, $size = 'large', $attr = array(), $sizes = '100vw')
	{
		$id = is_array($image) ? (int) ($image['ID'] ?? $image['id'] ?? 0) : (int) $image;
		if (!$id) {
			return '';
		}
		$attr = wp_parse_args($attr, array('class' => 'img-fluid', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => $sizes));
		return wp_get_attachment_image($id, $size, false, $attr);
	}
}
add_action('after_setup_theme', 'swipe_require_images_plugin', 200);
```

- [ ] **Step 3: README und CLAUDE.md**

`README.md`, Zeile `- **Bilder:** Automatische WebP-Konvertierung via `swipe_responsive_image()`` ersetzen durch:

```markdown
- **Bilder:** Plugin `swipe-images` (WebP/AVIF beim Upload, Regler unter Einstellungen → Medien). Blocks nutzen `swipe_responsive_image()` aus dem Plugin; das Theme trägt keinen Bildcode.
```

`CLAUDE.md`, Zeile `- **Images:** Auto-converted to WebP on the fly. …` ersetzen durch:

```markdown
- **Images:** Handled by the `swipe-images` plugin (WebP/AVIF at upload, quality slider under Settings → Media). Use `swipe_responsive_image()` from the plugin for srcset; never add image conversion code to the theme. Lazy loading applied except for LCP images.
```

- [ ] **Step 4: Gegen das Starter-Local prüfen**

```bash
export WP_CLI_PHP=/Applications/MAMP/bin/php/php8.3.30/bin/php
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local
ln -sfn /Users/swipegmbh/Sites/swipe-starter-theme wp-content/themes/swipe-starter
wp theme activate swipe-starter
php -l wp-content/themes/swipe-starter/functions.php
wp eval 'echo Swipe_Images::is_blocked() ? "blockiert" : "aktiv", "\n";'
ID=$(wp media import wp-content/plugins/swipe-images/tests/integration/tmp/photo.jpg --porcelain)
wp eval "echo strpos(swipe_responsive_image($ID, 'large'), '.webp') !== false ? 'helper ok' : 'helper FAIL', \"\n\";"
wp plugin deactivate swipe-images
wp eval "echo strpos(swipe_responsive_image($ID, 'large'), 'srcset=') !== false ? 'fallback ok' : 'fallback FAIL', \"\n\";"
wp eval 'wp_set_current_user(1); ob_start(); do_action("admin_notices"); echo strpos(ob_get_clean(), "swipe Bilder» fehlt") !== false ? "notice ok" : "notice FAIL", "\n";'
wp plugin activate swipe-images
wp post delete "$ID" --force
wp theme activate twentytwentyfive
```
Expected: `No syntax errors`, `aktiv`, `helper ok`, `fallback ok`, `notice ok`.

- [ ] **Step 5: Commit im Starter-Repo (kein Push, das macht Task 12)**

```bash
cd /Users/swipegmbh/Sites/swipe-starter-theme
git add -A
git commit -m "ref(images): Move image handling to the swipe-images plugin" -m "Drop functions-images.php and the on-the-fly WebP chain. The plugin generates WebP/AVIF at upload; the theme keeps a fallback swipe_responsive_image() and an admin notice when the plugin is missing so nothing fatals." -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

---

### Task 11: Skills, Vault, README

**Files:**
- Modify: `~/.claude/skills/swipe-wordpress-theme/references/project-setup.md` (Schritt 2), `~/.claude/skills/swipe-wordpress-theme/SKILL.md` (Abschnitt «Starter-Theme Bugfix-Regel»), `~/.claude/skills/swipe-wordpress-theme/templates/claude-md-template.md` (falls eine Zeile «WebP on the fly» enthalten ist)
- Modify: `~/Documents/Obsidian/swipe/Projekte/swipe-images/swipe-images.md`, `~/Documents/Obsidian/swipe/Über uns/Entwicklung/Plugin-Entwicklung.md` (Abschnitt «Anwendungen»)
- Modify: `README.md` im Plugin

**Interfaces:**
- Consumes: Release-URL `https://github.com/swipegmbh/swipe-images/releases/latest/download/swipe-images.zip`

- [ ] **Step 1: project-setup.md, Schritt 2**

Den Codeblock in «Schritt 2: Starter-Theme klonen» ersetzen durch:

```bash
cd wp-content/themes
git clone https://github.com/swipegmbh/swipe-starter-theme.git {projektname}
cd {projektname}
rm -rf .git
git init

# Pflicht-Plugin: Bildformat und srcset-Helfer liegen nicht mehr im Theme
cd ../..
wp plugin install https://github.com/swipegmbh/swipe-images/releases/latest/download/swipe-images.zip --activate
```

Darunter einen Absatz:

```markdown
Das Theme zeigt ohne `swipe-images` einen roten Hinweis im Backend und rendert Bilder nur über einen
Minimal-Fallback ohne WebP. Qualität und Format: Einstellungen → Medien → «swipe Bilder».
```

- [ ] **Step 2: SKILL.md, Bugfix-Regel**

Den Abschnitt ersetzen durch:

```markdown
## Starter-Theme Bugfix-Regel

Wenn ein Bug aus dem Starter-Theme stammt: User fragen ob Fix auch ins Starter-Theme soll.
Bildfehler (WebP/AVIF, srcset, `swipe_responsive_image`) gehören nicht ins Theme, sondern ins Plugin
`swipe-images` (Repo `swipegmbh/swipe-images`); ein Release dort erreicht alle Sites per Update.
→ Detail: `references/project-setup.md`
```

- [ ] **Step 3: Template prüfen**

Run: `grep -n -i "webp\|on the fly" ~/.claude/skills/swipe-wordpress-theme/templates/claude-md-template.md || echo "nichts zu ändern"`
Falls Treffer: die Zeile wie in Task 10 Schritt 3 (CLAUDE.md) umformulieren.

- [ ] **Step 4: Vault**

In `Projekte/swipe-images/swipe-images.md`: Frontmatter `status: in Entwicklung` → `status: v1.0.0`, `stand` auf das Datum; unter «Slug» das Repo als Link eintragen; einen Abschnitt «Migration je Site» mit den fünf Schritten aus der Spec (Abschnitt 7) und einer Tabelle «Site | Stand | Datum» mit den 26 Sites aus dem Sweep, alle auf «offen».

In `Über uns/Entwicklung/Plugin-Entwicklung.md`, Abschnitt «Anwendungen», eine Zeile:

```markdown
- [[swipe-images]] — Bildformat für die ganze Flotte, erstes Plugin mit Updates aus GitHub-Releases (`Update URI`).
```

- [ ] **Step 5: README des Plugins**

`README.md` vollständig ersetzen:

```markdown
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
`swipe_get_image_dimensions()`, `swipe_preload_responsive_image()`. Die alten `swipe_get_webp_*`-Funktionen
bleiben aus Kompatibilität erhalten und geben die URL unverändert zurück.

## Entwicklung

    composer install
    composer test
    composer lint
    bash tests/integration/run.sh

Release: Tag `vX.Y.Z` pushen, die GitHub-Action hängt `swipe-images.zip` an das Release.

Spec: `docs/superpowers/specs/2026-09-03-swipe-images-design.md` · Plan: `docs/superpowers/plans/2026-09-03-swipe-images.md`
```

- [ ] **Step 6: Commit (Plugin-Repo)**

```bash
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local/wp-content/plugins/swipe-images
git add README.md
git commit -m "docs: Describe installation, modes, migration and CLI" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
```

Skills und Vault liegen ausserhalb des Repos; dort keinen Commit.

---

### Task 12: Repo, Release, Update-Weg live (Freigabe nötig)

**Files:**
- Modify: `swipe-images.php` (Version), `includes/class-swipe-images.php` (nichts), Tag

**Vorher fragen:** Dieses Task legt ein **öffentliches** Repo an und pusht den Starter-Branch. Beides ist nach aussen sichtbar. Nur nach ausdrücklichem Ja des Users ausführen.

- [ ] **Step 1: Repo anlegen und pushen**

```bash
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local/wp-content/plugins/swipe-images
gh repo create swipegmbh/swipe-images --public --source=. --remote=origin --description "WebP/AVIF beim Upload, Qualitätsregler, Migration. swipe GmbH." --push
```
Expected: Repo sichtbar unter `https://github.com/swipegmbh/swipe-images`, Branch `main` gepusht.

- [ ] **Step 2: Release v1.0.0**

```bash
git tag -a v1.0.0 -m "swipe-images 1.0.0"
git push origin v1.0.0
sleep 60
gh release view v1.0.0 --json assets --jq '.assets[].name'
```
Expected: `swipe-images.zip`.

- [ ] **Step 3: Installation aus dem Release**

```bash
export WP_CLI_PHP=/Applications/MAMP/bin/php/php8.3.30/bin/php
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local
mv wp-content/plugins/swipe-images /Users/swipegmbh/Sites/swipe-images-dev
wp plugin install https://github.com/swipegmbh/swipe-images/releases/latest/download/swipe-images.zip --activate
wp plugin get swipe-images --field=version
```
Expected: `1.0.0`. Der Entwicklungsstand liegt jetzt unter `~/Sites/swipe-images-dev`; ab hier dort arbeiten.

- [ ] **Step 4: Update-Weg mit v1.0.1**

```bash
cd /Users/swipegmbh/Sites/swipe-images-dev
sed -i '' -e "s/Version:           1.0.0/Version:           1.0.1/" -e "s/SWIPE_IMAGES_VERSION', '1.0.0'/SWIPE_IMAGES_VERSION', '1.0.1'/" swipe-images.php
git commit -am "chore: Release 1.0.1" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn"
git push && git tag -a v1.0.1 -m "swipe-images 1.0.1" && git push origin v1.0.1
sleep 60
cd /Users/swipegmbh/Sites/swipe.wordpress-starter.local
wp eval 'delete_transient("swipe_images_update"); delete_site_transient("update_plugins");'
wp plugin list --update=available --field=name | grep -q swipe-images && wp plugin update swipe-images && wp plugin get swipe-images --field=version
```
Expected: `1.0.1`.

- [ ] **Step 5: Starter-Branch pushen und PR**

```bash
cd /Users/swipegmbh/Sites/swipe-starter-theme
git push -u origin feat/swipe-images
gh pr create --title "Move image handling to the swipe-images plugin" --body "$(printf 'Drops functions-images.php; the plugin swipe-images handles WebP/AVIF at upload. Theme keeps a fallback swipe_responsive_image() and a notice when the plugin is missing.\n\nSpec: swipegmbh/swipe-images docs/superpowers/specs/2026-09-03-swipe-images-design.md\n\n🤖 Generated with [Claude Code](https://claude.com/claude-code)\n\nhttps://claude.ai/code/session_01Wkw4Uq6EekuC9UjLLmovkn')"
```

- [ ] **Step 6: AVIF-Default auf einem Server mit AVIF prüfen**

Lokal gibt es kein AVIF. Auf einer Testsite auf srv02.swipe.ch (PHP 8.3, GD und Imagick mit AVIF) das Plugin installieren, Format AVIF, Vorschau mit drei echten Fotos bei 55 / 65 / 75. Ergebnis und die gewählte Default-Empfehlung in die Vault-Notiz, Abschnitt «Hinweise / Risiken». Falls der Default wechselt: `Swipe_Images_Settings::defaults()` und `SettingsTest` anpassen, Release 1.0.2.

- [ ] **Step 7: Memory und Vault nachführen**

`~/.claude/projects/-Users-swipegmbh/memory/swipe-images-plugin.md`: Status auf «v1.0.x released, Repo public, Starter-PR offen/gemergt»; MEMORY.md-Zeile anpassen. Vault-Notiz `stand` aktualisieren.

---

## Reihenfolge und Parallelität

Sequenziell 1 → 2 → 3 → 4 → 5 → 6. Danach unabhängig voneinander: 7 → 8 (Backend) und 9 (Updater) und 10 (Starter). 11 nach 10, 12 nach allem und nur mit Freigabe.
