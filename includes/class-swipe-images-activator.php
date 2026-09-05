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
	 * Löscht die Proben-Transients (swipe_images_can_encode_webp/avif, swipe_images_quality_honoured_webp/avif)
	 * und probt danach sofort neu: ein Plugin-Update reaktiviert das Plugin, dieser Hook feuert also mit. Ohne das
	 * würde ein alter Cache-Treffer (z. B. "avif = nein" vor einem Server-Fix) bis zu einer Woche
	 * stehen bleiben. Die aktive Probe hier füllt den Cache sofort mit einem geprüften Ergebnis,
	 * statt das dem ersten Request zu überlassen – der kann über REST/Frontend laufen, wo
	 * can_encode() aus Kostengründen nicht probt, sondern nur den Cache liest.
	 */
	public static function activate() {
		require_once __DIR__ . '/class-swipe-images-detector.php';
		delete_transient( 'swipe_images_can_encode_webp' );
		delete_transient( 'swipe_images_can_encode_avif' );
		delete_transient( 'swipe_images_quality_honoured_webp' );
		delete_transient( 'swipe_images_quality_honoured_avif' );
		Swipe_Images_Detector::can_encode( 'image/webp' );
		Swipe_Images_Detector::quality_is_honoured( 'image/webp' );
		if ( Swipe_Images_Detector::can_encode( 'image/avif' ) ) {
			Swipe_Images_Detector::quality_is_honoured( 'image/avif' );
		}
	}

}
