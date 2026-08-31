<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Rest {

	const NAMESPACE = 'bwg/v1';
	const BASE      = 'ai';

	public function register_routes() {
		$ns = self::NAMESPACE;
		$b  = '/' . self::BASE;

		// Phase 1 — Discovery
		register_rest_route( $ns, $b . '/start', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'start' ],
			'permission_callback' => '__return_true', // Rate limit + captcha enforced inside.
		] );

		register_rest_route( $ns, $b . '/discovery-status/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'discovery_status' ],
			'permission_callback' => [ $this, 'require_nonce' ],
			'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
		] );

		register_rest_route( $ns, $b . '/confirm-discovery', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'confirm_discovery' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		// Phase 2 — Ad Surface
		register_rest_route( $ns, $b . '/ad-surface-status/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'ad_surface_status' ],
			'permission_callback' => [ $this, 'require_nonce' ],
			'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
		] );

		register_rest_route( $ns, $b . '/ads/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_ads' ],
			'permission_callback' => [ $this, 'require_nonce' ],
			'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
		] );

		// Phase 4 — Confirm & Expand
		register_rest_route( $ns, $b . '/confirm-ads', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'confirm_ads' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		register_rest_route( $ns, $b . '/add-accounts', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'add_accounts' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		register_rest_route( $ns, $b . '/manual-ads', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'manual_ads' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		// Screenshot serving (signed URL auth — no WP nonce, see bwg_ai_screenshot_url()).
		register_rest_route( $ns, $b . '/screenshot/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_screenshot' ],
			'permission_callback' => '__return_true',
			'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
		] );

		// Phase 5 — Access Funnel
		register_rest_route( $ns, $b . '/access-status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'access_status' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		register_rest_route( $ns, $b . '/request-access', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'request_access' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		register_rest_route( $ns, $b . '/upload-export', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'upload_export' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		// Phase 6 — Spider (deferred; endpoint registered so front-end can poll)
		register_rest_route( $ns, $b . '/spider-status/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'spider_status' ],
			'permission_callback' => [ $this, 'require_nonce' ],
			'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
		] );

		// Reports (public — token-authenticated)
		register_rest_route( $ns, $b . '/report/(?P<token>[a-f0-9\-]{32,64})', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_report' ],
			'permission_callback' => '__return_true',
			'args'                => [ 'token' => [ 'sanitize_callback' => 'sanitize_text_field' ] ],
		] );

		register_rest_route( $ns, $b . '/email-report', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'email_report' ],
			'permission_callback' => [ $this, 'require_nonce' ],
		] );

		// Resume (access code / token auth — no WP nonce)
		register_rest_route( $ns, $b . '/resume', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'resume' ],
			'permission_callback' => '__return_true',
		] );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function require_nonce( WP_REST_Request $request ) {
		$result = BWG_AI_Security::verify_nonce( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Endpoint handlers
	// -------------------------------------------------------------------------

	/**
	 * POST /start
	 * Creates a new audit session. Returns session_id, access_code, resume_token.
	 */
	public function start( WP_REST_Request $request ) {
		$ip = BWG_AI_Security::get_client_ip();

		// Rate limits.
		if ( ! BWG_AI_Rate_Limiter::check_endpoint( 'start', $ip ) ) {
			$retry = BWG_AI_Rate_Limiter::retry_after( 'start', $ip );
			return $this->error( 'rate_limited', 'Too many requests. Please try again later.', 429, [ 'retry_after' => $retry ] );
		}
		if ( ! BWG_AI_Rate_Limiter::check_endpoint( 'start_daily', $ip ) ) {
			return $this->error( 'rate_limited_daily', 'Daily limit reached. Please try again tomorrow.', 429 );
		}

		// Captcha.
		$captcha = BWG_AI_Security::verify_captcha(
			$request->get_param( 'captcha_token' ),
			$ip
		);
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		// Inputs.
		$url = BWG_AI_Security::sanitize_url_input( $request->get_param( 'website_url' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$email = BWG_AI_Security::sanitize_email_input( $request->get_param( 'email' ) );
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		// Create session.
		$session = BWG_AI_Session::create( $email, $url );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// Fire save-spot email so the user has their access code and resume link.
		do_action( 'bwg_ai_session_created', $session );

		// Schedule Phase 1 discovery cron to run in 5 seconds.
		wp_schedule_single_event( time() + 5, 'bwg_ai_run_discovery', [ $session->id ] );

		return new WP_REST_Response( [
			'session_id'   => $session->id,
			'access_code'  => $session->access_code,
			'resume_token' => $session->resume_token,
			'step'         => 0,
		], 201 );
	}

	/**
	 * GET /discovery-status/{id}
	 */
	public function discovery_status( WP_REST_Request $request ) {
		$session = $this->get_session_or_error( $request->get_param( 'id' ), $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		global $wpdb;
		$discovered = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_discovered` WHERE session_id = %d LIMIT 1",
				$session->id
			)
		);

		$confidence = $discovered ? json_decode( $discovered->discovery_confidence, true ) : null;
		$flags      = $discovered ? json_decode( $discovered->discovery_flags, true ) : null;

		return new WP_REST_Response( [
			'session_id'   => $session->id,
			'step'         => (int) $session->step_completed,
			'status'       => $session->status,
			'progress_pct' => $confidence['progress_pct'] ?? ( $discovered ? 100 : 0 ),
			'discovered'   => $discovered ? $this->format_discovered( $discovered ) : null,
			'flags'        => $flags,
		], 200 );
	}

	/**
	 * POST /confirm-discovery
	 */
	public function confirm_discovery( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bwg_ai_discovered';

		$fields = [
			'business_name'    => sanitize_text_field( $request->get_param( 'business_name' ) ?? '' ),
			'business_address' => sanitize_text_field( $request->get_param( 'business_address' ) ?? '' ),
			'business_phone'   => sanitize_text_field( $request->get_param( 'business_phone' ) ?? '' ),
		];

		// Remove nulls so we only update what was sent.
		$fields = array_filter( $fields, fn( $v ) => $v !== '' );

		if ( ! empty( $fields ) ) {
			$wpdb->update(
				$table,
				$fields,
				[ 'session_id' => $session->id ],
				array_fill( 0, count( $fields ), '%s' ),
				[ '%d' ]
			);
		}

		BWG_AI_Session::update_step( $session->id, 1 );
		BWG_AI_Session::log( $session->id, 'discovery_confirmed', 'User confirmed discovery data.' );

		// Queue Phase 2 Meta Ad Library lookup.
		do_action( 'bwg_ai_queue_ad_surface', $session->id );

		return new WP_REST_Response( [ 'ok' => true, 'step' => 1 ], 200 );
	}

	/**
	 * GET /ad-surface-status/{id}
	 */
	public function ad_surface_status( WP_REST_Request $request ) {
		$session = $this->get_session_or_error( $request->get_param( 'id' ), $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		global $wpdb;
		$ad_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}bwg_ai_ads` WHERE session_id = %d",
				$session->id
			)
		);

		return new WP_REST_Response( [
			'session_id'        => $session->id,
			'step'              => (int) $session->step_completed,
			'status'            => $session->status,
			'ads_found'         => $ad_count,
			'meta_configured'   => BWG_AI_Meta_Ad_Library::is_configured(),
			'google_configured' => BWG_AI_Google_Transparency::is_configured(),
		], 200 );
	}

	/**
	 * GET /ads/{id}
	 */
	public function get_ads( WP_REST_Request $request ) {
		$session = $this->get_session_or_error( $request->get_param( 'id' ), $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		global $wpdb;
		$ads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, platform, ad_id, ad_copy, ad_image_url, ad_snapshot_url, screenshot_path,
				        run_dates, spend_range, user_confirmed, compliance_flags, vision_analysis, source
				 FROM `{$wpdb->prefix}bwg_ai_ads`
				 WHERE session_id = %d
				 ORDER BY platform, id",
				$session->id
			)
		);

		$formatted = array_map( function ( $ad ) {
			$ad->compliance_flags = json_decode( $ad->compliance_flags, true ) ?? [];
			$ad->screenshot_url   = $ad->screenshot_path ? bwg_ai_screenshot_url( $ad->id ) : '';
			$vision                = json_decode( $ad->vision_analysis, true );
			$ad->vision_analyzed   = ! empty( $vision['analyzed'] );
			unset( $ad->vision_analysis, $ad->screenshot_path );
			return $ad;
		}, $ads );

		return new WP_REST_Response( [ 'session_id' => $session->id, 'ads' => $formatted ], 200 );
	}

	/**
	 * GET /screenshot/{id}
	 * Streams a stored screenshot file. Auth is a short-lived HMAC-signed
	 * URL (see bwg_ai_screenshot_url()) rather than a WP nonce, since this
	 * is loaded directly by <img> tags that can't attach custom headers.
	 */
	public function get_screenshot( WP_REST_Request $request ) {
		$ad_id   = absint( $request->get_param( 'id' ) );
		$expires = absint( $request->get_param( 'expires' ) );
		$sig     = sanitize_text_field( (string) $request->get_param( 'sig' ) );

		if ( ! $ad_id || ! $expires || ! $sig ) {
			return $this->error( 'invalid_request', 'Missing signature.', 400 );
		}
		if ( $expires < time() ) {
			return $this->error( 'link_expired', 'This screenshot link has expired.', 410 );
		}
		if ( ! hash_equals( bwg_ai_sign_screenshot_url( $ad_id, $expires ), $sig ) ) {
			return $this->error( 'invalid_signature', 'Invalid signature.', 403 );
		}

		global $wpdb;
		$path = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT screenshot_path FROM `{$wpdb->prefix}bwg_ai_ads` WHERE id = %d LIMIT 1",
				$ad_id
			)
		);

		$full = $path ? BWG_AI_Screenshot_Store::full_path( $path ) : false;
		if ( ! $full ) {
			return $this->error( 'not_found', 'Screenshot not found.', 404 );
		}

		$filetype = wp_check_filetype( $full );
		$mime     = $filetype['type'] ?: 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $full ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_readfile -- streaming a local, path-validated image file.
		readfile( $full );
		exit;
	}

	/**
	 * POST /confirm-ads
	 */
	public function confirm_ads( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$confirmations = $request->get_param( 'confirmations' ); // [ { ad_id, confirmed: bool } ]
		if ( ! is_array( $confirmations ) ) {
			return $this->error( 'invalid_data', 'confirmations must be an array.', 400 );
		}

		global $wpdb;
		foreach ( $confirmations as $item ) {
			$ad_id     = absint( $item['ad_id'] ?? 0 );
			$confirmed = ! empty( $item['confirmed'] ) ? 1 : 0;
			if ( ! $ad_id ) {
				continue;
			}
			$wpdb->update(
				$wpdb->prefix . 'bwg_ai_ads',
				[ 'user_confirmed' => $confirmed ],
				[ 'id' => $ad_id, 'session_id' => $session->id ],
				[ '%d' ],
				[ '%d', '%d' ]
			);
		}

		BWG_AI_Session::update_step( $session->id, 3 );
		BWG_AI_Session::log( $session->id, 'ads_confirmed', count( $confirmations ) . ' ads confirmed/flagged.' );

		return new WP_REST_Response( [ 'ok' => true, 'step' => 3 ], 200 );
	}

	/**
	 * POST /add-accounts
	 */
	public function add_accounts( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$accounts = $request->get_param( 'accounts' ); // [ { type, identifier } ]
		if ( ! is_array( $accounts ) || empty( $accounts ) ) {
			return $this->error( 'invalid_data', 'accounts must be a non-empty array.', 400 );
		}

		// Sanitize and store hints for next Meta Ad Library pass.
		$hints = [];
		foreach ( $accounts as $acct ) {
			$hints[] = [
				'type'       => sanitize_text_field( $acct['type'] ?? '' ),
				'identifier' => sanitize_text_field( $acct['identifier'] ?? '' ),
			];
		}

		BWG_AI_Session::log( $session->id, 'accounts_added', 'Additional accounts submitted.', [ 'accounts' => $hints ] );

		// Trigger another Meta Ad Library lookup with the new hints.
		do_action( 'bwg_ai_queue_ad_surface', $session->id, $hints );

		return new WP_REST_Response( [ 'ok' => true, 'queued' => count( $hints ) ], 200 );
	}

	/**
	 * POST /manual-ads
	 * Saves ads the user pasted in by hand (Ad Library snapshot URL + optional
	 * copy) — the fallback path when no Meta Ad Library token is configured.
	 */
	public function manual_ads( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$entries  = $request->get_param( 'ads' ); // [ { ad_snapshot_url, ad_copy? } ]
		$platform = sanitize_text_field( $request->get_param( 'platform' ) ?: 'meta' );
		if ( ! in_array( $platform, [ 'meta', 'google' ], true ) ) {
			return $this->error( 'invalid_platform', 'Invalid platform.', 400 );
		}
		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return $this->error( 'invalid_data', 'ads must be a non-empty array.', 400 );
		}

		if ( count( $entries ) > 25 ) {
			return $this->error( 'too_many', 'Please submit 25 ads or fewer at a time.', 400 );
		}

		$saved = ( new BWG_AI_Ad_Surface() )->save_manual_ads( $session->id, $platform, $entries );

		BWG_AI_Session::log( $session->id, 'manual_ads_submitted', "{$saved} manually entered ads saved." );

		return new WP_REST_Response( [ 'ok' => true, 'saved' => $saved ], 200 );
	}

	/**
	 * POST /access-status
	 */
	public function access_status( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$platform          = sanitize_text_field( $request->get_param( 'platform' ) );
		$status            = sanitize_text_field( $request->get_param( 'access_status' ) );
		$allowed_platforms = [ 'meta', 'google', 'linkedin', 'tiktok' ];
		$allowed_statuses  = [ 'pending', 'granted', 'export' ];

		if ( ! in_array( $platform, $allowed_platforms, true ) || ! in_array( $status, $allowed_statuses, true ) ) {
			return $this->error( 'invalid_data', 'Invalid platform or access_status.', 400 );
		}

		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'bwg_ai_access',
			[
				'session_id'    => $session->id,
				'platform'      => $platform,
				'access_status' => $status,
				'access_granted_at' => $status === 'granted' ? gmdate( 'Y-m-d H:i:s' ) : null,
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		BWG_AI_Session::log( $session->id, 'access_status_updated', "{$platform} → {$status}" );

		return new WP_REST_Response( [ 'ok' => true, 'platform' => $platform, 'status' => $status ], 200 );
	}

	/**
	 * POST /request-access
	 * Records that the user has requested platform access and fires the step-by-step email.
	 */
	public function request_access( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$platform = sanitize_text_field( $request->get_param( 'platform' ) );
		$allowed  = [ 'meta', 'google', 'linkedin', 'tiktok' ];
		if ( ! in_array( $platform, $allowed, true ) ) {
			return $this->error( 'invalid_platform', 'Invalid platform.', 400 );
		}

		global $wpdb;
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT access_status FROM `{$wpdb->prefix}bwg_ai_access`
				 WHERE session_id = %d AND platform = %s LIMIT 1",
				$session->id,
				$platform
			)
		);

		// Don't downgrade a previously granted or export status.
		if ( $existing && in_array( $existing->access_status, [ 'granted', 'export' ], true ) ) {
			return new WP_REST_Response( [ 'ok' => true, 'platform' => $platform, 'status' => $existing->access_status, 'already_requested' => true ], 200 );
		}

		$wpdb->replace(
			$wpdb->prefix . 'bwg_ai_access',
			[
				'session_id'    => $session->id,
				'platform'      => $platform,
				'access_status' => 'pending',
			],
			[ '%d', '%s', '%s' ]
		);

		BWG_AI_Session::log( $session->id, 'access_requested', "{$platform} access requested." );

		if ( class_exists( 'BWG_AI_Email' ) ) {
			( new BWG_AI_Email() )->send_access_request( $session, $platform );
		}

		return new WP_REST_Response( [ 'ok' => true, 'platform' => $platform, 'status' => 'pending' ], 200 );
	}

	/**
	 * POST /upload-export
	 */
	public function upload_export( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$ip = BWG_AI_Security::get_client_ip();
		if ( ! BWG_AI_Rate_Limiter::check_session( 'upload_export', $session->id ) ) {
			return $this->error( 'rate_limited', 'Upload limit reached for this session. Try again later.', 429 );
		}

		$files = $request->get_file_params();
		if ( empty( $files['export_file'] ) ) {
			return $this->error( 'no_file', 'No file uploaded.', 400 );
		}

		$file     = $files['export_file'];
		$platform = sanitize_text_field( $request->get_param( 'platform' ) );

		// MIME validation — only CSV.
		$allowed_mimes = [ 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' ];
		$mime          = mime_content_type( $file['tmp_name'] );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return $this->error( 'invalid_file_type', 'Only CSV files are accepted.', 400 );
		}

		// Size limit: 10MB.
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return $this->error( 'file_too_large', 'File must be under 10MB.', 400 );
		}

		// Parse CSV and merge into ads table.
		$rows_parsed = 0;
		if ( 'meta' === $platform ) {
			$rows_parsed = $this->parse_meta_csv( $file['tmp_name'], $session->id );
		} elseif ( 'google' === $platform ) {
			$rows_parsed = $this->parse_google_csv( $file['tmp_name'], $session->id );
		}

		BWG_AI_Session::log( $session->id, 'export_uploaded', "Platform: {$platform}, size: {$file['size']} bytes, rows: {$rows_parsed}." );

		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'bwg_ai_access',
			[
				'session_id'         => $session->id,
				'platform'           => $platform,
				'access_status'      => 'export',
				'export_uploaded_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return new WP_REST_Response( [ 'ok' => true, 'platform' => $platform, 'rows_parsed' => $rows_parsed ], 200 );
	}

	/**
	 * GET /spider-status/{id}  (Phase 6 — deferred)
	 */
	public function spider_status( WP_REST_Request $request ) {
		return new WP_REST_Response( [ 'status' => 'deferred', 'message' => 'Landing page spider available in Phase 2.' ], 200 );
	}

	/**
	 * GET /report/{token}  (public)
	 * Browser requests (Accept: text/html) receive the rendered HTML template.
	 * API requests receive JSON.
	 */
	public function get_report( WP_REST_Request $request ) {
		$token = sanitize_text_field( $request->get_param( 'token' ) );

		global $wpdb;
		$report = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_reports`
				 WHERE report_token = %s
				   AND (expires_at IS NULL OR expires_at > %s)
				 LIMIT 1",
				$token,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( ! $report ) {
			return $this->error( 'report_not_found', 'Report not found or expired.', 404 );
		}

		$report_data = json_decode( $report->report_data, true ) ?? [];

		// Browser request — render HTML template.
		$accept = $request->get_header( 'Accept' ) ?? '';
		if ( strpos( $accept, 'text/html' ) !== false ) {
			// Explicitly assign template variables from stored JSON (no extract() — avoids injecting
			// unexpected variables if stored JSON is ever tampered with).
			$business_name     = isset( $report_data['business_name'] )     ? (string) $report_data['business_name']     : '';
			$website_url       = isset( $report_data['website_url'] )       ? (string) $report_data['website_url']       : '';
			$risk_score        = isset( $report_data['risk_score'] )        ? (int)    $report_data['risk_score']        : 0;
			$wasted_spend      = isset( $report_data['wasted_spend'] )      ? $report_data['wasted_spend']               : null;
			$top_actions       = isset( $report_data['top_actions'] )       ? (array)  $report_data['top_actions']       : [];
			$platform_snapshot = isset( $report_data['platform_snapshot'] ) ? (array)  $report_data['platform_snapshot'] : [];
			$whats_working     = isset( $report_data['whats_working'] )     ? (array)  $report_data['whats_working']     : [];
			$flag_counts       = isset( $report_data['flag_counts'] )       ? (array)  $report_data['flag_counts']       : [ 'high' => 0, 'medium' => 0, 'low' => 0 ];
			$total_ads         = isset( $report_data['total_ads'] )         ? (int)    $report_data['total_ads']         : 0;
			$generated_at      = $report->generated_at;
			$report_token      = $token;

			$template = BWG_AI_DIR . 'admin/partials/report-template.php';
			if ( file_exists( $template ) ) {
				// Output HTML directly and exit to bypass WP JSON envelope.
				ob_start();
				include $template;
				$html = ob_get_clean();
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes all output.
				echo $html;
				exit;
			}
		}

		// JSON response for API clients.
		return new WP_REST_Response( [
			'token'        => $token,
			'audience'     => $report->audience_type,
			'generated_at' => $report->generated_at,
			'data'         => $report_data,
		], 200 );
	}

	/**
	 * POST /email-report
	 * Generates the executive report and emails the link to the session owner.
	 */
	public function email_report( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id, $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$token = BWG_AI_Report::generate( $session->id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$report_url = rest_url( 'bwg/v1/ai/report/' . $token );

		if ( class_exists( 'BWG_AI_Email' ) ) {
			( new BWG_AI_Email() )->send_report_ready( $session, $token );
		}

		BWG_AI_Session::update_step( $session->id, 5 );
		BWG_AI_Session::log( $session->id, 'report_emailed', "Token: {$token}" );

		return new WP_REST_Response( [
			'ok'         => true,
			'token'      => $token,
			'report_url' => $report_url,
		], 200 );
	}

	/**
	 * POST /resume
	 * Authenticate by access code or resume token. Returns session state.
	 */
	public function resume( WP_REST_Request $request ) {
		$ip = BWG_AI_Security::get_client_ip();

		if ( ! BWG_AI_Rate_Limiter::check_endpoint( 'resume', $ip ) ) {
			$retry = BWG_AI_Rate_Limiter::retry_after( 'resume', $ip );
			return $this->error( 'rate_limited', 'Too many attempts. Please wait before trying again.', 429, [ 'retry_after' => $retry ] );
		}

		$access_code  = $request->get_param( 'access_code' );
		$resume_token = $request->get_param( 'resume_token' );

		// Require captcha for access-code resumes (brute-force vector).
		// Token resumes (64-char hex, 256-bit entropy) don't need it.
		if ( ! empty( $access_code ) && empty( $resume_token ) ) {
			$captcha = BWG_AI_Security::verify_captcha( $request->get_param( 'captcha_token' ), $ip );
			if ( is_wp_error( $captcha ) ) {
				return $captcha;
			}
		}

		// Access-code lockout: block after 5 wrong guesses per IP per hour.
		$lockout_key = 'access_code_fail:' . $ip;
		if ( ! empty( $access_code ) && empty( $resume_token ) ) {
			if ( BWG_AI_Rate_Limiter::is_locked( $lockout_key, 5, 3600 ) ) {
				return $this->error( 'access_code_locked', 'Too many incorrect access code attempts. Please try again later.', 429 );
			}
		}

		$session = BWG_AI_Security::resolve_resume(
			$access_code,
			$resume_token
		);

		if ( is_wp_error( $session ) ) {
			BWG_AI_Session::log( null, 'resume_failed', 'Failed resume attempt from ' . $ip );
			// Increment failure counter only for access-code attempts (token has 256-bit entropy).
			if ( ! empty( $access_code ) && empty( $resume_token ) ) {
				BWG_AI_Rate_Limiter::increment( $lockout_key, 3600 );
			}
			return $session;
		}

		// Rotate the token on successful resume so each link is single-use.
		$new_token = BWG_AI_Session::rotate_resume_token( $session->id );
		BWG_AI_Session::log( $session->id, 'session_resumed', 'Session resumed from ' . $ip );

		global $wpdb;
		$discovered = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_discovered` WHERE session_id = %d LIMIT 1",
				$session->id
			)
		);

		return new WP_REST_Response( [
			'session_id'   => $session->id,
			'resume_token' => $new_token,
			'access_code'  => $session->access_code,
			'step'         => (int) $session->step_completed,
			'status'       => $session->status,
			'website_url'  => $session->website_url,
			'discovered'   => $discovered ? $this->format_discovered( $discovered ) : null,
		], 200 );
	}

	// -------------------------------------------------------------------------
	// CSV parsers
	// -------------------------------------------------------------------------

	/**
	 * Parse a Meta Ads Manager CSV export and upsert rows into wp_bwg_ai_ads.
	 * Expected columns (subset): Ad ID, Ad name, Ad status, Results, Reach, Impressions,
	 * Cost per result, Amount spent (USD), Starts, Ends
	 *
	 * @return int Rows successfully parsed.
	 */
	private function parse_meta_csv( $filepath, $session_id ) {
		$handle = fopen( $filepath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return 0;
		}

		$header = null;
		$count  = 0;
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_ai_ads';

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( $header === null ) {
				// Normalise header names: lower-case, trim.
				$header = array_map( fn( $h ) => strtolower( trim( $h ) ), $row );
				continue;
			}

			if ( count( $row ) !== count( $header ) ) {
				continue;
			}

			$data = array_combine( $header, $row );

			$ad_id     = sanitize_text_field( $data['ad id'] ?? '' );
			$ad_name   = sanitize_text_field( $data['ad name'] ?? '' );
			$status    = sanitize_text_field( $data['ad status'] ?? '' );
			$starts    = sanitize_text_field( $data['starts'] ?? '' );
			$ends      = sanitize_text_field( $data['ends'] ?? '' );
			$spent     = sanitize_text_field( $data['amount spent (usd)'] ?? ( $data['amount spent'] ?? '' ) );

			if ( empty( $ad_id ) ) {
				continue;
			}

			$run_dates  = $starts && $ends ? "{$starts} – {$ends}" : ( $starts ?: '' );
			$spend_range = $spent ? "\${$spent}" : '';

			// Check for existing row to preserve compliance_flags.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE session_id = %d AND platform = 'meta' AND ad_id = %s LIMIT 1",
					$session_id,
					$ad_id
				)
			);

			if ( $existing ) {
				$wpdb->update(
					$table,
					[
						'run_dates'   => $run_dates,
						'spend_range' => $spend_range,
					],
					[ 'id' => $existing ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
			} else {
				$wpdb->insert(
					$table,
					[
						'session_id'  => $session_id,
						'platform'    => 'meta',
						'ad_id'       => $ad_id,
						'ad_copy'     => $ad_name,
						'run_dates'   => $run_dates,
						'spend_range' => $spend_range,
					],
					[ '%d', '%s', '%s', '%s', '%s', '%s' ]
				);
			}

			$count++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $count;
	}

	/**
	 * Parse a Google Ads CSV export and upsert rows into wp_bwg_ai_ads.
	 * Standard Google Ads report: Campaign, Ad group, Ad, Status, Clicks, Impr., CTR, Avg. CPC, Cost, ...
	 *
	 * @return int Rows successfully parsed.
	 */
	private function parse_google_csv( $filepath, $session_id ) {
		$handle = fopen( $filepath, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			return 0;
		}

		$header = null;
		$count  = 0;
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_ai_ads';

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			// Google Ads CSVs often have header/footer rows like "Google Ads" or "Total:".
			if ( count( $row ) < 3 ) {
				continue;
			}

			if ( $header === null ) {
				$candidate = array_map( fn( $h ) => strtolower( trim( $h ) ), $row );
				// Detect the actual header row by checking for known columns.
				if ( in_array( 'campaign', $candidate, true ) || in_array( 'ad', $candidate, true ) ) {
					$header = $candidate;
				}
				continue;
			}

			if ( count( $row ) !== count( $header ) ) {
				continue;
			}

			$data = array_combine( $header, $row );

			// Skip summary rows.
			$campaign = sanitize_text_field( $data['campaign'] ?? '' );
			if ( empty( $campaign ) || strtolower( $campaign ) === 'total' ) {
				continue;
			}

			$ad_group = sanitize_text_field( $data['ad group'] ?? ( $data['ad group name'] ?? '' ) );
			$ad_text  = sanitize_text_field( $data['ad'] ?? ( $data['ad name'] ?? ( $data['description'] ?? '' ) ) );
			$cost     = sanitize_text_field( $data['cost'] ?? ( $data['cost (usd)'] ?? '' ) );

			// Synthetic stable ID.
			$ad_id = md5( $session_id . '|' . $campaign . '|' . $ad_group . '|' . $ad_text );

			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE session_id = %d AND platform = 'google' AND ad_id = %s LIMIT 1",
					$session_id,
					$ad_id
				)
			);

			$spend_range = $cost ? "\${$cost}" : '';

			if ( $existing ) {
				$wpdb->update(
					$table,
					[ 'spend_range' => $spend_range ],
					[ 'id' => $existing ],
					[ '%s' ],
					[ '%d' ]
				);
			} else {
				$wpdb->insert(
					$table,
					[
						'session_id'  => $session_id,
						'platform'    => 'google',
						'ad_id'       => $ad_id,
						'ad_copy'     => $ad_text ?: "{$campaign} / {$ad_group}",
						'spend_range' => $spend_range,
					],
					[ '%d', '%s', '%s', '%s', '%s' ]
				);
			}

			$count++;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $count;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get a session by ID or return a WP_Error if not found.
	 *
	 * When $request is supplied the caller's session ownership is verified via
	 * the X-BWG-Session-Token header (must equal the session's resume_token).
	 * WordPress admins bypass this check so the admin panel works without tokens.
	 */
	private function get_session_or_error( $id, WP_REST_Request $request = null ) {
		$session = BWG_AI_Session::get( absint( $id ) );
		if ( ! $session ) {
			return $this->error( 'session_not_found', 'Session not found.', 404 );
		}

		if ( $request && ! current_user_can( 'manage_options' ) ) {
			$token = $request->get_header( 'X-BWG-Session-Token' );
			if ( ! $token || ! hash_equals( $session->resume_token, sanitize_text_field( $token ) ) ) {
				return $this->error( 'session_access_denied', 'Not authorised to access this session.', 403 );
			}
		}

		return $session;
	}

	/**
	 * Format a discovered row for API output — strips raw DB columns, decodes JSON.
	 */
	private function format_discovered( $row ) {
		return [
			'business_name'    => $row->business_name,
			'business_address' => $row->business_address,
			'business_phone'   => $row->business_phone,
			'gbp'              => [
				'place_id'     => $row->gbp_place_id,
				'rating'       => $row->gbp_rating,
				'review_count' => $row->gbp_review_count,
				'category'     => $row->gbp_category,
			],
			'social'           => [
				'facebook'  => $row->social_facebook_url,
				'instagram' => $row->social_instagram_url,
				'linkedin'  => $row->social_linkedin_url,
				'tiktok'    => $row->social_tiktok_url,
				'youtube'   => $row->social_youtube_url,
			],
			'pixels'           => [
				'meta'     => $row->pixel_meta_id,
				'gtm'      => $row->pixel_gtm_id,
				'ga4'      => $row->pixel_ga4_id,
				'tiktok'   => $row->pixel_tiktok_id,
				'linkedin' => $row->pixel_linkedin_id,
			],
			'whois'            => [
				'registrar'    => $row->whois_registrar,
				'created_at'   => $row->whois_created_at,
				'expires_at'   => $row->whois_expires_at,
				'nameservers'  => $row->whois_nameservers,
			],
			'tech_stack'       => json_decode( $row->tech_stack, true ),
			'legitscript'      => $row->legitscript_status,
			'licensure_signals'=> $row->licensure_signals,
			'confidence'       => json_decode( $row->discovery_confidence, true ),
		];
	}

	/**
	 * Helper to return a consistent error response.
	 */
	private function error( $code, $message, $status = 400, $extra = [] ) {
		return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}
}
