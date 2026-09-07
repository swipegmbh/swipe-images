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

/**
 * GD, der Palettenbilder vor dem Speichern in Truecolor wandelt. Bundled GD schreibt für indizierte Bilder
 * (Paletten-PNG, GIF) mit imagewebp() eine leere Datei und meldet trotzdem true: PHP wertet den Rückgabewert
 * von gdImageWebpCtx nicht aus, anders als bei AVIF. Core sieht Erfolg, stellt file auf die .webp, und die hat
 * 0 Byte. Es trifft das unskalierte Full-Size; die Grössen laufen über imagecopyresampled in Truecolor und
 * bleiben heil. autopflege-ostschweiz: 13 Attachments mit leerem Full-Size, 12 davon Paletten-PNG.
 *
 * Steht per Swipe_Images_Detector::truecolor_gd() an der Stelle des Core-GD in wp_image_editors: wo Core GD
 * wählen würde, wählt es diese Klasse, mit oder ohne Vortritt.
 */
class Swipe_Images_Editor_GD_Truecolor extends WP_Image_Editor_GD {

	protected function _save( $image, $filename = null, $mime_type = null ) {
		// imagepalettetotruecolor() liefert true auch für Truecolor; false nur, wenn die Kopie nicht in den
		// Speicher passt. Dann lieber ein Fehler als eine leere Datei mit Erfolgsmeldung.
		if ( ! imagepalettetotruecolor( $image ) ) {
			return new WP_Error( 'image_save_error', 'Palettenbild liess sich nicht in Truecolor wandeln.' );
		}
		return parent::_save( $image, $filename, $mime_type );
	}
}

class Swipe_Images_Editor_GD extends Swipe_Images_Editor_GD_Truecolor {

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
