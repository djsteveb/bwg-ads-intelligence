<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token-bucket rate limiter backed by wp_bwg_ai_ratelimits.
 *
 * If the sibling plugin bwg-speed-sitescout is active and its rate limiter
 * uses the same table/pattern, we delegate to it so both plugins share one
 * rate-limit store. Otherwise we run our own implementation.
 */
class BWG_AI_Rate_Limiter {

	// Per-endpoint limits: [ max_count, window_in_seconds ]
	const LIMITS = [
		'start'         => [ 5,  3600  ],   // 5 per hour per IP
		'start_daily'   => [ 20, 86400 ],   // 20 per day per IP
		'resume'        => [ 10, 3600  ],   // 10 per hour per IP
		'upload_export' => [ 3,  3600  ],   // 3 per session per hour
	];

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bwg_ai_ratelimits';
	}

	/**
	 * Check whether $key is within $limit hits per $window seconds.
	 * Increments the counter. Returns true if allowed, false if blocked.
	 *
	 * @param string $key    Unique bucket identifier (e.g. "start:1.2.3.4")
	 * @param int    $limit  Maximum allowed hits.
	 * @param int    $window Window in seconds.
	 * @return bool
	 */
	public static function check( $key, $limit, $window ) {
		// Delegate to sibling plugin if available (shares same table pattern).
		if ( class_exists( 'BWG_CPA_Rate_Limiter' ) && method_exists( 'BWG_CPA_Rate_Limiter', 'check' ) ) {
			return BWG_CPA_Rate_Limiter::check( 'bwgai_' . $key, $limit, $window );
		}

		return self::check_own( $key, $limit, $window );
	}

	/**
	 * Convenience wrapper — checks a named endpoint limit for the given IP.
	 *
	 * @param string $endpoint One of the keys in self::LIMITS.
	 * @param string $ip
	 * @return bool True if allowed.
	 */
	public static function check_endpoint( $endpoint, $ip ) {
		if ( ! isset( self::LIMITS[ $endpoint ] ) ) {
			return true;
		}
		[ $limit, $window ] = self::LIMITS[ $endpoint ];
		$key = $endpoint . ':' . $ip;
		return self::check( $key, $limit, $window );
	}

	/**
	 * Convenience wrapper for per-session limits (e.g. upload_export).
	 *
	 * @param string $endpoint
	 * @param int    $session_id
	 * @return bool
	 */
	public static function check_session( $endpoint, $session_id ) {
		if ( ! isset( self::LIMITS[ $endpoint ] ) ) {
			return true;
		}
		[ $limit, $window ] = self::LIMITS[ $endpoint ];
		$key = $endpoint . ':session:' . absint( $session_id );
		return self::check( $key, $limit, $window );
	}

	/**
	 * Return seconds until the bucket resets (for showing cooldown timers).
	 *
	 * @param string $endpoint
	 * @param string $ip
	 * @return int  0 if not blocked.
	 */
	public static function retry_after( $endpoint, $ip ) {
		global $wpdb;
		$key = $endpoint . ':' . $ip;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT expires_at FROM `{$wpdb->prefix}bwg_ai_ratelimits` WHERE bucket_key = %s",
				$key
			)
		);
		if ( ! $row ) {
			return 0;
		}
		$seconds = strtotime( $row->expires_at ) - time();
		return max( 0, $seconds );
	}

	/**
	 * Check if an IP is locked out (too many failures) WITHOUT incrementing.
	 * Used to gate access-code attempts before processing.
	 *
	 * @param string $key    Bucket key (e.g. "access_code_fail:1.2.3.4")
	 * @param int    $limit  Max failures before lockout.
	 * @param int    $window Window in seconds.
	 * @return bool True = locked out (blocked), false = allowed.
	 */
	public static function is_locked( $key, $limit, $window ) {
		global $wpdb;
		$table = self::table();
		$key   = sanitize_text_field( $key );
		$now   = time();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT count, expires_at FROM `$table` WHERE bucket_key = %s",
				$key
			)
		);

		if ( ! $row || strtotime( $row->expires_at ) <= $now ) {
			return false;
		}

		return (int) $row->count >= $limit;
	}

	/**
	 * Increment a failure counter without checking the limit.
	 * Used after a confirmed bad access-code guess.
	 *
	 * @param string $key    Bucket key.
	 * @param int    $window Window in seconds (used when creating a new bucket).
	 */
	public static function increment( $key, $window ) {
		global $wpdb;
		$table = self::table();
		$key   = sanitize_text_field( $key );
		$now   = time();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT count, expires_at FROM `$table` WHERE bucket_key = %s",
				$key
			)
		);

		if ( ! $row || strtotime( $row->expires_at ) <= $now ) {
			$wpdb->replace(
				$table,
				[
					'bucket_key' => $key,
					'count'      => 1,
					'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $window ),
				],
				[ '%s', '%d', '%s' ]
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `$table` SET count = count + 1 WHERE bucket_key = %s",
					$key
				)
			);
		}
	}

	/**
	 * Remove stale rows (called by daily maintenance cron).
	 */
	public static function prune() {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->prefix}bwg_ai_ratelimits` WHERE expires_at < %s",
				$now
			)
		);
	}

	// -------------------------------------------------------------------------
	// Internal implementation
	// -------------------------------------------------------------------------

	private static function check_own( $key, $limit, $window ) {
		global $wpdb;
		$table = self::table();
		$now   = time();
		$key   = sanitize_text_field( $key );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT count, expires_at FROM `$table` WHERE bucket_key = %s",
				$key
			)
		);

		if ( ! $row || strtotime( $row->expires_at ) <= $now ) {
			// New or expired bucket — insert/replace with count=1.
			$wpdb->replace(
				$table,
				[
					'bucket_key' => $key,
					'count'      => 1,
					'expires_at' => gmdate( 'Y-m-d H:i:s', $now + $window ),
				],
				[ '%s', '%d', '%s' ]
			);
			return true;
		}

		if ( (int) $row->count >= $limit ) {
			return false; // Blocked.
		}

		// Increment within the existing window.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `$table` SET count = count + 1 WHERE bucket_key = %s",
				$key
			)
		);
		return true;
	}
}
