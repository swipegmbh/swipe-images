<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'swipe_images_settings' );
delete_option( 'swipe_images_failed' );
delete_transient( 'swipe_images_update' );
