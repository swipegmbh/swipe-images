<?php
/**
 * Bestand: zählen, regenerieren, Waisen aus der On-the-fly-Zeit finden.
 *
 * @package Swipe_Images
 */

class Swipe_Images_Regenerator {

	const FAILED_OPTION = 'swipe_images_failed';

	public static function is_target_file( string $path ): bool {
		return (bool) preg_match( '/\.(webp|avif)$/i', $path );
	}

	/**
	 * Das aktuelle Mapping als Quell-Mimes und Ziel-Endung. Zähler und Batch arbeiten nur
	 * die Mimes ab, für die eine Konvertierung vorgesehen ist; PNG bleibt bei
	 * convert_png = false aussen vor statt dauerhaft «ausstehend» zu heissen.
	 *
	 * WordPress schreibt post_mime_type beim Konvertieren nicht um (auf dem Local sind alle
	 * konvertierten Attachments weiter image/jpeg), darum deckt die Quell-Mime-Liste sowohl
	 * die schon konvertierten als auch die ausstehenden Attachments ab.
	 *
	 * @return array{mimes:string[],ext:string}
	 */
	private static function mapping(): array {
		$settings = Swipe_Images_Settings::get();
		$avif_ok  = Swipe_Images_Detector::editor_supports( 'image/avif' );
		return array(
			'mimes' => array_keys(
				Swipe_Images_Converter::output_format( array(), (string) $settings['format'], ! empty( $settings['convert_png'] ), $avif_ok )
			),
			'ext'   => 'image/avif' === Swipe_Images_Converter::target_mime( (string) $settings['format'], $avif_ok ) ? 'avif' : 'webp',
		);
	}

	/** @return array{total:int,converted:int,pending:int} */
	public static function counts(): array {
		global $wpdb;
		$map = self::mapping();
		$in  = implode( ',', array_fill( 0, count( $map['mimes'] ), '%s' ) );

		$total     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ($in)",
				$map['mimes']
			)
		);
		$converted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
				 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN ($in)
				 AND m.meta_value LIKE %s",
				array_merge( $map['mimes'], array( '%.' . $map['ext'] ) )
			)
		);
		return array( 'total' => $total, 'converted' => $converted, 'pending' => max( 0, $total - $converted ) );
	}

	/**
	 * IDs der Attachments, deren Datei noch nicht im Zielformat liegt.
	 *
	 * @param int   $limit   0 = alle.
	 * @param int[] $exclude IDs, die übersprungen werden (Fehlerliste), damit ein Batch-Lauf endet.
	 * @return int[]
	 */
	public static function pending_ids( int $limit = 0, array $exclude = array() ): array {
		global $wpdb;
		$map  = self::mapping();
		$in   = implode( ',', array_fill( 0, count( $map['mimes'] ), '%s' ) );
		$args = array_merge( $map['mimes'], array( '%.' . $map['ext'] ) );
		$sql  = "SELECT p.ID FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN ($in)
			AND m.meta_value NOT LIKE %s";
		$exclude = array_filter( array_map( 'intval', $exclude ) );
		if ( $exclude ) {
			$sql .= ' AND p.ID NOT IN (' . implode( ',', $exclude ) . ')';
		}
		$sql .= ' ORDER BY p.ID ASC';
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
	}

	/**
	 * Absolute Pfade aller Dateien, die ein Metadaten-Array referenziert. Rein.
	 *
	 * @param string $basedir Upload-Basisverzeichnis ohne Slash am Ende.
	 */
	public static function files_from_meta( array $meta, string $basedir ): array {
		if ( empty( $meta['file'] ) ) {
			return array();
		}
		$full  = $basedir . '/' . ltrim( $meta['file'], '/' );
		$dir   = dirname( $full );
		$files = array( $full );
		if ( ! empty( $meta['original_image'] ) ) {
			$files[] = $dir . '/' . $meta['original_image'];
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . '/' . $size['file'];
			}
		}
		return $files;
	}

	/**
	 * Alte Dateien der Vor-Konvertierungs-Zeit, abgeleitet aus den NEUEN Metadaten. Rein.
	 *
	 * WordPress benennt Sub-Sizes deterministisch <stem>-<w>x<h>.<ext> und die verkleinerte
	 * Full-Size <stem>-scaled.<ext>. Vor der Konvertierung trugen sie die Endung des Originals.
	 * Ohne original_image gibt es nichts abzuleiten.
	 *
	 * @param string $basedir Upload-Basisverzeichnis ohne Slash am Ende.
	 */
	public static function legacy_siblings( array $meta, string $basedir ): array {
		if ( empty( $meta['file'] ) || empty( $meta['original_image'] ) ) {
			return array();
		}
		$dir  = dirname( $basedir . '/' . ltrim( $meta['file'], '/' ) );
		$ext  = pathinfo( $meta['original_image'], PATHINFO_EXTENSION );
		$stem = pathinfo( $meta['original_image'], PATHINFO_FILENAME );
		if ( '' === $ext ) {
			return array();
		}
		$files = array( $dir . '/' . $stem . '-scaled.' . $ext );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . '/' . pathinfo( $size['file'], PATHINFO_FILENAME ) . '.' . $ext;
			}
		}
		return $files;
	}

	/**
	 * Erzeugt Full, scaled und Sub-Sizes neu aus dem Original. Die Konvertierungsfilter
	 * müssen vorher gesetzt sein (Swipe_Images::register_conversion_filters()).
	 *
	 * @return true|WP_Error
	 */
	public static function regenerate( int $attachment_id, bool $delete_old = false ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file = wp_get_original_image_path( $attachment_id );
		if ( ! $file ) {
			$file = get_attached_file( $attachment_id );
		}
		if ( ! $file || ! file_exists( $file ) ) {
			$e = new WP_Error( 'swipe_images_missing', 'Quelldatei fehlt: ' . (string) $file );
			self::mark_failed( $attachment_id, $e->get_error_message() );
			return $e;
		}

		$basedir   = wp_get_upload_dir()['basedir'];
		$old_meta  = (array) wp_get_attachment_metadata( $attachment_id );
		$old_files = self::files_from_meta( $old_meta, $basedir );

		$new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( empty( $new_meta['file'] ) ) {
			$e = new WP_Error( 'swipe_images_editor', 'Editor lieferte keine Metadaten' );
			self::mark_failed( $attachment_id, $e->get_error_message() );
			return $e;
		}
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		// Core füllt 'file' auch dann, wenn der Editor scheiterte, und zeigt dann weiter auf die
		// Quelldatei. War für diesen Mime eine Konvertierung vorgesehen, ist das ein Fehler, sonst
		// meldet der Lauf Erfolg und der Batch dreht sich ewig um dieselben IDs.
		$settings = Swipe_Images_Settings::get();
		$avif_ok  = Swipe_Images_Detector::editor_supports( 'image/avif' );
		$source   = (string) wp_check_filetype( $file )['type'];
		if ( Swipe_Images_Converter::expects_conversion( $source, $settings, $avif_ok ) && ! self::is_target_file( $new_meta['file'] ) ) {
			$e = new WP_Error(
				'swipe_images_not_converted',
				sprintf(
					'Nicht konvertiert: Ergebnis liegt als .%s vor, erwartet war .%s.',
					pathinfo( $new_meta['file'], PATHINFO_EXTENSION ),
					'image/avif' === Swipe_Images_Converter::target_mime( (string) $settings['format'], $avif_ok ) ? 'avif' : 'webp'
				)
			);
			self::mark_failed( $attachment_id, $e->get_error_message() );
			return $e;
		}

		if ( $delete_old ) {
			$keep   = self::files_from_meta( $new_meta, $basedir );
			$keep[] = $file;
			$old    = array_unique( array_merge( $old_files, self::legacy_siblings( $new_meta, $basedir ) ) );
			foreach ( array_diff( $old, $keep ) as $path ) {
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}

		self::clear_failed_single( $attachment_id );
		return true;
	}

	/**
	 * Alle Werte eines Meta-Keys in Blöcken zu 1000. Ein einzelnes get_col() über
	 * _wp_attachment_metadata zieht bei grossen Bibliotheken hunderte Megabyte in den Speicher.
	 *
	 * @param string $key Meta-Key.
	 * @return Generator<string>
	 */
	private static function meta_values( string $key ): Generator {
		global $wpdb;
		$offset = 0;
		do {
			$rows = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT %d OFFSET %d",
					$key,
					1000,
					$offset
				)
			);
			foreach ( $rows as $row ) {
				yield (string) $row;
			}
			$offset += 1000;
		} while ( count( $rows ) === 1000 );
	}

	/** .webp-Dateien im Upload-Ordner, die kein Attachment referenziert und neben denen ein JPG/PNG liegt. */
	public static function orphan_webp_files(): array {
		$basedir    = wp_get_upload_dir()['basedir'];
		$referenced = array();
		foreach ( self::meta_values( '_wp_attachment_metadata' ) as $raw ) {
			$meta = maybe_unserialize( $raw );
			if ( is_array( $meta ) ) {
				foreach ( self::files_from_meta( $meta, $basedir ) as $p ) {
					$referenced[ $p ] = true;
				}
			}
		}
		// Auch die reine Dateizuordnung schützt: ein Attachment mit fehlenden oder kaputten
		// Metadaten wäre sonst eine Waise und seine Datei würde gelöscht.
		foreach ( self::meta_values( '_wp_attached_file' ) as $rel ) {
			$referenced[ $basedir . '/' . ltrim( $rel, '/' ) ] = true;
		}

		$orphans  = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			$path = $entry->getPathname();
			if ( ! preg_match( '/\.webp$/i', $path ) || isset( $referenced[ $path ] ) || str_contains( $path, '/swipe-images-preview/' ) ) {
				continue;
			}
			$stem = preg_replace( '/\.webp$/i', '', $path );
			foreach ( array( '.jpg', '.jpeg', '.png', '.JPG', '.JPEG', '.PNG' ) as $ext ) {
				if ( file_exists( $stem . $ext ) ) {
					$orphans[] = $path;
					break;
				}
			}
		}
		sort( $orphans );
		return $orphans;
	}

	public static function mark_failed( int $id, string $msg ): void {
		$failed        = (array) get_option( self::FAILED_OPTION, array() );
		$failed[ $id ] = $msg;
		update_option( self::FAILED_OPTION, $failed, false );
	}

	public static function clear_failed_single( int $id ): void {
		$failed = (array) get_option( self::FAILED_OPTION, array() );
		if ( isset( $failed[ $id ] ) ) {
			unset( $failed[ $id ] );
			update_option( self::FAILED_OPTION, $failed, false );
		}
	}

	public static function failed(): array {
		return (array) get_option( self::FAILED_OPTION, array() );
	}

	public static function clear_failed(): void {
		update_option( self::FAILED_OPTION, array(), false );
	}
}
