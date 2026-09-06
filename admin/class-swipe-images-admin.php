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
		$mime    = Swipe_Images_Converter::target_mime( $format, ! empty( $caps['editor']['avif'] ) );
		$verdict = Swipe_Images_Detector::quality_verdict( $mime );
		if ( 'ignored' === $verdict || 'declined' === $verdict ) {
			$base['status']      = 'recommended';
			$base['label']       = sprintf( 'swipe Bilder erzeugt %s, der Qualitätsregler wirkt nicht', strtoupper( $format ) );
			$base['description'] = 'declined' === $verdict
				? '<p>Der Encoder dieses Servers ignoriert den Qualitätswert. GD könnte einspringen, ist auf dieser Site aber per Filter <code>swipe_images_prefer_gd</code> abgewählt.</p>'
				: '<p>Qualität: der Encoder dieses Servers ignoriert den Wert, GD steht nicht bereit. Der eingestellte Wert hat auf diesem Server keine Wirkung.</p>';
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
		$quality  = Swipe_Images_Detector::quality_verdict( Swipe_Images_Converter::target_mime( $settings['format'], $caps['editor']['avif'] ) );
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

		$out  = array();
		$slot = 0;
		foreach ( array( $quality - 10, $quality, $quality + 10 ) as $q ) {
			++$slot;
			$q      = max( $bounds['min'], min( $bounds['max'], $q ) );
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				wp_send_json_error( $editor->get_error_message() );
			}
			$editor->resize( 1200, 1200 );
			// WP_Image_Editor::get_output_format() ruft beim Formatwechsel intern set_quality()
			// ohne Argument auf und würde die Vorschau-Qualität mit dem eigenen wp_editor_set_quality-
			// Filter (Priorität 999) wieder auf die globale Einstellung zurücksetzen. Eigener Filter
			// mit höherer Priorität hält $q für diesen einen save()-Aufruf fest.
			$pin_quality = static fn() => $q;
			add_filter( 'wp_editor_set_quality', $pin_quality, 1000 );
			$editor->set_quality( $q );
			// Fester Slot statt Qualitätswert: drei Dateien je Benutzer und Format, die
			// jede neue Vorschau überschreibt. Der Cache-Buster in der URL hält sie frisch.
			$path  = sprintf( '%s/preview-%d-%d.%s', $dir, get_current_user_id(), $slot, $ext );
			$saved = $editor->save( $path, $mime );
			remove_filter( 'wp_editor_set_quality', $pin_quality, 1000 );
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
}
