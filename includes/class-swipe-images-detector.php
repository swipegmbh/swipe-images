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
	 * `swipe_images_encode_probe_ttl`), zusätzlich statisch je Request. Der Transient wird beim
	 * Aktivieren gelöscht (Swipe_Images_Activator::activate()), ein Plugin-Update re-probt also.
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

		$result = self::probe_encode( $mime );
		$ttl    = (int) apply_filters( 'swipe_images_encode_probe_ttl', WEEK_IN_SECONDS, $mime );
		set_transient( $transient_key, $result, $ttl );

		$request_cache[ $mime ] = $result;
		return $result;
	}

	/** Kodiert eine kleine Quelldatei probeweise zu $mime, räumt alle Temp-Dateien danach auf. */
	private static function probe_encode( string $mime ): bool {
		$tmp_files = array();
		$source    = self::probe_source_file( $tmp_files );
		if ( '' === $source ) {
			// Weder GD noch ein vorhandenes Upload-Bild verfügbar: alte Behauptung als Fallback.
			return (bool) wp_image_editor_supports( array( 'mime_type' => $mime ) );
		}

		$ext    = 'image/avif' === $mime ? 'avif' : 'webp';
		$target = wp_tempnam( 'swipe-images-probe.' . $ext );
		$ok     = false;

		$editor = wp_get_image_editor( $source );
		if ( ! is_wp_error( $editor ) ) {
			$editor->set_quality( 60 );
			$saved = $editor->save( $target, $mime );
			$path  = ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) ? $saved['path'] : $target;
			$bytes = file_exists( $path ) ? filesize( $path ) : null;
			// WP_Image_Editor::get_output_format() weicht bei fehlender Unterstuetzung still auf
			// ein anderes Mime aus (lokal faellt GD von AVIF auf JPEG zurueck) - dann steht zwar
			// eine gueltige, nichtleere Datei da, aber nicht im gefragten Format. Zaehlt nicht.
			$mime_ok = ! is_wp_error( $saved ) && isset( $saved['mime-type'] ) && $saved['mime-type'] === $mime;
			$ok      = $mime_ok && self::probe_result_ok( $saved, $bytes );
			if ( $path !== $target ) {
				$tmp_files[] = $path;
			}
		}

		$tmp_files[] = $target;
		foreach ( array_unique( $tmp_files ) as $f ) {
			if ( file_exists( $f ) ) {
				wp_delete_file( $f );
			}
		}

		return $ok;
	}

	/**
	 * Quelldatei für die Encode-Probe: bevorzugt ein frisches 8×8-JPEG via GD, sonst ein
	 * vorhandenes Upload-Bild. Neu erzeugte Dateien werden in $tmp_files nachgetragen, damit
	 * der Aufrufer sie aufräumt; das vorhandene Upload-Bild bleibt unangetastet.
	 *
	 * @param string[] $tmp_files Referenz, wird um neu erzeugte Temp-Dateien ergänzt.
	 */
	private static function probe_source_file( array &$tmp_files ): string {
		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) ) {
			$src = wp_tempnam( 'swipe-images-probe-source.jpg' );
			$im  = imagecreatetruecolor( 8, 8 );
			imagefilledrectangle( $im, 0, 0, 7, 7, imagecolorallocate( $im, 120, 120, 120 ) );
			$written = imagejpeg( $im, $src );
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
		if ( class_exists( 'Imagick' ) ) {
			$formats = array_map( 'strtoupper', (array) Imagick::queryFormats() );
			$imagick = array( 'webp' => in_array( 'WEBP', $formats, true ), 'avif' => in_array( 'AVIF', $formats, true ) );
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
