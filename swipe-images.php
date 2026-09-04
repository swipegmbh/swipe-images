<?php
/**
 * swipe Bilder
 *
 * @package Swipe_Images
 *
 * @wordpress-plugin
 * Plugin Name:       swipe Bilder
 * Plugin URI:        https://github.com/swipegmbh/swipe-images
 * Description:       Bilder beim Upload als WebP oder AVIF, Qualitätsregler, Migration des Bestands. Ersetzt die functions-images.php der swipe-Themes.
 * Version:           1.0.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            swipe GmbH
 * Author URI:        https://swipe.ch
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       swipe-images
 * Domain Path:       /languages
 * Update URI:        https://github.com/swipegmbh/swipe-images
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'SWIPE_IMAGES_VERSION', '1.0.3' );
define( 'SWIPE_IMAGES_FILE', __FILE__ );
define( 'SWIPE_IMAGES_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWIPE_IMAGES_BASENAME', plugin_basename( __FILE__ ) );

function activate_swipe_images() {
	require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-activator.php';
	Swipe_Images_Activator::activate();
}

function deactivate_swipe_images() {
	require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-deactivator.php';
	Swipe_Images_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_swipe_images' );
register_deactivation_hook( __FILE__, 'deactivate_swipe_images' );

require SWIPE_IMAGES_PATH . 'includes/class-swipe-images.php';

function run_swipe_images() {
	$plugin = new Swipe_Images();
	$plugin->run();
}
run_swipe_images();
