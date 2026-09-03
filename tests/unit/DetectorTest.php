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
}
