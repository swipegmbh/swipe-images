<?php
/**
 * GD-Editor mit Speicherwächter. Steht per Swipe_Images_Detector::prefer_gd() vorn in wp_image_editors,
 * wenn der Standard-Editor den Qualitätswert ignoriert (quality_verdict() 'gd').
 *
 * _wp_image_editor_choose() reicht $args samt 'path' an test() jeder Implementierung durch – die einzige
 * Stelle, an der WordPress je Bild fragt, ob ein Editor taugt. Hier entscheidet also nicht die Liste,
 * sondern das konkrete Bild: passt es nicht ins freie PHP-Budget, fällt Core auf den nächsten Eintrag
 * zurück. Wird erst geladen, wenn Core WP_Image_Editor_GD geladen hat (siehe prefer_gd()).
 *
 * @package Swipe_Images
 */

class Swipe_Images_Editor_GD extends WP_Image_Editor_GD {

	/**
	 * @param array $args Wie bei WP_Image_Editor_GD::test(); wp_get_image_editor() setzt 'path',
	 *                    wp_image_editor_supports() nicht – dann gilt GD nur bei memory_limit -1 als sicher.
	 */
	public static function test( $args = array() ) {
		if ( ! parent::test( $args ) ) {
			return false;
		}
		return isset( $args['path'] )
			? Swipe_Images_Detector::gd_fits_file( (string) $args['path'] )
			: Swipe_Images_Detector::gd_fits( null, Swipe_Images_Detector::memory_budget() );
	}
}
