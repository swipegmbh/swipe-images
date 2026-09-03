<?php

/**
 * Internationalisierung: laden von Sprach-Dateien.
 *
 * @package Swipe_Images
 */

/**
 * Internationalisierung: laden von Sprach-Dateien.
 *
 * @package Swipe_Images
 */
class Swipe_Images_i18n {


	/**
	 * Lädt die Text-Domain des Plugins.
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'swipe-images',
			false,
			dirname( SWIPE_IMAGES_BASENAME ) . '/languages/'
		);

	}



}
