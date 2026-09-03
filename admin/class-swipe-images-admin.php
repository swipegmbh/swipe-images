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
