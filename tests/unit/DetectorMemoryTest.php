<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Speicherwächter (1.0.4): GD hält das Bild als Truecolor im PHP-Speicher, Imagick nicht. Gemessen am echten
 * Upload-Fluss (media_handle_sideload, WP 7.1, bundled GD, Schwelle 2560), OOM-Grenze mit gestaffelten
 * memory_limits bestätigt: 800×600-PNG 8 MB, 6-MP-JPEG 67 MB, 12-MP-JPEG 93,5 MB, 24,8-MP-PNG 188 MB (stirbt
 * bei 240M mit 53 MB Grundlast), srv01 48-MP-JPEG 231 MB. Unter PHP-FPM (128M, von WordPress auf
 * WP_MAX_MEMORY_LIMIT 256M angehoben) stirbt ein grosser Upload mit GD, mit Imagick geht er durch. Darum bekommt
 * GD den Vortritt nur für Bilder, die ins verbleibende Budget passen.
 */
class DetectorMemoryTest extends TestCase {

	private const MB = 1048576;

	/** srv01 nach dem Anheben: 256M Limit minus 87 MB Grundlast. */
	private const SRV01_BUDGET = 169 * self::MB;

	private array $files = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_getimagesize' )->alias( 'getimagesize' );
		Functions\when( 'get_option' )->justReturn( array() ); // Settings::get() liefert die Defaults, Schwelle 2560
	}

	protected function tearDown(): void {
		foreach ( $this->files as $f ) {
			@unlink( $f );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Flaches JPEG mit $w × $h Pixeln; für getimagesize() zählt nur der Header. */
	private function jpeg( int $w, int $h ): string {
		$path = tempnam( sys_get_temp_dir(), 'swipe-images-test' ) . '.jpg';
		$im   = imagecreatetruecolor( $w, $h );
		imagejpeg( $im, $path, 50 );
		imagedestroy( $im );
		$this->files[] = $path;
		return $path;
	}

	/** Die Schätzung liegt über jeder Messung, aber unter dem Doppelten – Reserve, kein Blindflug. */
	public function test_schaetzung_deckt_die_messwerte_mit_reserve(): void {
		$measured = array(
			array( 800 * 600, 8.0 ),      // PNG, unter der Schwelle
			array( 3000 * 2000, 66.7 ),   // JPEG
			array( 4000 * 3000, 93.5 ),   // JPEG
			array( 6112 * 4060, 188.0 ),  // PNG, adschool.ch
			array( 8000 * 6000, 231.0 ),  // JPEG, srv01
		);
		foreach ( $measured as list( $pixels, $mb ) ) {
			$need = Swipe_Images_Detector::gd_bytes_needed( $pixels, 2560 );
			$this->assertGreaterThanOrEqual( $mb * self::MB, $need, sprintf( '%d px: Schätzung unter der Messung von %.1f MB', $pixels, $mb ) );
			$this->assertLessThanOrEqual( 2 * $mb * self::MB, $need, sprintf( '%d px: Schätzung mehr als doppelt so hoch wie gemessen', $pixels ) );
		}
		$this->assertSame( 48000000 * 20, Swipe_Images_Detector::gd_bytes_needed( 48000000, 0 ), 'Schwelle aus: der Arbeitssatz ist das ganze Bild' );
	}

	public function test_grosses_bild_unter_knappem_limit_bleibt_beim_standard_editor(): void {
		$this->assertFalse( Swipe_Images_Detector::gd_fits( 8000 * 6000, self::SRV01_BUDGET ), '48 MP brauchen ~443 MB, 169 MB sind da' );
		$this->assertFalse( Swipe_Images_Detector::gd_fits( 6112 * 4060, self::SRV01_BUDGET ), 'adschool 24,8 MP: ~257 MB geschätzt, 188 gemessen, 169 verfügbar' );
	}

	public function test_kleines_bild_unter_demselben_limit_geht_an_gd(): void {
		$this->assertTrue( Swipe_Images_Detector::gd_fits( 3000 * 2000, self::SRV01_BUDGET ), '6 MP: ~107 MB' );
		$this->assertTrue( Swipe_Images_Detector::gd_fits( 4032 * 3024, self::SRV01_BUDGET ), '12-MP-Handyfoto: ~157 MB' );
		$ceiling = Swipe_Images_Detector::gd_pixel_ceiling( self::SRV01_BUDGET, 2560 );
		$this->assertSame( 14778368, $ceiling, 'srv01: 14,8 MP' );
		$this->assertTrue( Swipe_Images_Detector::gd_fits( $ceiling, self::SRV01_BUDGET ), 'der letzte passende Pixel' );
		$this->assertFalse( Swipe_Images_Detector::gd_fits( $ceiling + 1, self::SRV01_BUDGET ), 'ein Pixel mehr passt nicht' );
	}

	public function test_pixel_ceiling_unter_der_schwelle_und_ohne_schwelle(): void {
		$this->assertSame( 1048576, Swipe_Images_Detector::gd_pixel_ceiling( 20 * self::MB, 2560 ), 'kleines Budget: 20 B/px, das Bild bleibt unter der Schwelle' );
		$this->assertSame( intdiv( self::SRV01_BUDGET, 20 ), Swipe_Images_Detector::gd_pixel_ceiling( self::SRV01_BUDGET, 0 ), 'Schwelle aus: 20 B/px' );
	}

	public function test_unbegrenztes_limit_nimmt_jedes_bild(): void {
		$this->assertTrue( Swipe_Images_Detector::gd_fits( 8000 * 6000, PHP_INT_MAX ) );
		$this->assertTrue( Swipe_Images_Detector::gd_fits( null, PHP_INT_MAX ), 'unbekannte Grösse bei -1: GD unbedenklich' );
	}

	public function test_unbekannte_groesse_unter_endlichem_limit_heisst_nein(): void {
		$this->assertFalse( Swipe_Images_Detector::gd_fits( null, self::SRV01_BUDGET ), 'keine Masse, knappes Limit: kein GD' );
		$this->assertFalse( Swipe_Images_Detector::gd_fits( 1, 0 ), 'Budget aufgebraucht' );
	}

	public function test_gd_fits_file_liest_die_masse_aus_dem_header(): void {
		Functions\expect( 'wp_raise_memory_limit' )->never();
		$small = $this->jpeg( 20, 20 ); // 400 px × (8 + 12) B = 8000 B
		$this->assertFalse( Swipe_Images_Detector::gd_fits_file( $small, 7999 ) );
		$this->assertTrue( Swipe_Images_Detector::gd_fits_file( $small, 8000 ) );
		$this->assertFalse( Swipe_Images_Detector::gd_fits_file( '/nirgends/fehlt.jpg', self::SRV01_BUDGET ), 'unlesbar = unbekannt' );
		$this->assertTrue( Swipe_Images_Detector::gd_fits_file( '/nirgends/fehlt.jpg', PHP_INT_MAX ) );
	}

	/**
	 * Ohne injiziertes Budget hebt gd_fits_file() das Limit wie WP_Image_Editor_GD::load() an und rechnet gegen
	 * memory_limit minus memory_get_usage(true). Das Limit wird hier real gesetzt, relativ zum aktuellen Verbrauch.
	 */
	public function test_gd_fits_file_ohne_budget_rechnet_gegen_das_angehobene_limit(): void {
		Functions\expect( 'wp_raise_memory_limit' )->times( 3 )->with( 'image' )->andReturn( false );
		Functions\when( 'wp_convert_hr_to_bytes' )->alias( static fn( $v ) => (int) $v );
		$big   = $this->jpeg( 3000, 3000 ); // 9 MP: ~131 MB geschätzt
		$small = $this->jpeg( 20, 20 );
		$orig  = ini_get( 'memory_limit' );
		try {
			ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 32 * self::MB ) );
			$this->assertFalse( Swipe_Images_Detector::gd_fits_file( $big ), '131 MB in ~32 MB Budget' );
			$this->assertTrue( Swipe_Images_Detector::gd_fits_file( $small ) );
			ini_set( 'memory_limit', '-1' );
			$this->assertTrue( Swipe_Images_Detector::gd_fits_file( $big ), 'unbegrenzt: auch das grosse Bild' );
		} finally {
			ini_set( 'memory_limit', $orig );
		}
	}

	/** Der Editor, den prefer_gd() nach vorn stellt, verneint test() für Bilder, die nicht passen. */
	public function test_editor_test_folgt_dem_speicherwaechter(): void {
		Functions\when( 'wp_raise_memory_limit' )->justReturn( false );
		Functions\when( 'wp_convert_hr_to_bytes' )->alias( static fn( $v ) => (int) $v );
		$big   = $this->jpeg( 3000, 3000 );
		$small = $this->jpeg( 20, 20 );
		$orig  = ini_get( 'memory_limit' );
		try {
			ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 32 * self::MB ) );
			$this->assertFalse( Swipe_Images_Editor_GD::test( array( 'path' => $big ) ) );
			$this->assertTrue( Swipe_Images_Editor_GD::test( array( 'path' => $small ) ) );
			$this->assertFalse( Swipe_Images_Editor_GD::test( array( 'mime_type' => 'image/webp' ) ), 'Fähigkeitsabfrage ohne Datei unter endlichem Limit: nein' );
			ini_set( 'memory_limit', '-1' );
			$this->assertTrue( Swipe_Images_Editor_GD::test( array( 'path' => $big ) ) );
			$this->assertTrue( Swipe_Images_Editor_GD::test( array( 'mime_type' => 'image/webp' ) ) );
		} finally {
			ini_set( 'memory_limit', $orig );
		}
	}
}
