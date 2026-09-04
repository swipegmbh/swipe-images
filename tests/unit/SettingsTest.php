<?php
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	public function test_defaults(): void {
		$d = Swipe_Images_Settings::defaults();
		$this->assertSame( true, $d['enabled'] );
		$this->assertSame( 'webp', $d['format'] );
		$this->assertSame( true, $d['convert_png'] );
		$this->assertSame( 82, $d['quality_webp'] );
		$this->assertSame( 65, $d['quality_avif'] );
		$this->assertSame( 2560, $d['big_image_threshold'] );
		$this->assertSame( 2560, $d['max_srcset_width'] );
		$this->assertSame( true, $d['auto_update'] );
	}

	public function test_sanitize_auto_update_abwaehlbar(): void {
		$s = Swipe_Images_Settings::sanitize( array( 'auto_update' => '0' ) );
		$this->assertFalse( $s['auto_update'] );
		$this->assertTrue( Swipe_Images_Settings::sanitize( array() )['auto_update'] );
	}

	public function test_sanitize_clamps_quality_and_rejects_unknown_format(): void {
		$s = Swipe_Images_Settings::sanitize( array(
			'format'       => 'jpeg2000',
			'quality_webp' => 5,
			'quality_avif' => 500,
			'enabled'      => '1',
			'convert_png'  => '',
		) );
		$this->assertSame( 'webp', $s['format'] );
		$this->assertSame( 40, $s['quality_webp'] );
		$this->assertSame( 100, $s['quality_avif'] );
		$this->assertTrue( $s['enabled'] );
		$this->assertFalse( $s['convert_png'] );
	}

	public function test_sanitize_fills_missing_keys_with_defaults(): void {
		$s = Swipe_Images_Settings::sanitize( array( 'format' => 'avif' ) );
		$this->assertSame( 'avif', $s['format'] );
		$this->assertSame( 82, $s['quality_webp'] );
		$this->assertSame( 2560, $s['big_image_threshold'] );
	}

	public function test_sanitize_widths_never_negative(): void {
		$s = Swipe_Images_Settings::sanitize( array( 'big_image_threshold' => -1, 'max_srcset_width' => '0' ) );
		$this->assertSame( 0, $s['big_image_threshold'] );
		$this->assertSame( 0, $s['max_srcset_width'] );
	}

	public function test_sanitize_non_array_returns_defaults(): void {
		$this->assertSame( Swipe_Images_Settings::defaults(), Swipe_Images_Settings::sanitize( 'nope' ) );
	}
}
