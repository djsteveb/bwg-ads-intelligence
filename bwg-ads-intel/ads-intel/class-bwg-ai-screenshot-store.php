<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local-disk storage for ad screenshots (Google Ads Transparency captures,
 * and anything else that needs a rendered image rather than a hosted link
 * like Meta's ad_snapshot_url).
 *
 * Files live under wp-content/uploads/bwg-ai-screenshots/{YYYY-MM-DD}/{session_id}/
 * with an .htaccess + index.php blocking direct web access — every read goes
 * through the signed REST endpoint in class-bwg-ai-rest.php so file paths
 * are never exposed and access can be time-limited.
 */
class BWG_AI_Screenshot_Store {

	const SUBDIR = 'bwg-ai-screenshots';

	/**
	 * Absolute base directory for screenshot storage. Creates it (and its
	 * access-blocking files) on first use.
	 *
	 * @return string|WP_Error
	 */
	public static function base_dir() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
		}

		$base = trailingslashit( $upload_dir['basedir'] ) . self::SUBDIR;

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$index = $base . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $base;
	}

	/**
	 * Save raw image bytes for one ad screenshot.
	 *
	 * @param int    $session_id
	 * @param string $platform     e.g. 'google'
	 * @param string $binary_data  Raw image bytes.
	 * @param string $ext          File extension without the dot (default png).
	 * @return array|WP_Error  [ 'relative_path' => ..., 'bytes' => int ]
	 */
	public static function save( $session_id, $platform, $binary_data, $ext = 'png' ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$session_id = absint( $session_id );
		$platform   = sanitize_key( $platform );
		$ext        = preg_replace( '/[^a-z0-9]/i', '', $ext ) ?: 'png';
		$date_dir   = gmdate( 'Y-m-d' );

		$dir = "{$base}/{$date_dir}/{$session_id}";
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$filename = $platform . '-' . wp_generate_password( 12, false, false ) . '.' . $ext;
		$full_path = "{$dir}/{$filename}";

		$bytes = file_put_contents( $full_path, $binary_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			return new WP_Error( 'screenshot_write_failed', 'Could not write screenshot to disk.' );
		}

		return [
			'relative_path' => "{$date_dir}/{$session_id}/{$filename}",
			'bytes'         => $bytes,
		];
	}

	/**
	 * Resolve a relative path (as stored in wp_bwg_ai_ads.screenshot_path)
	 * to an absolute filesystem path, guarding against path traversal.
	 *
	 * @param string $relative_path
	 * @return string|false
	 */
	public static function full_path( $relative_path ) {
		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return false;
		}

		$relative_path = ltrim( (string) $relative_path, '/' );
		// Reject any path that tries to escape the base directory.
		if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) ) {
			return false;
		}

		$full = $base . '/' . $relative_path;
		return file_exists( $full ) ? $full : false;
	}

	/**
	 * Delete one screenshot file. Safe to call on an already-missing file.
	 *
	 * @param string $relative_path
	 */
	public static function delete( $relative_path ) {
		$full = self::full_path( $relative_path );
		if ( $full ) {
			wp_delete_file( $full );
		}
	}

	// -------------------------------------------------------------------------
	// Stats
	// -------------------------------------------------------------------------

	/**
	 * Aggregate storage stats from the ads table (bytes are recorded at save
	 * time in screenshot_bytes — no filesystem walk needed).
	 *
	 * @return array{ total_bytes:int, weekly_bytes:int, weekly_breakdown:array }
	 */
	public static function stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_ai_ads';

		$total_bytes = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(screenshot_bytes),0) FROM `{$table}` WHERE screenshot_path <> ''"
		);

		$breakdown = [];
		$weekly_bytes = 0;
		for ( $i = 6; $i >= 0; $i-- ) {
			$day_start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$i} days" ) );
			$day_end   = gmdate( 'Y-m-d 23:59:59', strtotime( "-{$i} days" ) );
			$bytes     = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(screenshot_bytes),0) FROM `{$table}`
					 WHERE screenshot_path <> '' AND created_at BETWEEN %s AND %s",
					$day_start,
					$day_end
				)
			);
			$breakdown[]   = [ 'date' => gmdate( 'Y-m-d', strtotime( "-{$i} days" ) ), 'bytes' => $bytes ];
			$weekly_bytes += $bytes;
		}

		return [
			'total_bytes'      => $total_bytes,
			'weekly_bytes'     => $weekly_bytes,
			'weekly_breakdown' => $breakdown,
		];
	}

	// -------------------------------------------------------------------------
	// Retention / prune
	// -------------------------------------------------------------------------

	/**
	 * Delete screenshot files (and null the DB columns) for ads older than
	 * the given number of days. Used by both the "delete older than N days"
	 * admin action and daily maintenance retention.
	 *
	 * @param int $days
	 * @return array{ deleted:int, bytes_freed:int }
	 */
	public static function prune_older_than( $days ) {
		return self::prune_range( null, gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $days ) . ' days' ) ) );
	}

	/**
	 * Delete screenshot files (and null the DB columns) for ads created
	 * within [from, to]. Either bound may be null for an open range.
	 *
	 * @param string|null $from  'Y-m-d' or null.
	 * @param string|null $to    'Y-m-d' (or full datetime) or null.
	 * @return array{ deleted:int, bytes_freed:int }
	 */
	public static function prune_range( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_ai_ads';

		$where  = [ "screenshot_path <> ''" ];
		$params = [];
		if ( $from ) {
			$where[]  = 'created_at >= %s';
			$params[] = self::normalize_date( $from, false );
		}
		if ( $to ) {
			$where[]  = 'created_at <= %s';
			$params[] = self::normalize_date( $to, true );
		}

		$sql = "SELECT id, screenshot_path, screenshot_bytes FROM `{$table}` WHERE " . implode( ' AND ', $where );
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$deleted = 0;
		$freed   = 0;

		foreach ( $rows as $row ) {
			self::delete( $row->screenshot_path );
			$wpdb->update(
				$table,
				[ 'screenshot_path' => '', 'screenshot_bytes' => null ],
				[ 'id' => $row->id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
			$deleted++;
			$freed += (int) $row->screenshot_bytes;
		}

		return [ 'deleted' => $deleted, 'bytes_freed' => $freed ];
	}

	// -------------------------------------------------------------------------
	// Backup / export
	// -------------------------------------------------------------------------

	/**
	 * Build a zip of every screenshot in the given date range plus a CSV
	 * manifest (session_id, platform, ad_id, path, bytes, created_at).
	 * Saved under the same protected screenshots directory so it isn't
	 * publicly reachable — download it through the admin Storage page,
	 * which streams it via a nonce-protected admin-post handler.
	 *
	 * @param string|null $from
	 * @param string|null $to
	 * @return array{ path:string, filename:string, file_count:int }|WP_Error
	 */
	public static function export_zip( $from, $to ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_unavailable', 'The PHP ZipArchive extension is not available on this server.' );
		}

		$base = self::base_dir();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'bwg_ai_ads';
		$where  = [ "screenshot_path <> ''" ];
		$params = [];
		if ( $from ) {
			$where[]  = 'created_at >= %s';
			$params[] = self::normalize_date( $from, false );
		}
		if ( $to ) {
			$where[]  = 'created_at <= %s';
			$params[] = self::normalize_date( $to, true );
		}

		$sql  = "SELECT id, session_id, platform, ad_id, screenshot_path, screenshot_bytes, created_at
		          FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at';
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		if ( ! $rows ) {
			return new WP_Error( 'no_screenshots', 'No screenshots found in that date range.' );
		}

		$filename = 'bwg-ai-screenshots-' . gmdate( 'Ymd-His' ) . '.zip';
		$zip_path = "{$base}/{$filename}";

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_open_failed', 'Could not create export archive.' );
		}

		$manifest = "id,session_id,platform,ad_id,path,bytes,created_at\n";
		$count    = 0;

		foreach ( $rows as $row ) {
			$full = self::full_path( $row->screenshot_path );
			if ( $full ) {
				$zip->addFile( $full, 'screenshots/' . $row->screenshot_path );
				$count++;
			}
			$manifest .= implode( ',', array_map(
				function ( $v ) { return '"' . str_replace( '"', '""', (string) $v ) . '"'; },
				[ $row->id, $row->session_id, $row->platform, $row->ad_id, $row->screenshot_path, $row->screenshot_bytes, $row->created_at ]
			) ) . "\n";
		}

		$zip->addFromString( 'manifest.csv', $manifest );
		$zip->close();

		return [ 'path' => $zip_path, 'filename' => $filename, 'file_count' => $count ];
	}

	private static function normalize_date( $date, $end_of_day ) {
		$date = sanitize_text_field( $date );
		return $end_of_day ? gmdate( 'Y-m-d 23:59:59', strtotime( $date ) ) : gmdate( 'Y-m-d 00:00:00', strtotime( $date ) );
	}
}
