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
		foreach ( array( 'loader', 'i18n', 'settings', 'converter', 'detector', 'regenerator' ) as $part ) {
			require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-' . $part . '.php';
		}
		require_once SWIPE_IMAGES_PATH . 'admin/class-swipe-images-admin.php';
	}

	public function run(): void {
		add_action( 'after_setup_theme', array( $this, 'boot' ), 100 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-cli.php';
			WP_CLI::add_command( 'swipe-images', 'Swipe_Images_CLI' );
		}
	}

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
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue' );

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
