<?php
/**
 * Erkennung: Theme mit altem Bildcode, Server-Fähigkeiten, fremde Quality-Filter.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Detector {

	/**
	 * GD-Spitzenbedarf beim Upload, gemessen am echten Fluss (media_handle_sideload, WP 7.1, PHP 8.3, bundled GD,
	 * Schwelle 2560, OOM-Grenze durch gestaffelte memory_limits bestätigt): 800×600-PNG 8 MB, 6-MP-JPEG 67 MB,
	 * 12-MP-JPEG 93,5 MB, 24,8-MP-PNG 188 MB; srv01 48-MP-JPEG 231 MB. Zwei Anteile:
	 *  - Quelle als Truecolor, 4 B/px; PNG braucht beim Dekodieren zusätzlich einen libpng-Vollpuffer (~7 B/px).
	 *  - Arbeitssatz in Grösse der skalierten Kopie, dreifach: _wp_make_subsizes() lädt das Original ein zweites
	 *    Mal, während der erste Editor die skalierte Kopie hält, dazu die grösste Sub-Size und der WebP-Encoder.
	 * 8 + 12 B/px liegen 24 % (grosse PNG) bis 80 % (JPEG) über den Messwerten.
	 */
	const GD_SOURCE_BYTES_PER_PIXEL  = 8;
	const GD_WORKSET_BYTES_PER_PIXEL = 12;

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

	/**
	 * wp_image_editor_supports() fragt nur, ob die Editor-Klasse den Mime *behauptet* zu können.
	 * Auf manchen Servern (z. B. PHP 8.4, GD und Imagick melden beide AVIF) stimmt die Behauptung,
	 * der Encode liefert aber eine 0-Byte-Datei. Für webp/avif zählt deshalb zusätzlich die echte
	 * Encode-Probe aus can_encode(); für alle anderen Mimes bleibt das Verhalten unverändert.
	 */
	public static function editor_supports( string $mime ): bool {
		static $cache = array();
		if ( ! isset( $cache[ $mime ] ) ) {
			$claims = (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
			if ( $claims && in_array( $mime, array( 'image/webp', 'image/avif' ), true ) ) {
				$claims = self::can_encode( $mime );
			}
			$cache[ $mime ] = $claims;
		}
		return $cache[ $mime ];
	}

	/**
	 * True nur, wenn der Save kein WP_Error ist und die Datei mehr als 32 Byte hat. Reine,
	 * isoliert testbare Entscheidungslogik von can_encode() – dort steckt das I/O.
	 *
	 * @param mixed    $saved Rückgabe von WP_Image_Editor::save().
	 * @param int|null $bytes filesize() der geschriebenen Datei, null wenn sie fehlt.
	 */
	public static function probe_result_ok( $saved, ?int $bytes ): bool {
		if ( is_wp_error( $saved ) ) {
			return false;
		}
		return null !== $bytes && $bytes > 32;
	}

	/**
	 * Echter Encode-Test: kodiert ein winziges Bild zum Zielmime und prüft, ob das Ergebnis
	 * eine nichtleere Datei ist. Nur für image/webp und image/avif, alles andere liefert false.
	 *
	 * Läuft nie im Frontend (kein Encode-Aufwand in einem Seiten-Request): dort wird ein
	 * vorhandener Cache-Wert genommen, sonst ungeprobt auf wp_image_editor_supports() zurückgefallen.
	 * In Admin/WP-CLI wird 1× je Woche geprobt und in einem Transient gecacht (Filter
	 * `swipe_images_encode_probe_ttl`), zusätzlich statisch je Request. Der Transient wird nur bei
	 * manueller Aktivierung gelöscht (Swipe_Images_Activator::activate()); ein Update reaktiviert still,
	 * das neue Urteil fällt erst im ersten Admin- oder WP-CLI-Request nach Ablauf des alten Transients.
	 */
	public static function can_encode( string $mime ): bool {
		static $request_cache = array();
		if ( ! in_array( $mime, array( 'image/avif', 'image/webp' ), true ) ) {
			return false;
		}
		if ( array_key_exists( $mime, $request_cache ) ) {
			return $request_cache[ $mime ];
		}

		$ext           = 'image/avif' === $mime ? 'avif' : 'webp';
		$transient_key = 'swipe_images_can_encode_' . $ext;
		$transient     = get_transient( $transient_key );
		$is_cli        = defined( 'WP_CLI' ) && WP_CLI;

		if ( ! is_admin() && ! $is_cli ) {
			$result = false !== $transient ? (bool) $transient : (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
			$request_cache[ $mime ] = $result;
			return $result;
		}

		if ( false !== $transient ) {
			$request_cache[ $mime ] = (bool) $transient;
			return $request_cache[ $mime ];
		}

		try {
			$result = self::probe_encode( $mime );
			$ttl    = (int) apply_filters( 'swipe_images_encode_probe_ttl', WEEK_IN_SECONDS, $mime );
		} catch ( \Throwable $e ) {
			// Eine Fähigkeits-Probe darf den Bootstrap nie mitreissen (egloffwoerwag.ch, 1.0.2: fataler
			// Fehler in after_setup_theme). Alte Behauptung als Fallback, kurze TTL statt einer Woche,
			// damit ein einmaliger Ausrutscher rasch erneut probt wird statt sich festzusetzen.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'swipe-images: Encode-Probe fuer ' . $mime . ' fehlgeschlagen: ' . $e->getMessage() );
			}
			$result = (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
			$ttl    = HOUR_IN_SECONDS;
		}
		set_transient( $transient_key, $result, $ttl );

		$request_cache[ $mime ] = $result;
		return $result;
	}

	/**
	 * Steuert der Encoder für $mime die Qualität wirklich? srv02.swipe.ch (Imagick 6.9) ignoriert den
	 * WebP-Wert komplett: 30 und 90 liefern dieselbe Dateigrösse, der Regler im Backend ist dort eine
	 * Attrappe. Kodiert deshalb zweimal (30 und 90) und vergleicht die Bytes, siehe sizes_show_quality().
	 *
	 * Dieselbe Vorsicht wie can_encode(): kein Encode im Frontend (Cache oder true), Transient
	 * `swipe_images_quality_honoured_<ext>` eine Woche (Filter `swipe_images_encode_probe_ttl`), bei
	 * einem Throwable true mit einer Stunde TTL. Gespeichert wird 0/1, weil ein false-Transient von
	 * einem fehlenden nicht zu unterscheiden ist und hier gerade die negative Antwort zählt.
	 *
	 * @param callable|null $probe fn( string $mime ): array{0:?int,1:?int} – Bytes derselben Quelle bei
	 *                             Qualität 30 und 90, null wenn nicht messbar. Default probe_pair();
	 *                             injizierbar für Tests.
	 */
	public static function quality_is_honoured( string $mime, ?callable $probe = null ): bool {
		$ext           = 'image/avif' === $mime ? 'avif' : 'webp';
		$transient_key = 'swipe_images_quality_honoured_' . $ext;
		$transient     = get_transient( $transient_key );
		if ( false !== $transient ) {
			return (bool) $transient;
		}
		if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}

		$probe = $probe ?? static fn( string $m ): array => self::probe_pair( $m );
		try {
			list( $low, $high ) = $probe( $mime );
			$result             = self::sizes_show_quality( $low, $high );
			$ttl                = (int) apply_filters( 'swipe_images_encode_probe_ttl', WEEK_IN_SECONDS, $mime );
		} catch ( \Throwable $e ) {
			// Wie can_encode(): eine Probe darf den Bootstrap nie mitreissen. Ohne Messung keine Behauptung.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'swipe-images: Qualitaets-Probe fuer ' . $mime . ' fehlgeschlagen: ' . $e->getMessage() );
			}
			$result = true;
			$ttl    = HOUR_IN_SECONDS;
		}
		set_transient( $transient_key, $result ? 1 : 0, $ttl );
		return $result;
	}

	/**
	 * Reine Entscheidungslogik der Qualitätsprobe: die Datei bei Qualität 90 muss mindestens 10 %
	 * grösser sein als die bei 30. Fehlt eine Messung (null), gilt der Encoder als gehorsam – wir
	 * behaupten keinen Defekt, den wir nicht gemessen haben.
	 */
	public static function sizes_show_quality( ?int $low, ?int $high ): bool {
		if ( null === $low || null === $high ) {
			return true;
		}
		return $high * 10 >= $low * 11;
	}

	/**
	 * GD kann $mime schreiben – unabhängig davon, welchen Editor WP gerade wählt.
	 *
	 * @param callable|null $exists Prüffunktion, Default function_exists; injizierbar für Tests.
	 */
	public static function gd_can_encode( string $mime, ?callable $exists = null ): bool {
		$exists = $exists ?? 'function_exists';
		return (bool) $exists( 'image/avif' === $mime ? 'imageavif' : 'imagewebp' );
	}

	/**
	 * Urteil zur Qualitätssteuerung für $mime, eine Stelle für Boot, Statuskasten, Site Health und CLI:
	 * 'ok' – der Editor gehorcht; 'gd' – der Standard-Editor ignoriert den Wert, GD kann das Format und
	 * übernimmt (prefer_gd) für jedes Bild, das in den PHP-Speicher passt; 'declined' – GD könnte, die Site
	 * hat es per Filter abgewählt; 'ignored' – ignoriert, und GD kann das Format auch nicht. In den beiden
	 * letzten Fällen wirkt der Regler nicht.
	 *
	 * GD ändert mehr als den Regler: es hält das Bild als Truecolor im PHP-Speicher (siehe gd_bytes_needed()),
	 * Imagick nicht. Zu grosse Bilder bleiben deshalb beim Standard-Editor (Swipe_Images_Editor_GD::test()).
	 * Ausserdem verwirft GD ICC-Profile (P3-Fotos entsättigen leicht); eine Site, der das wichtiger ist als
	 * der Regler, steigt mit `add_filter( 'swipe_images_prefer_gd', '__return_false' )` ganz aus.
	 *
	 * @param callable|null $exists Durchgereicht an gd_can_encode(), injizierbar für Tests.
	 */
	public static function quality_verdict( string $mime, ?callable $exists = null ): string {
		if ( self::quality_is_honoured( $mime ) ) {
			return 'ok';
		}
		if ( ! self::gd_can_encode( $mime, $exists ) ) {
			return 'ignored';
		}
		return apply_filters( 'swipe_images_prefer_gd', true, $mime ) ? 'gd' : 'declined';
	}

	/**
	 * Callback für wp_image_editors, registriert wenn quality_verdict() 'gd' sagt: der speicherwachende GD-Editor
	 * nach vorn, die Core-Liste bleibt dahinter. Passt ein Bild nicht (Swipe_Images_Editor_GD::test()), fällt
	 * Core auf den nächsten Eintrag zurück – Imagick, oder ohne Imagick der Core-GD wie ohne Plugin.
	 *
	 * Die Unterklasse braucht WP_Image_Editor_GD, das Core erst in _wp_image_editor_choose() lädt; ruft ein
	 * Dritter den Filter davor auf, bleibt die Liste unverändert statt in einen Fatal zu laufen.
	 */
	public static function prefer_gd( $editors ): array {
		if ( ! class_exists( 'WP_Image_Editor_GD', false ) ) {
			return (array) $editors;
		}
		require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-editor-gd.php';
		$editors = array_diff( (array) $editors, array( 'Swipe_Images_Editor_GD' ) );
		array_unshift( $editors, 'Swipe_Images_Editor_GD' );
		return $editors;
	}

	/** Freier PHP-Speicher in Bytes für den laufenden Prozess; PHP_INT_MAX bei memory_limit -1. */
	public static function memory_budget(): int {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( -1 === $limit ) {
			return PHP_INT_MAX;
		}
		return max( 0, $limit - memory_get_usage( true ) );
	}

	/**
	 * Geschätzter GD-Spitzenbedarf in Bytes für $pixels Pixel bei big_image_size_threshold $threshold (0 = aus).
	 * Die skalierte Kopie hat höchstens $threshold als lange Seite; 3/4 des Quadrats deckt 4:3, 3:2 und 16:9, ein
	 * Quadrat über der Schwelle frisst davon ein Viertel der Reserve. Rein.
	 */
	public static function gd_bytes_needed( int $pixels, int $threshold ): int {
		$scaled = $threshold > 0 ? min( $pixels, (int) ( $threshold * $threshold * 0.75 ) ) : $pixels;
		return $pixels * self::GD_SOURCE_BYTES_PER_PIXEL + $scaled * self::GD_WORKSET_BYTES_PER_PIXEL;
	}

	/** Umkehrung von gd_bytes_needed(): das grösste Bild in Pixeln, das in $budget Bytes passt. Rein. */
	public static function gd_pixel_ceiling( int $budget, int $threshold ): int {
		$per_px     = self::GD_SOURCE_BYTES_PER_PIXEL + self::GD_WORKSET_BYTES_PER_PIXEL;
		$scaled_max = (int) ( $threshold * $threshold * 0.75 );
		if ( $threshold <= 0 || $budget <= $scaled_max * $per_px ) {
			return intdiv( $budget, $per_px );
		}
		return intdiv( $budget - $scaled_max * self::GD_WORKSET_BYTES_PER_PIXEL, self::GD_SOURCE_BYTES_PER_PIXEL );
	}

	/**
	 * Passt ein Bild mit $pixels Pixeln in $budget Bytes GD-Speicher? Rein. Eine unbekannte Grösse (null) gilt
	 * nur bei unbegrenztem Speicher als passend – im Zweifel bleibt der Standard-Editor.
	 */
	public static function gd_fits( ?int $pixels, int $budget, int $threshold = 2560 ): bool {
		if ( PHP_INT_MAX === $budget ) {
			return true;
		}
		return null !== $pixels && self::gd_bytes_needed( $pixels, $threshold ) <= $budget;
	}

	/**
	 * gd_fits() für eine Datei: Masse aus dem Header, Budget nach wp_raise_memory_limit( 'image' ) – also
	 * gegen das Limit, das WP_Image_Editor_GD::load() gleich darauf ohnehin setzt (WP_MAX_MEMORY_LIMIT).
	 *
	 * @param int|null $budget Bytes; null = aus dem Prozess lesen. Injizierbar für Tests.
	 */
	public static function gd_fits_file( string $path, ?int $budget = null ): bool {
		$pixels = null;
		if ( '' !== $path && is_file( $path ) ) {
			$size = wp_getimagesize( $path );
			if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
				$pixels = (int) $size[0] * (int) $size[1];
			}
		}
		if ( null === $budget ) {
			wp_raise_memory_limit( 'image' );
			$budget = self::memory_budget();
		}
		return self::gd_fits( $pixels, $budget, (int) Swipe_Images_Settings::get()['big_image_threshold'] );
	}

	/**
	 * Nachsatz zum Urteil 'gd' für Statuskasten und CLI: bis zu welcher Bildgrösse GD im laufenden Prozess
	 * übernimmt. Im Backend läuft das im selben FPM-Pool wie ein Upload, auf der CLI gilt es für den Regenerator.
	 */
	public static function gd_memory_note(): string {
		wp_raise_memory_limit( 'image' );
		$budget = self::memory_budget();
		if ( PHP_INT_MAX === $budget ) {
			return 'ohne Speichergrenze in diesem Prozess (memory_limit -1); Uploads über den Webserver rechnen mit dessen eigenem Limit';
		}
		$ceiling = self::gd_pixel_ceiling( $budget, (int) Swipe_Images_Settings::get()['big_image_threshold'] );
		return sprintf(
			'für Bilder bis etwa %s Megapixel (%s freier PHP-Speicher); grössere Bilder gehen weiter an den Standard-Editor, dort wirkt der Regler nicht',
			number_format_i18n( $ceiling / 1000000, 1 ),
			size_format( $budget )
		);
	}

	/**
	 * Laedt die admin-only File-API bei Bedarf nach. wp_tempnam() lebt in
	 * wp-admin/includes/file.php, das WordPress nur im Admin/AJAX/wp-admin-Bootstrap automatisch
	 * einbindet. Der Encode-Probe-Pfad laeuft aber auch ueber WP-CLI und ueber den Hook
	 * after_setup_theme (boot() bei Prioritaet 100) - beides vor jedem Admin-Bootstrap. Ohne diesen
	 * Nachlade-Schritt ist wp_tempnam() dort eine undefinierte Funktion (Fatal Error, egloffwoerwag.ch
	 * mit 1.0.2).
	 */
	private static function ensure_file_api(): void {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	/** Kodiert eine kleine Quelldatei probeweise zu $mime, räumt alle Temp-Dateien danach auf. */
	private static function probe_encode( string $mime ): bool {
		self::ensure_file_api();

		$tmp_files = array();
		$source    = self::probe_source_file( $tmp_files );
		if ( '' === $source ) {
			// Weder GD noch ein vorhandenes Upload-Bild verfügbar: alte Behauptung als Fallback.
			return (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
		}

		$ok = null !== self::probe_save( $source, $mime, 60, $tmp_files );
		self::delete_files( $tmp_files );
		return $ok;
	}

	/**
	 * Standard-Probe von quality_is_honoured(): Bytes derselben Quelle bei Qualität 30 und 90, null wo
	 * nicht messbar. Eine Quelle für beide Encodes ist Pflicht: frisches Rauschen je Encode streut bei
	 * fester Qualität um bis zu 8 % (Review 1.0.4, 300 Bilder) und frisst die 10-Prozent-Schwelle fast auf.
	 * Misst den Editor, den WP ohne den eigenen GD-Vortritt wählt – sonst würde eine Re-Probe bei
	 * aktivem prefer_gd GD messen, «gehorcht» cachen und den Vortritt beim nächsten Boot abschalten.
	 *
	 * @return array{0:?int,1:?int}
	 */
	private static function probe_pair( string $mime ): array {
		self::ensure_file_api();

		$prefer_gd = array( __CLASS__, 'prefer_gd' );
		$had_gd    = remove_filter( 'wp_image_editors', $prefer_gd );
		$tmp_files = array();
		try {
			$source = self::probe_source_file( $tmp_files );
			if ( '' === $source ) {
				return array( null, null );
			}
			return array(
				self::probe_save( $source, $mime, 30, $tmp_files ),
				self::probe_save( $source, $mime, 90, $tmp_files ),
			);
		} finally {
			self::delete_files( $tmp_files );
			if ( $had_gd ) {
				add_filter( 'wp_image_editors', $prefer_gd );
			}
		}
	}

	/**
	 * Speichert $source einmal als $mime mit $quality und liefert die Bytes der Zieldatei – null, wenn
	 * kein Editor, save() ein WP_Error, das Ergebnis ein anderes Mime oder die Datei (fast) leer ist.
	 * Neue Dateien landen in $tmp_files, der Aufrufer räumt auf.
	 */
	private static function probe_save( string $source, string $mime, int $quality, array &$tmp_files ): ?int {
		$ext         = 'image/avif' === $mime ? 'avif' : 'webp';
		$target      = wp_tempnam( 'swipe-images-probe.' . $ext );
		$tmp_files[] = $target;

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return null;
		}
		// WP_Image_Editor::get_output_format() ruft beim Mime-Wechsel set_quality() ohne Argument auf;
		// der eigene wp_editor_set_quality-Filter (Priorität 999) setzte $quality damit auf die
		// Einstellung zurück, und die Qualitätsprobe misste zweimal dasselbe. Wie in
		// Swipe_Images_Admin::ajax_preview(): kurzlebiger Filter auf 1000 hält $quality für diesen save().
		$pin_quality = static fn() => $quality;
		add_filter( 'wp_editor_set_quality', $pin_quality, 1000 );
		try {
			$editor->set_quality( $quality );
			$saved = $editor->save( $target, $mime );
		} finally {
			remove_filter( 'wp_editor_set_quality', $pin_quality, 1000 );
		}

		$path = ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) ? $saved['path'] : $target;
		if ( $path !== $target ) {
			$tmp_files[] = $path;
		}
		$bytes = file_exists( $path ) ? filesize( $path ) : null;
		// get_output_format() weicht bei fehlender Unterstuetzung still auf ein anderes Mime aus (lokal
		// faellt GD von AVIF auf JPEG zurueck) - dann steht zwar eine gueltige, nichtleere Datei da,
		// aber nicht im gefragten Format. Zaehlt nicht.
		$mime_ok = ! is_wp_error( $saved ) && isset( $saved['mime-type'] ) && $saved['mime-type'] === $mime;
		return ( $mime_ok && self::probe_result_ok( $saved, $bytes ) ) ? (int) $bytes : null;
	}

	/** @param string[] $files Temp-Dateien der Proben; fehlende werden übersprungen. */
	private static function delete_files( array $files ): void {
		foreach ( array_unique( $files ) as $f ) {
			if ( file_exists( $f ) ) {
				wp_delete_file( $f );
			}
		}
	}

	/**
	 * Quelldatei für die Proben: bevorzugt ein frisches 32×32-Rausch-JPEG via GD, sonst ein
	 * vorhandenes Upload-Bild. Neu erzeugte Dateien werden in $tmp_files nachgetragen, damit
	 * der Aufrufer sie aufräumt; das vorhandene Upload-Bild bleibt unangetastet.
	 *
	 * @param string[] $tmp_files Referenz, wird um neu erzeugte Temp-Dateien ergänzt.
	 */
	private static function probe_source_file( array &$tmp_files ): string {
		self::ensure_file_api();

		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) ) {
			$src = wp_tempnam( 'swipe-images-probe-source.jpg' );
			// Rauschen statt Fläche: eine graue Fläche kodiert bei jeder Qualität gleich klein (8×8 grau:
			// 44 Byte bei 30 wie bei 90), die Qualitätsprobe braucht Nutzlast. 32×32 Rauschen liefert mit
			// GD 574 Byte bei 30 und 1120 bei 90.
			$im = imagecreatetruecolor( 32, 32 );
			for ( $y = 0; $y < 32; $y++ ) {
				for ( $x = 0; $x < 32; $x++ ) {
					imagesetpixel( $im, $x, $y, imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
				}
			}
			$written = imagejpeg( $im, $src, 92 );
			imagedestroy( $im );
			$tmp_files[] = $src;
			if ( $written && file_exists( $src ) && filesize( $src ) > 0 ) {
				return $src;
			}
		}

		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		if ( $existing ) {
			$file = get_attached_file( (int) $existing[0] );
			if ( $file && file_exists( $file ) ) {
				return $file;
			}
		}

		return '';
	}

	/** @return array{gd:array{webp:bool,avif:bool},imagick:array{webp:bool,avif:bool},editor:array{webp:bool,avif:bool},encode:array{webp:bool,avif:bool}} */
	public static function capabilities(): array {
		$imagick = array( 'webp' => false, 'avif' => false );
		// energieuster.ch: das Theme bündelt calcinai/php-imagick. Die Klasse existiert, queryFormats() ist dort
		// Instanzmethode, der statische Aufruf warf einen Error und blockierte Admin und WP-CLI. Im Zweifel nein.
		if ( class_exists( 'Imagick' ) && method_exists( 'Imagick', 'queryFormats' ) ) {
			try {
				$formats = array_map( 'strtoupper', (array) Imagick::queryFormats() );
				$imagick = array( 'webp' => in_array( 'WEBP', $formats, true ), 'avif' => in_array( 'AVIF', $formats, true ) );
			} catch ( \Throwable $e ) {
				$imagick = array( 'webp' => false, 'avif' => false );
			}
		}
		return array(
			'gd'      => array( 'webp' => function_exists( 'imagewebp' ), 'avif' => function_exists( 'imageavif' ) ),
			'imagick' => $imagick,
			'editor'  => array( 'webp' => self::editor_supports( 'image/webp' ), 'avif' => self::editor_supports( 'image/avif' ) ),
			'encode'  => array( 'webp' => self::can_encode( 'image/webp' ), 'avif' => self::can_encode( 'image/avif' ) ),
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
