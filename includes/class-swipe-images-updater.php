<?php
/**
 * Updates aus GitHub-Releases über den Core-Mechanismus «Update URI».
 *
 * WordPress ruft update_plugins_github.com für jedes Plugin mit einem Update URI auf
 * github.com auf; wir antworten nur für unser eigenes.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Updater {

	const REPO      = 'swipegmbh/swipe-images';
	const URI       = 'https://github.com/swipegmbh/swipe-images';
	const ASSET     = 'swipe-images.zip';
	const TRANSIENT = 'swipe_images_update';

	/**
	 * Baut die Update-Antwort aus dem Release-JSON. Rein, testbar.
	 *
	 * @param mixed  $release     Dekodiertes JSON von /releases/latest.
	 * @param string $current     Installierte Version.
	 * @param string $plugin_file Plugin-Basename.
	 * @return array|false
	 */
	public static function build_update( $release, string $current, string $plugin_file ) {
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return false;
		}
		$version = ltrim( (string) $release['tag_name'], 'vV' );
		if ( ! version_compare( $version, $current, '>' ) ) {
			return false;
		}
		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			return false;
		}
		return array(
			'id'           => self::URI,
			'slug'         => 'swipe-images',
			'plugin'       => $plugin_file,
			'version'      => $version,
			'url'          => (string) ( $release['html_url'] ?? self::URI ),
			'package'      => $package,
			'requires'     => '6.5',
			'requires_php' => '8.1',
		);
	}

	/**
	 * Callback für update_plugins_github.com.
	 *
	 * @param mixed $update       Aktuelle Update-Info oder null.
	 * @param array $plugin_data  Plugin-Header.
	 * @param string $plugin_file Plugin-Basename.
	 * @param array $locales      Sprach-Einstellungen.
	 * @return mixed
	 */
	public function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( SWIPE_IMAGES_BASENAME !== $plugin_file ) {
			return $update;
		}
		$release = get_transient( self::TRANSIENT );
		if ( false === $release ) {
			$response = wp_remote_get(
				'https://api.github.com/repos/' . self::REPO . '/releases/latest',
				array(
					'timeout' => 8,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'swipe-images/' . SWIPE_IMAGES_VERSION,
					),
				)
			);
			$release = array();
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$release = (array) json_decode( wp_remote_retrieve_body( $response ), true );
			}
			set_transient( self::TRANSIENT, $release, 12 * HOUR_IN_SECONDS );
		}
		$built = self::build_update( $release, SWIPE_IMAGES_VERSION, $plugin_file );
		return $built ? $built : $update;
	}
}
