<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Session {

	/** Characters for access code generation — excludes O, 0, I, 1 for readability. */
	const ACCESS_CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	const ACCESS_CODE_LENGTH  = 6;
	const RESUME_TOKEN_BYTES  = 32; // 64 hex chars = 256-bit entropy.
	const RESUME_TOKEN_EXPIRY = 30; // days

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bwg_ai_sessions';
	}

	private static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'bwg_ai_audit_log';
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Create a new session. Returns the full session row on success, WP_Error on failure.
	 *
	 * @param string $email
	 * @param string $website_url Already sanitized + validated.
	 * @return object|WP_Error
	 */
	public static function create( $email, $website_url ) {
		global $wpdb;

		$access_code  = self::generate_access_code();
		$resume_token = self::generate_resume_token();
		$expires      = gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::RESUME_TOKEN_EXPIRY . ' days' ) );

		$inserted = $wpdb->insert(
			self::table(),
			[
				'access_code'          => $access_code,
				'resume_token'         => $resume_token,
				'resume_token_expires' => $expires,
				'email'                => sanitize_email( $email ),
				'website_url'          => esc_url_raw( $website_url ),
				'step_completed'       => 0,
				'status'               => 'active',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', 'Failed to create session.' );
		}

		$session = self::get( $wpdb->insert_id );
		self::log( $session->id, 'session_created', 'Session created for ' . $website_url );

		return $session;
	}

	/**
	 * Update which step the session has completed.
	 */
	public static function update_step( $session_id, $step ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[ 'step_completed' => absint( $step ) ],
			[ 'id' => absint( $session_id ) ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Update session status (active / paused / complete / error).
	 */
	public static function update_status( $session_id, $status ) {
		global $wpdb;
		$allowed = [ 'active', 'paused', 'complete', 'error' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}
		$wpdb->update(
			self::table(),
			[ 'status' => $status ],
			[ 'id' => absint( $session_id ) ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Store the EntityIQ job ID returned when a scrape job is queued.
	 */
	public static function update_entityiq_job_id( $session_id, $job_id ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[ 'entityiq_job_id' => sanitize_text_field( $job_id ) ],
			[ 'id' => absint( $session_id ) ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Rotate the resume token (e.g. after a successful resume to limit reuse).
	 */
	public static function rotate_resume_token( $session_id ) {
		global $wpdb;
		$token   = self::generate_resume_token();
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::RESUME_TOKEN_EXPIRY . ' days' ) );
		$wpdb->update(
			self::table(),
			[
				'resume_token'         => $token,
				'resume_token_expires' => $expires,
			],
			[ 'id' => absint( $session_id ) ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		return $token;
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Fetch a session by primary key.
	 *
	 * @return object|null
	 */
	public static function get( $session_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_sessions` WHERE id = %d LIMIT 1",
				absint( $session_id )
			)
		);
	}

	/**
	 * Fetch a session by access code (case-insensitive).
	 *
	 * @return object|null
	 */
	public static function get_by_access_code( $code ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_sessions` WHERE access_code = %s LIMIT 1",
				strtoupper( sanitize_text_field( $code ) )
			)
		);
	}

	/**
	 * Fetch a session by resume token. Returns null if not found or expired.
	 *
	 * @return object|null
	 */
	public static function get_by_resume_token( $token ) {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_sessions`
				 WHERE resume_token = %s AND resume_token_expires > %s LIMIT 1",
				sanitize_text_field( $token ),
				$now
			)
		);
	}

	/**
	 * Fetch sessions whose followup drip emails are due.
	 * Returns sessions that completed step 0+ but haven't passed step 3,
	 * grouped by how old they are so the caller can pick the right email.
	 *
	 * @param int $days_old
	 * @return object[]
	 */
	public static function get_due_for_followup( $days_old ) {
		global $wpdb;
		$cutoff_start = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_old} days -1 hour" ) );
		$cutoff_end   = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_old} days" ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_sessions`
				 WHERE status = 'active'
				   AND step_completed < 4
				   AND created_at BETWEEN %s AND %s",
				$cutoff_start,
				$cutoff_end
			)
		);
	}

	// -------------------------------------------------------------------------
	// Audit log
	// -------------------------------------------------------------------------

	/**
	 * Write an entry to the audit log.
	 *
	 * @param int|null $session_id
	 * @param string   $action
	 * @param string   $message
	 * @param array    $context  Will be JSON-encoded.
	 */
	public static function log( $session_id, $action, $message = '', $context = [] ) {
		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			[
				'session_id' => $session_id ? absint( $session_id ) : null,
				'action'     => sanitize_text_field( $action ),
				'message'    => sanitize_textarea_field( $message ),
				'context'    => wp_json_encode( $context ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);
	}

	// -------------------------------------------------------------------------
	// Generators
	// -------------------------------------------------------------------------

	private static function generate_access_code() {
		$chars  = self::ACCESS_CODE_CHARS;
		$len    = strlen( $chars );
		$code   = '';
		for ( $i = 0; $i < self::ACCESS_CODE_LENGTH; $i++ ) {
			$code .= $chars[ random_int( 0, $len - 1 ) ];
		}
		return $code;
	}

	private static function generate_resume_token() {
		return bin2hex( random_bytes( self::RESUME_TOKEN_BYTES ) );
	}
}
