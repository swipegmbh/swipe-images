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
