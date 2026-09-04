<?php

/**
 * Aktivierungshook: wird beim Plugin-Aktivieren aufgerufen.
 *
 * @package Swipe_Images
 */

/**
 * Aktivierungshook: wird beim Plugin-Aktivieren aufgerufen.
 *
 * @package Swipe_Images
 */
class Swipe_Images_Activator {

	/**
	 * Führt die Aktivierungs-Logik aus.
	 *
	 * Löscht die Encode-Probe-Transients (swipe_images_can_encode_webp/avif): ein Plugin-Update
	 * reaktiviert das Plugin, dieser Hook feuert also mit. Ohne das würde ein alter Cache-Treffer
	 * (z. B. "avif = nein" vor einem Server-Fix) bis zu einer Woche stehen bleiben.
	 */
	public static function activate() {
		delete_transient( 'swipe_images_can_encode_webp' );
		delete_transient( 'swipe_images_can_encode_avif' );
	}

}
