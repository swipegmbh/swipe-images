<?php
use PHPUnit\Framework\TestCase;

class SanityTest extends TestCase {
	public function test_loader_class_exists(): void {
		$this->assertTrue( class_exists( 'Swipe_Images_Loader' ) );
	}
}
