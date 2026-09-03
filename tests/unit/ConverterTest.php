<?php
use PHPUnit\Framework\TestCase;

class ConverterTest extends TestCase {

	public function test_target_mime_avif_only_when_supported(): void {
		$this->assertSame( 'image/webp', Swipe_Images_Converter::target_mime( 'webp', true ) );
		$this->assertSame( 'image/avif', Swipe_Images_Converter::target_mime( 'avif', true ) );
		$this->assertSame( 'image/webp', Swipe_Images_Converter::target_mime( 'avif', false ) );
	}

	public function test_output_format_maps_jpeg_and_optionally_png(): void {
		$m = Swipe_Images_Converter::output_format( array(), 'webp', true, false );
		$this->assertSame( 'image/webp', $m['image/jpeg'] );
		$this->assertSame( 'image/webp', $m['image/png'] );

		$m = Swipe_Images_Converter::output_format( array(), 'webp', false, false );
		$this->assertArrayNotHasKey( 'image/png', $m );
	}

	public function test_output_format_keeps_existing_mappings_and_never_touches_gif(): void {
		$m = Swipe_Images_Converter::output_format( array( 'image/heic' => 'image/jpeg' ), 'webp', true, false );
		$this->assertSame( 'image/jpeg', $m['image/heic'] );
		$this->assertArrayNotHasKey( 'image/gif', $m );
	}

	public function test_quality_uses_setting_for_target_mimes_only(): void {
		$s = array( 'quality_webp' => 70, 'quality_avif' => 50 );
		$this->assertSame( 70, Swipe_Images_Converter::quality( 100, 'image/webp', $s ) );
		$this->assertSame( 50, Swipe_Images_Converter::quality( 100, 'image/avif', $s ) );
		$this->assertSame( 82, Swipe_Images_Converter::quality( 82, 'image/jpeg', $s ) );
	}

	public function test_sanitize_metadata_drops_sizes_without_numeric_dimensions(): void {
		$c    = new Swipe_Images_Converter( Swipe_Images_Settings::defaults(), false );
		$meta = array(
			'sizes' => array(
				'ok'     => array( 'file' => 'a.webp', 'width' => 10, 'height' => 5 ),
				'kaputt' => array( 'file' => 'b.webp' ),
				'string' => 'nope',
			),
		);
		$out = $c->sanitize_metadata( $meta, 1 );
		$this->assertSame( array( 'ok' ), array_keys( $out['sizes'] ) );
		$this->assertFalse( $c->sanitize_metadata( false, 1 ) );
	}

	public function test_filter_threshold_returns_false_when_disabled(): void {
		$on  = new Swipe_Images_Converter( array_merge( Swipe_Images_Settings::defaults(), array( 'big_image_threshold' => 2560 ) ), false );
		$off = new Swipe_Images_Converter( array_merge( Swipe_Images_Settings::defaults(), array( 'big_image_threshold' => 0 ) ), false );
		$this->assertSame( 2560, $on->filter_threshold( 2560 ) );
		$this->assertSame( 2560, $on->filter_threshold( 1800 ) );
		$this->assertFalse( $off->filter_threshold( 2560 ) );
	}

	public function test_filter_max_srcset_only_raises(): void {
		$c = new Swipe_Images_Converter( array_merge( Swipe_Images_Settings::defaults(), array( 'max_srcset_width' => 2560 ) ), false );
		$this->assertSame( 2560, $c->filter_max_srcset( 2048 ) );
		$this->assertSame( 4000, $c->filter_max_srcset( 4000 ) );
		$zero = new Swipe_Images_Converter( array_merge( Swipe_Images_Settings::defaults(), array( 'max_srcset_width' => 0 ) ), false );
		$this->assertSame( 2048, $zero->filter_max_srcset( 2048 ) );
	}
}
