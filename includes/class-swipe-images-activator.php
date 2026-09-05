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
	 * und probt danach sofort neu, damit der Cache gleich ein geprüftes Ergebnis trägt und nicht der
	 * erste Request entscheidet – der kann über REST/Frontend laufen, wo die Proben nur den Cache lesen.
	 *
	 * Gilt nur für die manuelle Aktivierung (Plugins-Seite, wp plugin activate). Ein Update, auch das
	 * automatische, reaktiviert still (activate_plugin(…, $silent = true)), dieser Hook läuft dann nicht:
	 * alte Transients stehen bis zu einer Woche weiter, das Urteil der Qualitätsprobe fällt erst im ersten
	 * Admin- oder WP-CLI-Request, weil Frontend, REST und Cron nicht proben.
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
