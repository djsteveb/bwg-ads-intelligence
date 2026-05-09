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

		// Phase 5 — Access Funnel
		register_rest_route( $ns, $b . '/access-status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'access_status' ],
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

		// EntityIQ webhook (HMAC auth — no WP nonce)
		register_rest_route( $ns, $b . '/entityiq-webhook', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'entityiq_webhook' ],
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
		$session = $this->get_session_or_error( $request->get_param( 'id' ) );
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
		$session    = $this->get_session_or_error( $session_id );
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

		// Queue Phase 2 EntityIQ ad surface job (implemented in M5).
		do_action( 'bwg_ai_queue_ad_surface', $session->id );

		return new WP_REST_Response( [ 'ok' => true, 'step' => 1 ], 200 );
	}

	/**
	 * GET /ad-surface-status/{id}
	 */
	public function ad_surface_status( WP_REST_Request $request ) {
		$session = $this->get_session_or_error( $request->get_param( 'id' ) );
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
			'session_id'     => $session->id,
			'step'           => (int) $session->step_completed,
			'status'         => $session->status,
			'entityiq_job_id'=> $session->entityiq_job_id,
			'ads_found'      => $ad_count,
		], 200 );
	}

	/**
	 * GET /ads/{id}
	 */
	public function get_ads( WP_REST_Request $request ) {
		$session = $this->get_session_or_error( $request->get_param( 'id' ) );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		global $wpdb;
		$ads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, platform, ad_id, ad_copy, ad_image_url, screenshot_path,
				        run_dates, spend_range, user_confirmed, compliance_flags
				 FROM `{$wpdb->prefix}bwg_ai_ads`
				 WHERE session_id = %d
				 ORDER BY platform, id",
				$session->id
			)
		);

		$formatted = array_map( function ( $ad ) {
			$ad->compliance_flags = json_decode( $ad->compliance_flags, true ) ?? [];
			return $ad;
		}, $ads );

		return new WP_REST_Response( [ 'session_id' => $session->id, 'ads' => $formatted ], 200 );
	}

	/**
	 * POST /confirm-ads
	 */
	public function confirm_ads( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id );
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
		$session    = $this->get_session_or_error( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$accounts = $request->get_param( 'accounts' ); // [ { type, identifier } ]
		if ( ! is_array( $accounts ) || empty( $accounts ) ) {
			return $this->error( 'invalid_data', 'accounts must be a non-empty array.', 400 );
		}

		// Sanitize and store hints for next EntityIQ pass.
		$hints = [];
		foreach ( $accounts as $acct ) {
			$hints[] = [
				'type'       => sanitize_text_field( $acct['type'] ?? '' ),
				'identifier' => sanitize_text_field( $acct['identifier'] ?? '' ),
			];
		}

		BWG_AI_Session::log( $session->id, 'accounts_added', 'Additional accounts submitted.', [ 'accounts' => $hints ] );

		// Trigger another EntityIQ surface job with the new hints (M5).
		do_action( 'bwg_ai_queue_ad_surface', $session->id, $hints );

		return new WP_REST_Response( [ 'ok' => true, 'queued' => count( $hints ) ], 200 );
	}

	/**
	 * POST /access-status
	 */
	public function access_status( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$platform = sanitize_text_field( $request->get_param( 'platform' ) );
		$status   = sanitize_text_field( $request->get_param( 'access_status' ) );
		$allowed  = [ 'pending', 'granted', 'export' ];

		if ( ! $platform || ! in_array( $status, $allowed, true ) ) {
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
	 * POST /upload-export
	 */
	public function upload_export( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id );
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

		// Parsing delegated to M8; for now just acknowledge receipt.
		BWG_AI_Session::log( $session->id, 'export_uploaded', "Platform: {$platform}, size: {$file['size']} bytes." );

		// Update access record.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'bwg_ai_access',
			[ 'export_uploaded_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'session_id' => $session->id, 'platform' => $platform ],
			[ '%s' ],
			[ '%d', '%s' ]
		);

		return new WP_REST_Response( [ 'ok' => true, 'platform' => $platform, 'rows_parsed' => 0 ], 200 );
	}

	/**
	 * GET /spider-status/{id}  (Phase 6 — deferred)
	 */
	public function spider_status( WP_REST_Request $request ) {
		return new WP_REST_Response( [ 'status' => 'deferred', 'message' => 'Landing page spider available in Phase 2.' ], 200 );
	}

	/**
	 * GET /report/{token}  (public)
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

		return new WP_REST_Response( [
			'token'        => $token,
			'audience'     => $report->audience_type,
			'generated_at' => $report->generated_at,
			'data'         => json_decode( $report->report_data, true ),
		], 200 );
	}

	/**
	 * POST /email-report
	 */
	public function email_report( WP_REST_Request $request ) {
		$session_id = absint( $request->get_param( 'session_id' ) );
		$session    = $this->get_session_or_error( $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// M9 will implement report generation + email send.
		BWG_AI_Session::log( $session->id, 'report_email_requested', 'Report email send requested.' );

		return new WP_REST_Response( [ 'ok' => true, 'message' => 'Report email queued.' ], 200 );
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

		$session = BWG_AI_Security::resolve_resume(
			$request->get_param( 'access_code' ),
			$request->get_param( 'resume_token' )
		);

		if ( is_wp_error( $session ) ) {
			BWG_AI_Session::log( null, 'resume_failed', 'Failed resume attempt from ' . $ip );
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

	/**
	 * POST /entityiq-webhook
	 * Called by EntityIQ when an ad surface job completes.
	 */
	public function entityiq_webhook( WP_REST_Request $request ) {
		$verified = BWG_AI_Security::verify_webhook_signature( $request );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$payload    = $request->get_json_params();
		$session_id = absint( $payload['session_id'] ?? 0 );
		$job_id     = sanitize_text_field( $payload['job_id'] ?? '' );
		$ads        = $payload['ads'] ?? [];

		if ( ! $session_id ) {
			return $this->error( 'invalid_payload', 'Missing session_id.', 400 );
		}

		$session = BWG_AI_Session::get( $session_id );
		if ( ! $session ) {
			return $this->error( 'session_not_found', 'Session not found.', 404 );
		}

		// Delegate to Ad Surface handler (M5 fills this out).
		do_action( 'bwg_ai_webhook_received', $session_id, $job_id, $ads, $payload );

		BWG_AI_Session::log( $session_id, 'webhook_received', "EntityIQ job {$job_id} — " . count( $ads ) . ' ads.' );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get a session by ID or return a WP_Error if not found.
	 */
	private function get_session_or_error( $id ) {
		$session = BWG_AI_Session::get( absint( $id ) );
		if ( ! $session ) {
			return $this->error( 'session_not_found', 'Session not found.', 404 );
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
