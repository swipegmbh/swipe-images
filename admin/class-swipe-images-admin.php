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
}
