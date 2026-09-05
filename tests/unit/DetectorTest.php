<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class DetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_legacy_detection_uses_injected_checker(): void {
		$this->assertTrue( Swipe_Images_Detector::theme_has_legacy_code( fn( $fn ) => 'swipe_get_webp_url' === $fn ) );
		$this->assertFalse( Swipe_Images_Detector::theme_has_legacy_code( fn( $fn ) => false ) );
	}

	public function test_describe_callbacks_skips_own_class_and_names_the_rest(): void {
		$own = new Swipe_Images_Converter( Swipe_Images_Settings::defaults(), false );
		$callbacks = array(
			10  => array(
				'a' => array( 'function' => 'theme_quality_100', 'accepted_args' => 1 ),
				'b' => array( 'function' => function () { return 100; }, 'accepted_args' => 1 ),
			),
			999 => array(
				'c' => array( 'function' => array( $own, 'filter_quality' ), 'accepted_args' => 2 ),
			),
		);
		$out = Swipe_Images_Detector::describe_callbacks( $callbacks, 'Swipe_Images_Converter' );
		$this->assertSame( array( 'theme_quality_100 (Priorität 10)', 'Closure (Priorität 10)' ), $out );
	}

	/**
	 * Regression energieuster.ch: das Theme bündelt calcinai/php-imagick. Die Klasse Imagick existiert,
	 * queryFormats() ist dort aber Instanzmethode; der statische Aufruf in capabilities() warf einen Error
	 * und blockierte Admin und jeden wp swipe-images-Befehl. Die Fake-Klasse unten stellt das nach.
	 */
	public function test_capabilities_ueberlebt_imagick_polyfill_ohne_statisches_queryformats(): void {
		if ( extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'echte Imagick-Extension geladen, Polyfill-Fall nicht nachstellbar' );
		}
		Functions\when( 'wp_image_editor_supports' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'is_admin' )->justReturn( true );
		$caps = Swipe_Images_Detector::capabilities();
		$this->assertSame( array( 'webp' => false, 'avif' => false ), $caps['imagick'] );
	}
}

// Nachbau von calcinai/php-imagick, nur wo die Extension fehlt: queryFormats() ist Instanz-, nicht Klassenmethode.
if ( ! class_exists( 'Imagick', false ) ) {
	class Imagick {
		public function queryFormats( $pattern = '*' ): array {
			return array();
		}
	}
}
