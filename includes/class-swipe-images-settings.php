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
