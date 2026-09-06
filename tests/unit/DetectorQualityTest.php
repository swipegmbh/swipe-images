<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

/**
 * Qualitätsprobe (1.0.4): srv02-Imagick 6.9 ignoriert den WebP-Qualitätswert. Die Probe wird hier
 * als Callable injiziert (Bytes derselben Quelle bei 30 und 90), das I/O bleibt Sache des Integrationslaufs.
 */
class DetectorQualityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_admin' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_gleiche_groessen_bei_30_und_90_heisst_qualitaet_ignoriert(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once()->with( 'swipe_images_quality_honoured_webp', 0, WEEK_IN_SECONDS );
		$taub = static fn( string $mime ): array => array( 4711, 4711 );
		$this->assertFalse( Swipe_Images_Detector::quality_is_honoured( 'image/webp', $taub ) );
	}

	public function test_deutlich_verschiedene_groessen_heisst_qualitaet_gehorcht(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once()->with( 'swipe_images_quality_honoured_avif', 1, WEEK_IN_SECONDS );
		$gehorsam = static fn( string $mime ): array => array( 574, 1120 );
		$this->assertTrue( Swipe_Images_Detector::quality_is_honoured( 'image/avif', $gehorsam ) );
	}

	public function test_werfender_editor_heisst_true_mit_kurzer_ttl(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once()->with( 'swipe_images_quality_honoured_webp', 1, HOUR_IN_SECONDS );
		$kaputt = static function ( string $mime ): array {
			throw new RuntimeException( 'Editor explodiert' );
		};
		$this->assertTrue( Swipe_Images_Detector::quality_is_honoured( 'image/webp', $kaputt ) );
	}

	public function test_frontend_probt_nicht_und_nimmt_true_an(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();
		$taub = static fn( string $mime ): array => array( 4711, 4711 );
		$this->assertTrue( Swipe_Images_Detector::quality_is_honoured( 'image/webp', $taub ) );
	}

	public function test_zehn_prozent_grenze(): void {
		$this->assertFalse( Swipe_Images_Detector::sizes_show_quality( 1000, 1000 ) );
		$this->assertFalse( Swipe_Images_Detector::sizes_show_quality( 1000, 1099 ) );
		$this->assertTrue( Swipe_Images_Detector::sizes_show_quality( 1000, 1100 ) );
		$this->assertFalse( Swipe_Images_Detector::sizes_show_quality( 1200, 1000 ), 'kleiner bei 90 als bei 30 ist keine Steuerung' );
		$this->assertTrue( Swipe_Images_Detector::sizes_show_quality( null, 1000 ), 'nicht messbar: keine Behauptung' );
		$this->assertTrue( Swipe_Images_Detector::sizes_show_quality( 1000, null ) );
	}

	/** Transient 0 = Probe negativ, GD kann WebP → GD soll führen. */
	public function test_verdict_gd_wenn_probe_negativ_und_gd_kann(): void {
		Functions\when( 'get_transient' )->justReturn( 0 );
		$this->assertSame( 'gd', Swipe_Images_Detector::quality_verdict( 'image/webp', static fn( $fn ) => 'imagewebp' === $fn ) );
	}

	public function test_verdict_ignored_wenn_probe_negativ_und_gd_nicht_kann(): void {
		Functions\when( 'get_transient' )->justReturn( 0 );
		$this->assertSame( 'ignored', Swipe_Images_Detector::quality_verdict( 'image/webp', static fn( $fn ) => false ) );
	}

	/** Notausgang: GD könnte, die Site hat es per Filter abgewählt. Eigener Zustand, damit die Meldung nicht «GD fehlt» behauptet. */
	public function test_verdict_declined_wenn_site_den_gd_vortritt_abwaehlt(): void {
		Functions\when( 'get_transient' )->justReturn( 0 );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => 'swipe_images_prefer_gd' === $hook ? false : $value );
		$this->assertSame( 'declined', Swipe_Images_Detector::quality_verdict( 'image/webp', static fn( $fn ) => 'imagewebp' === $fn ) );
		$this->assertSame( 'ignored', Swipe_Images_Detector::quality_verdict( 'image/webp', static fn( $fn ) => false ), 'ohne GD bleibt es ignored, egal was der Filter sagt' );
	}

	public function test_verdict_ok_wenn_probe_positiv(): void {
		Functions\when( 'get_transient' )->justReturn( 1 );
		$this->assertSame( 'ok', Swipe_Images_Detector::quality_verdict( 'image/webp', static fn( $fn ) => 'imagewebp' === $fn ) );
	}

	public function test_prefer_gd_stellt_gd_nach_vorn(): void {
		$this->assertSame(
			array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ),
			Swipe_Images_Detector::prefer_gd( array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) )
		);
		$this->assertSame(
			array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ),
			Swipe_Images_Detector::prefer_gd( array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ) ),
			'GD steht schon vorn: nichts doppelt'
		);
	}

	/**
	 * Verdrahtung: register_conversion_filters() registriert den wp_image_editors-Filter, wenn die
	 * Probe negativ ist und GD WebP kann. Braucht echtes GD (function_exists ist nicht mockbar) und
	 * läuft wegen des static-Guards genau einmal je Prozess.
	 */
	public function test_boot_registriert_gd_vortritt_bei_negativer_probe(): void {
		if ( ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD ohne WebP' );
		}
		Functions\when( 'get_option' )->justReturn( array( 'enabled' => true, 'format' => 'webp' ) );
		Functions\when( 'wp_image_editor_supports' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( 0 );

		$this->assertTrue( Swipe_Images::register_conversion_filters() );
		$this->assertNotFalse( Filters\has( 'wp_image_editors', array( 'Swipe_Images_Detector', 'prefer_gd' ) ) );
		$this->assertNotFalse( Filters\has( 'image_editor_output_format' ), 'die bisherigen Filter bleiben' );
	}
}
