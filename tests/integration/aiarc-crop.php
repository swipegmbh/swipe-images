<?php
/**
 * Integrationshelfer für Block 20: ein Zuschnitt mit acf-image-aspect-ratio-crop, drei Wege.
 *
 *   wp eval-file aiarc-crop.php direct  <quell-id>   create_crop() direkt (der gemeinsame Pfad beider Einstiege)
 *   wp eval-file aiarc-crop.php rest    <quell-id>   POST aiarc/v1/crop über rest_do_request()
 *   wp eval-file aiarc-crop.php control <quell-id>   ohne Verträglichkeitsschicht: zeigt den Fehler, den sie behebt
 *
 * Gibt eine Zeile «AIARC CROP=… FILE=… EXT=… THUMB=… W=… H=… SUSP=… LATER=…» aus und räumt seine Dateien auf.
 * Braucht die lokale Feldgruppe aus dem mu-Plugin (field_swipe_test_crop, Seitenverhältnis 1:1).
 *
 * @package Swipe_Images
 */

list( $mode, $source_id ) = $args;
$source_id = (int) $source_id;

if ( 'control' === $mode ) {
	remove_action( 'aiarc_pre_customize_upload_dir', array( 'Swipe_Images_Converter', 'suspend' ) );
}

$data = array(
	'id'                => $source_id,
	'key'               => 'field_swipe_test_crop',
	'x'                 => 100,
	'y'                 => 50,
	'width'             => 600,
	'height'            => 600,
	'cropType'          => 'aspect_ratio',
	'aspectRatioWidth'  => 1,
	'aspectRatioHeight' => 1,
	'temp_post_id'      => 'swipe-test',
);

if ( 'rest' === $mode ) {
	$req = new WP_REST_Request( 'POST', '/aiarc/v1/crop' );
	$req->set_header( 'X-Aiarc-Nonce', wp_create_nonce( 'aiarc' ) );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( $data ) );
	$res     = rest_do_request( $req );
	$crop_id = (int) ( $res->get_data()['id'] ?? 0 );
} else {
	$crop_id = (int) ( new npx_acf_plugin_image_aspect_ratio_crop() )->create_crop( $data );
}

$suspended = (int) Swipe_Images_Converter::is_suspended();

$file  = (string) get_attached_file( $crop_id );
$meta  = (array) wp_get_attachment_metadata( $crop_id );
$thumb = isset( $meta['sizes']['thumbnail']['file'] ) ? pathinfo( $meta['sizes']['thumbnail']['file'], PATHINFO_EXTENSION ) : '-';

// Späterer Upload im selben Prozess: wird er wieder konvertiert?
$tmp = wp_tempnam( 'later.jpg' );
copy( get_attached_file( $source_id ), $tmp );
$later_id  = media_handle_sideload( array( 'name' => 'later.jpg', 'tmp_name' => $tmp ), 0 );
$later_ext = is_wp_error( $later_id ) ? 'ERR' : pathinfo( wp_get_attachment_metadata( $later_id )['sizes']['thumbnail']['file'] ?? '-', PATHINFO_EXTENSION );

printf(
	"AIARC CROP=%d FILE=%d EXT=%s THUMB=%s W=%d H=%d SUSP=%d LATER=%s\n",
	$crop_id,
	(int) file_exists( $file ),
	pathinfo( $file, PATHINFO_EXTENSION ),
	$thumb,
	(int) ( $meta['width'] ?? 0 ),
	(int) ( $meta['height'] ?? 0 ),
	$suspended,
	$later_ext
);

// Aufräumen: Attachments weg; im control-Fall liegt die .webp verwaist neben dem fehlenden .jpg.
foreach ( array( $crop_id, $later_id ) as $id ) {
	if ( is_int( $id ) && $id > 0 ) {
		wp_delete_attachment( $id, true );
	}
}
$orphan = preg_replace( '/\.[a-z]+$/i', '.webp', $file );
if ( $orphan !== $file && file_exists( $orphan ) ) {
	unlink( $orphan );
}
