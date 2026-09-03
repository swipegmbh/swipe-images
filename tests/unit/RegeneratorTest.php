<?php
use PHPUnit\Framework\TestCase;

class RegeneratorTest extends TestCase {

	public function test_files_from_meta_resolves_full_sizes_and_original(): void {
		$meta  = array(
			'file'           => '2026/09/photo-scaled.webp',
			'original_image' => 'photo.jpg',
			'sizes'          => array(
				'large'  => array( 'file' => 'photo-1024x683.webp' ),
				'kaputt' => array(),
			),
		);
		$files = Swipe_Images_Regenerator::files_from_meta( $meta, '/up' );
		$this->assertSame(
			array( '/up/2026/09/photo-scaled.webp', '/up/2026/09/photo.jpg', '/up/2026/09/photo-1024x683.webp' ),
			$files
		);
	}

	public function test_files_from_meta_without_file_is_empty(): void {
		$this->assertSame( array(), Swipe_Images_Regenerator::files_from_meta( array(), '/up' ) );
	}

	public function test_is_target_file(): void {
		$this->assertTrue( Swipe_Images_Regenerator::is_target_file( 'a/b.webp' ) );
		$this->assertTrue( Swipe_Images_Regenerator::is_target_file( 'a/b.AVIF' ) );
		$this->assertFalse( Swipe_Images_Regenerator::is_target_file( 'a/b.jpg' ) );
	}

	public function test_legacy_siblings_derives_old_names_from_new_meta(): void {
		$meta  = array(
			'file'           => '2026/09/photo-scaled.webp',
			'original_image' => 'photo.jpg',
			'sizes'          => array(
				'large'     => array( 'file' => 'photo-1024x683.webp' ),
				'thumbnail' => array( 'file' => 'photo-150x150.webp' ),
			),
		);
		$this->assertSame(
			array( '/up/2026/09/photo-scaled.jpg', '/up/2026/09/photo-1024x683.jpg', '/up/2026/09/photo-150x150.jpg' ),
			Swipe_Images_Regenerator::legacy_siblings( $meta, '/up' )
		);
		$this->assertSame( array(), Swipe_Images_Regenerator::legacy_siblings( array( 'file' => 'a.webp' ), '/up' ) );
	}
}
