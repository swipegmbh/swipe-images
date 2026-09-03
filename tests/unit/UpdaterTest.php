<?php
use PHPUnit\Framework\TestCase;

class UpdaterTest extends TestCase {

	private function release( string $tag, bool $with_asset = true ): array {
		$r = array( 'tag_name' => $tag, 'html_url' => 'https://github.com/swipegmbh/swipe-images/releases/tag/' . $tag, 'assets' => array() );
		if ( $with_asset ) {
			$r['assets'][] = array( 'name' => 'swipe-images.zip', 'browser_download_url' => 'https://github.com/swipegmbh/swipe-images/releases/download/' . $tag . '/swipe-images.zip' );
		}
		return $r;
	}

	public function test_newer_release_with_asset_yields_update(): void {
		$u = Swipe_Images_Updater::build_update( $this->release( 'v1.0.1' ), '1.0.0', 'swipe-images/swipe-images.php' );
		$this->assertSame( '1.0.1', $u['version'] );
		$this->assertSame( 'swipe-images', $u['slug'] );
		$this->assertSame( 'swipe-images/swipe-images.php', $u['plugin'] );
		$this->assertStringEndsWith( '/v1.0.1/swipe-images.zip', $u['package'] );
	}

	public function test_same_or_older_release_is_no_update(): void {
		$this->assertFalse( Swipe_Images_Updater::build_update( $this->release( 'v1.0.0' ), '1.0.0', 'x' ) );
		$this->assertFalse( Swipe_Images_Updater::build_update( $this->release( '0.9.0' ), '1.0.0', 'x' ) );
	}

	public function test_missing_asset_or_garbage_is_no_update(): void {
		$this->assertFalse( Swipe_Images_Updater::build_update( $this->release( 'v2.0.0', false ), '1.0.0', 'x' ) );
		$this->assertFalse( Swipe_Images_Updater::build_update( array(), '1.0.0', 'x' ) );
		$this->assertFalse( Swipe_Images_Updater::build_update( 'kaputt', '1.0.0', 'x' ) );
	}
}
