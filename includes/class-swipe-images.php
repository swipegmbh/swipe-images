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
		foreach ( array( 'loader', 'i18n', 'settings', 'converter', 'detector', 'regenerator', 'updater' ) as $part ) {
			require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-' . $part . '.php';
		}
		require_once SWIPE_IMAGES_PATH . 'admin/class-swipe-images-admin.php';
	}

	public function run(): void {
		add_action( 'after_setup_theme', array( $this, 'boot' ), 100 );
		$updater = new Swipe_Images_Updater();
		add_filter( 'update_plugins_github.com', array( $updater, 'check' ), 10, 4 );
		// Nicht modusabhaengig registriert, muss auch im Cron greifen (WP-Cron laeuft ohne Theme-Boot).
		add_filter( 'auto_update_plugin', array( $updater, 'maybe_auto_update' ), 10, 2 );
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
			add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'log_unconverted' ), 99, 2 );
		}

		$admin = new Swipe_Images_Admin( $this->plugin_name, $this->version );
		$this->loader->add_action( 'admin_notices', $admin, 'notice_blocked' );
		$this->loader->add_filter( 'site_status_tests', $admin, 'site_health_tests' );
		$this->loader->add_action( 'admin_init', $admin, 'register_settings' );
		$this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue' );
		$this->loader->add_action( 'wp_ajax_swipe_images_preview', $admin, 'ajax_preview' );
		$this->loader->add_action( 'wp_ajax_swipe_images_regenerate', $admin, 'ajax_regenerate' );

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
		$avif      = Swipe_Images_Detector::editor_supports( 'image/avif' );
		$converter = new Swipe_Images_Converter( $settings, $avif );
		add_filter( 'image_editor_output_format', array( $converter, 'filter_output_format' ), 10, 3 );
		add_filter( 'wp_editor_set_quality', array( $converter, 'filter_quality' ), 999, 2 );
		add_filter( 'big_image_size_threshold', array( $converter, 'filter_threshold' ), 10, 1 );
		add_filter( 'max_srcset_image_width', array( $converter, 'filter_max_srcset' ), 10, 1 );
		add_filter( 'wp_get_attachment_metadata', array( $converter, 'sanitize_metadata' ), 5, 2 );
		// srv02 (Imagick 6.9) ignoriert den WebP-Qualitätswert. Kann GD das Zielformat, bekommt GD den
		// Vortritt; kann es GD auch nicht, melden Statuskasten, Site Health und CLI das (quality_verdict()).
		if ( 'gd' === Swipe_Images_Detector::quality_verdict( Swipe_Images_Converter::target_mime( $settings['format'], $avif ) ) ) {
			add_filter( 'wp_image_editors', array( 'Swipe_Images_Detector', 'prefer_gd' ) );
		}
		$done = true;
		return true;
	}

	/**
	 * Spec §12: Fällt der Editor beim Upload auf das Quellformat zurück, bleibt das sonst
	 * unsichtbar. Bei WP_DEBUG wandert eine Zeile ins Log; die Metadaten bleiben unverändert.
	 *
	 * @param array $metadata      Metadaten aus wp_create_image_subsizes().
	 * @param int   $attachment_id Attachment-ID.
	 * @return array Unverändert.
	 */
	public static function log_unconverted( $metadata, $attachment_id ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || empty( $metadata['file'] ) ) {
			return $metadata;
		}
		$expects = Swipe_Images_Converter::expects_conversion(
			(string) get_post_mime_type( $attachment_id ),
			Swipe_Images_Settings::get(),
			Swipe_Images_Detector::editor_supports( 'image/avif' )
		);
		if ( $expects && ! Swipe_Images_Regenerator::is_target_file( (string) $metadata['file'] ) ) {
			error_log( sprintf( 'swipe-images: Attachment %d wurde nicht konvertiert, Datei bleibt %s.', (int) $attachment_id, $metadata['file'] ) );
		}
		return $metadata;
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
