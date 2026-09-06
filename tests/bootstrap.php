<?php
require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! defined( 'SWIPE_IMAGES_VERSION' ) ) {
	define( 'SWIPE_IMAGES_VERSION', '1.0.0' );
}
if ( ! defined( 'SWIPE_IMAGES_PATH' ) ) {
	define( 'SWIPE_IMAGES_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SWIPE_IMAGES_FILE' ) ) {
	define( 'SWIPE_IMAGES_FILE', SWIPE_IMAGES_PATH . 'swipe-images.php' );
}
if ( ! defined( 'SWIPE_IMAGES_BASENAME' ) ) {
	define( 'SWIPE_IMAGES_BASENAME', 'swipe-images/swipe-images.php' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! class_exists( 'WP_Error' ) ) {
	// Minimaler Stub: Brain Monkeys is_wp_error() prüft nur instanceof \WP_Error.
	class WP_Error {
		public function __construct( ...$args ) {}
		public function get_error_message(): string {
			return '';
		}
	}
}

if ( ! class_exists( 'WP_Image_Editor_GD' ) ) {
	// Minimaler Stub: Swipe_Images_Editor_GD erbt davon. Core-test() prüft nur die GD-Extension.
	class WP_Image_Editor_GD {
		public static function test( $args = array() ) {
			return true;
		}
	}
}

foreach ( glob( dirname( __DIR__ ) . '/includes/class-swipe-images-*.php' ) as $file ) {
	require_once $file;
}
