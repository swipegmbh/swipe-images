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

foreach ( glob( dirname( __DIR__ ) . '/includes/class-swipe-images-*.php' ) as $file ) {
	require_once $file;
}
