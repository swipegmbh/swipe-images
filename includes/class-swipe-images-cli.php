<?php
/**
 * WP-CLI: wp swipe-images status | regenerate | cleanup
 *
 * @package Swipe_Images
 */

class Swipe_Images_CLI {

	/**
	 * Zeigt Modus, Format, Server-Fähigkeiten, Zähler und fremde Quality-Filter.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swipe-images status
	 */
	public function status( $args, $assoc_args ) {
		$s    = Swipe_Images_Settings::get();
		$caps = Swipe_Images_Detector::capabilities();
		$c    = Swipe_Images_Regenerator::counts();

		WP_CLI::log( 'Modus:      ' . ( Swipe_Images::is_blocked() ? 'blockiert (Theme trägt eigenen Bildcode: ' . Swipe_Images_Detector::legacy_file() . ')' : 'aktiv' ) );
		WP_CLI::log( 'Aktiv:      ' . ( $s['enabled'] ? 'ja' : 'nein' ) );
		WP_CLI::log( sprintf( 'Format:     %s (Qualität WebP %d, AVIF %d)', $s['format'], $s['quality_webp'], $s['quality_avif'] ) );
		WP_CLI::log( sprintf( 'Editor:     WebP %s, AVIF %s', $caps['editor']['webp'] ? 'ja' : 'nein', $caps['editor']['avif'] ? 'ja' : 'nein' ) );
		WP_CLI::log( sprintf( 'GD:         WebP %s, AVIF %s · Imagick: WebP %s, AVIF %s', $caps['gd']['webp'] ? 'ja' : 'nein', $caps['gd']['avif'] ? 'ja' : 'nein', $caps['imagick']['webp'] ? 'ja' : 'nein', $caps['imagick']['avif'] ? 'ja' : 'nein' ) );
		WP_CLI::log( sprintf( 'Bilder:     %d gesamt, %d im Zielformat, %d ausstehend', $c['total'], $c['converted'], $c['pending'] ) );
		foreach ( Swipe_Images_Detector::foreign_quality_filters() as $hook => $list ) {
			WP_CLI::warning( sprintf( 'Fremder Filter auf %s: %s', $hook, implode( ', ', $list ) ) );
		}
		$failed = Swipe_Images_Regenerator::failed();
		if ( $failed ) {
			WP_CLI::warning( count( $failed ) . ' Attachments in der Fehlerliste (wp swipe-images regenerate --ids=… oder Backend).' );
		}
	}

	/**
	 * Erzeugt Full, scaled und alle Grössen neu aus dem Original im Zielformat.
	 *
	 * Läuft auch im blockierten Modus; die Filter gelten nur für diesen Lauf.
	 * Alte Dateien bleiben liegen, ausser mit --delete-old.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Kommagetrennte Attachment-IDs. Ohne Angabe alle ausstehenden.
	 *
	 * [--delete-old]
	 * : Dateien aus den alten Metadaten löschen, die die neuen nicht mehr referenzieren.
	 *
	 * [--yes]
	 * : Keine Rückfrage.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swipe-images regenerate
	 *     wp swipe-images regenerate --ids=12,15 --delete-old
	 */
	public function regenerate( $args, $assoc_args ) {
		$ids = ! empty( $assoc_args['ids'] )
			? array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['ids'] ) ) )
			: Swipe_Images_Regenerator::pending_ids();
		if ( ! $ids ) {
			WP_CLI::success( 'Nichts zu tun, alle Bilder liegen im Zielformat.' );
			return;
		}
		$delete_old = ! empty( $assoc_args['delete-old'] );
		WP_CLI::confirm( sprintf( '%d Bilder regenerieren%s?', count( $ids ), $delete_old ? ' und alte Dateien löschen' : '' ), $assoc_args );

		if ( ! Swipe_Images::register_conversion_filters() ) {
			WP_CLI::error( 'Das Plugin ist in den Einstellungen deaktiviert.' );
		}

		$done     = 0;
		$errors   = 0;
		$seen     = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Regeneriere', count( $ids ) );
		foreach ( $ids as $id ) {
			$r = Swipe_Images_Regenerator::regenerate( $id, $delete_old );
			if ( is_wp_error( $r ) ) {
				++$errors;
				WP_CLI::warning( sprintf( 'ID %d: %s', $id, $r->get_error_message() ) );
			} else {
				++$done;
			}
			// Ohne Flush wächst der Objekt-Cache über eine grosse Bibliothek bis zum OOM.
			if ( 0 === ++$seen % 200 ) {
				wp_cache_flush();
			}
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success( sprintf( '%d regeneriert, %d Fehler.', $done, $errors ) );
	}

	/**
	 * Findet .webp-Dateien aus der On-the-fly-Zeit, die kein Attachment referenziert.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Nur auflisten.
	 *
	 * [--yes]
	 * : Keine Rückfrage vor dem Löschen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp swipe-images cleanup --dry-run
	 *     wp swipe-images cleanup
	 */
	public function cleanup( $args, $assoc_args ) {
		$orphans = Swipe_Images_Regenerator::orphan_webp_files();
		if ( ! $orphans ) {
			WP_CLI::success( 'Keine verwaisten WebP-Dateien.' );
			return;
		}
		$bytes = array_sum( array_map( 'filesize', $orphans ) );
		foreach ( $orphans as $p ) {
			WP_CLI::log( $p );
		}
		WP_CLI::log( sprintf( '%d Dateien, %s', count( $orphans ), size_format( $bytes ) ) );
		self::warn_foreign_webp_plugins();
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			return;
		}
		WP_CLI::confirm( sprintf( '%d Dateien löschen?', count( $orphans ) ), $assoc_args );
		foreach ( $orphans as $p ) {
			wp_delete_file( $p );
		}
		WP_CLI::success( sprintf( '%d Dateien gelöscht, %s frei.', count( $orphans ), size_format( $bytes ) ) );
	}

	/**
	 * Warnt vor aktiven Fremdplugins mit eigenem WebP-Cache. Deren Cache-Dateien passen exakt
	 * auf das Suchmuster von cleanup (.webp ohne Attachment, JPG/PNG gleichen Namens daneben)
	 * und würden mitgelöscht.
	 *
	 * @return void
	 */
	private static function warn_foreign_webp_plugins(): void {
		$known = array( 'wp-smushit', 'wp-smush-pro', 'ewww-image-optimizer', 'shortpixel-image-optimiser', 'webp-express', 'imagify', 'webp-converter-for-media' );
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			foreach ( $known as $slug ) {
				if ( str_starts_with( (string) $plugin, $slug ) ) {
					WP_CLI::warning( sprintf( '%s ist aktiv. Dessen WebP-Cache fällt unter dieses Suchmuster und würde mitgelöscht. Modul vorher abschalten.', $slug ) );
					break;
				}
			}
		}
	}
}
