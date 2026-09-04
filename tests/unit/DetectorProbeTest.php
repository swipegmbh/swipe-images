<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Deckt nur die reine Entscheidungslogik der Encode-Probe ab (probe_result_ok). can_encode()
 * selbst ist I/O (Datei schreiben, WP-Editor) und dafür Sache des Integrationslaufs.
 */
class DetectorProbeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_wp_error_ist_nie_ok(): void {
		$error = new WP_Error( 'x', 'egal' );
		$this->assertFalse( Swipe_Images_Detector::probe_result_ok( $error, 5000 ) );
		$this->assertFalse( Swipe_Images_Detector::probe_result_ok( $error, null ) );
	}

	public function test_null_bytes_ist_nicht_ok(): void {
		$this->assertFalse( Swipe_Images_Detector::probe_result_ok( array( 'path' => '/tmp/x' ), null ) );
	}

	public function test_null_byte_datei_ist_nicht_ok(): void {
		$this->assertFalse( Swipe_Images_Detector::probe_result_ok( array( 'path' => '/tmp/x' ), 0 ) );
	}

	public function test_genau_32_byte_ist_nicht_ok(): void {
		$this->assertFalse( Swipe_Images_Detector::probe_result_ok( array( 'path' => '/tmp/x' ), 32 ) );
	}

	public function test_ueber_32_byte_ist_ok(): void {
		$this->assertTrue( Swipe_Images_Detector::probe_result_ok( array( 'path' => '/tmp/x' ), 33 ) );
		$this->assertTrue( Swipe_Images_Detector::probe_result_ok( array( 'path' => '/tmp/x' ), 5000 ) );
	}
}
