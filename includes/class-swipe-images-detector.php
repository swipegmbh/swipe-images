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
