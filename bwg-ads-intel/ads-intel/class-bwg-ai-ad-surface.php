<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Ad_Surface {

	// -------------------------------------------------------------------------
	// Public API (called via do_action / cron)
	// -------------------------------------------------------------------------

	/**
	 * Queue an EntityIQ ad surface job after Phase 1 confirm.
	 *
	 * Hooked to: bwg_ai_queue_ad_surface (args: session_id, hints=[])
	 *
	 * @param int   $session_id
	 * @param array $hints  Extra account identifiers added by the user.
	 */
	public function queue_job( $session_id, $hints = [] ) {
		$session_id = absint( $session_id );
		$session    = BWG_AI_Session::get( $session_id );
		if ( ! $session ) {
			return;
		}

		$entityiq_url = get_option( 'bwg_ai_entityiq_url', '' );
		if ( ! $entityiq_url ) {
			BWG_AI_Session::log( $session_id, 'entityiq_skip', 'EntityIQ URL not configured — skipping ad surface job.' );
			return;
		}

		global $wpdb;
		$discovered = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_discovered` WHERE session_id = %d LIMIT 1",
				$session_id
			)
		);

		$payload = wp_json_encode( [
			'session_id'       => $session_id,
			'website_url'      => $session->website_url,
			'platforms'        => [ 'meta' ],
			'advertiser_hints' => $this->build_hints( $session, $discovered, $hints ),
		] );

		$response = $this->post_to_entityiq( '/ads/surface', $payload );

		if ( is_wp_error( $response ) ) {
			BWG_AI_Session::log( $session_id, 'entityiq_error', 'Could not reach EntityIQ: ' . $response->get_error_message() );
			BWG_AI_Session::update_status( $session_id, 'error' );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 202 !== $code || empty( $body['job_id'] ) ) {
			BWG_AI_Session::log( $session_id, 'entityiq_error', "Unexpected response {$code} from EntityIQ surface endpoint." );
			BWG_AI_Session::update_status( $session_id, 'error' );
			return;
		}

		$job_id = sanitize_text_field( $body['job_id'] );
		BWG_AI_Session::update_entityiq_job_id( $session_id, $job_id );
		BWG_AI_Session::log( $session_id, 'entityiq_job_queued', "EntityIQ job queued: {$job_id}" );

		// Schedule a backup poll in 30s in case the webhook never arrives.
		if ( ! wp_next_scheduled( 'bwg_ai_poll_entityiq', [ $session_id ] ) ) {
			wp_schedule_single_event( time() + 30, 'bwg_ai_poll_entityiq', [ $session_id ] );
		}
	}

	/**
	 * Poll EntityIQ for job completion. Called by cron every 30s as a backup
	 * to the webhook — the webhook is the primary completion signal.
	 *
	 * Hooked to: bwg_ai_poll_entityiq (arg: session_id)
	 *
	 * @param int $session_id
	 */
	public function poll( $session_id ) {
		if ( ! wp_doing_cron() ) {
			return;
		}

		$session_id = absint( $session_id );
		$session    = BWG_AI_Session::get( $session_id );

		if ( ! $session || ! $session->entityiq_job_id ) {
			return;
		}

		// Session already past ad surface step — webhook already handled it.
		if ( (int) $session->step_completed >= 2 ) {
			return;
		}

		$response = $this->get_from_entityiq( '/ads/surface/' . rawurlencode( $session->entityiq_job_id ) );

		if ( is_wp_error( $response ) ) {
			BWG_AI_Session::log( $session_id, 'entityiq_poll_error', $response->get_error_message() );
			wp_schedule_single_event( time() + 30, 'bwg_ai_poll_entityiq', [ $session_id ] );
			return;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$status = $body['status'] ?? 'unknown';

		if ( 'complete' === $status ) {
			$this->save_ads( $session_id, $session->entityiq_job_id, $body['ads'] ?? [] );

		} elseif ( 'error' === $status ) {
			BWG_AI_Session::update_status( $session_id, 'error' );
			BWG_AI_Session::log( $session_id, 'entityiq_job_error', $body['error'] ?? 'EntityIQ job failed.' );

		} else {
			// Still running — reschedule.
			wp_schedule_single_event( time() + 30, 'bwg_ai_poll_entityiq', [ $session_id ] );
		}
	}

	/**
	 * Handle the EntityIQ webhook callback.
	 *
	 * HMAC signature is already verified by the REST endpoint before this action fires.
	 * Hooked to: bwg_ai_webhook_received (args: session_id, job_id, ads, payload)
	 *
	 * @param int    $session_id
	 * @param string $job_id
	 * @param array  $ads
	 * @param array  $payload  Full webhook payload (available for future extensions).
	 */
	public function handle_webhook( $session_id, $job_id, $ads, $payload ) {
		$session_id = absint( $session_id );
		$job_id     = sanitize_text_field( $job_id );

		$session = BWG_AI_Session::get( $session_id );
		if ( ! $session ) {
			return;
		}

		// Avoid double-processing if the backup poll cron already handled this.
		if ( (int) $session->step_completed >= 2 ) {
			BWG_AI_Session::log( $session_id, 'webhook_duplicate', "Webhook for job {$job_id} ignored — ads already saved." );
			return;
		}

		$this->save_ads( $session_id, $job_id, (array) $ads );
	}

	// -------------------------------------------------------------------------
	// Internal: save + compliance + email
	// -------------------------------------------------------------------------

	/**
	 * Persist ads from EntityIQ, run compliance on each, advance session step,
	 * cancel the backup poll cron, and fire the ads-preview drip email.
	 *
	 * @param int    $session_id
	 * @param string $job_id
	 * @param array  $ads  Normalized ad objects from EntityIQ.
	 */
	private function save_ads( $session_id, $job_id, $ads ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_ai_ads';
		$saved = 0;

		foreach ( $ads as $ad ) {
			$platform     = sanitize_text_field( $ad['platform'] ?? 'meta' );
			$ad_id_ext    = sanitize_text_field( $ad['ad_id'] ?? '' );
			$ad_copy      = sanitize_textarea_field( $ad['ad_copy'] ?? '' );
			$ad_image_url = esc_url_raw( $ad['ad_image_url'] ?? '' );
			$screenshot   = sanitize_text_field( $ad['screenshot_path'] ?? '' );
			$run_dates    = sanitize_text_field( $ad['run_dates'] ?? '' );
			$spend_range  = sanitize_text_field( $ad['spend_range'] ?? '' );

			// Run text compliance. BWG_AI_Compliance::analyze_ad_copy() is
			// implemented in M6; until then this returns an empty array.
			$flags = $this->run_compliance( $ad_copy, $platform );

			$wpdb->insert(
				$table,
				[
					'session_id'       => $session_id,
					'platform'         => $platform,
					'ad_id'            => $ad_id_ext,
					'ad_copy'          => $ad_copy,
					'ad_image_url'     => $ad_image_url,
					'screenshot_path'  => $screenshot,
					'run_dates'        => $run_dates,
					'spend_range'      => $spend_range,
					'compliance_flags' => wp_json_encode( $flags ),
					'user_confirmed'   => 0,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
			);

			if ( $wpdb->insert_id ) {
				$saved++;
			}
		}

		// Cancel backup poll cron — job is resolved.
		$ts = wp_next_scheduled( 'bwg_ai_poll_entityiq', [ $session_id ] );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'bwg_ai_poll_entityiq', [ $session_id ] );
		}

		BWG_AI_Session::update_step( $session_id, 2 );
		BWG_AI_Session::log( $session_id, 'ads_saved', "{$saved} ads saved from EntityIQ job {$job_id}." );

		// Fire the ads-preview email so the prospect knows results are ready.
		$session = BWG_AI_Session::get( $session_id );
		if ( $session ) {
			( new BWG_AI_Email() )->send_ads_preview( $session, $saved );
		}
	}

	/**
	 * Delegate to BWG_AI_Compliance when available. Implemented fully in M6.
	 *
	 * @return array  compliance_flags[]
	 */
	private function run_compliance( $ad_copy, $platform ) {
		if ( class_exists( 'BWG_AI_Compliance' ) ) {
			return BWG_AI_Compliance::analyze_ad_copy( $ad_copy, $platform );
		}
		return [];
	}

	/**
	 * Build the advertiser_hints payload from discovered data + any user extras.
	 */
	private function build_hints( $session, $discovered, $extra_hints = [] ) {
		$hints = [
			'website_url' => $session->website_url,
		];

		if ( $discovered ) {
			if ( ! empty( $discovered->business_name ) ) {
				$hints['business_name'] = $discovered->business_name;
			}
			if ( ! empty( $discovered->social_facebook_url ) ) {
				$hints['facebook_page_url'] = $discovered->social_facebook_url;
			}
			if ( ! empty( $discovered->pixel_meta_id ) ) {
				$hints['meta_pixel_id'] = $discovered->pixel_meta_id;
			}
			if ( ! empty( $discovered->gbp_place_id ) ) {
				$hints['gbp_place_id'] = $discovered->gbp_place_id;
			}
		}

		if ( ! empty( $extra_hints ) ) {
			$hints['extra_accounts'] = array_map( function ( $h ) {
				return [
					'type'       => sanitize_text_field( $h['type'] ?? '' ),
					'identifier' => sanitize_text_field( $h['identifier'] ?? '' ),
				];
			}, (array) $extra_hints );
		}

		return $hints;
	}

	// -------------------------------------------------------------------------
	// EntityIQ HTTP client — signed outbound requests with shared HMAC secret
	// -------------------------------------------------------------------------

	/**
	 * POST JSON to the EntityIQ service.
	 *
	 * Signs the request the same way EntityIQ signs its callbacks to us:
	 * X-BWG-Signature: sha256=HMAC(secret, body + timestamp)
	 *
	 * @param string $path       e.g. '/ads/surface'
	 * @param string $body_json  Already-encoded JSON string.
	 * @return array|WP_Error
	 */
	private function post_to_entityiq( $path, $body_json ) {
		$base = rtrim( get_option( 'bwg_ai_entityiq_url', '' ), '/' );
		if ( ! $base ) {
			return new WP_Error( 'entityiq_not_configured', 'EntityIQ URL is not set.' );
		}

		$timestamp = (string) time();
		$secret    = get_option( 'bwg_ai_entityiq_secret', '' );
		$sig       = 'sha256=' . hash_hmac( 'sha256', $body_json . $timestamp, $secret );

		return wp_remote_post(
			$base . $path,
			[
				'headers' => [
					'Content-Type'    => 'application/json',
					'X-BWG-Signature' => $sig,
					'X-BWG-Timestamp' => $timestamp,
				],
				'body'    => $body_json,
				'timeout' => 20,
			]
		);
	}

	/**
	 * GET from the EntityIQ service. Signs with empty body.
	 *
	 * @param string $path  e.g. '/ads/surface/job-id'
	 * @return array|WP_Error
	 */
	private function get_from_entityiq( $path ) {
		$base = rtrim( get_option( 'bwg_ai_entityiq_url', '' ), '/' );
		if ( ! $base ) {
			return new WP_Error( 'entityiq_not_configured', 'EntityIQ URL is not set.' );
		}

		$timestamp = (string) time();
		$secret    = get_option( 'bwg_ai_entityiq_secret', '' );
		$sig       = 'sha256=' . hash_hmac( 'sha256', '' . $timestamp, $secret );

		return wp_remote_get(
			$base . $path,
			[
				'headers' => [
					'X-BWG-Signature' => $sig,
					'X-BWG-Timestamp' => $timestamp,
				],
				'timeout' => 15,
			]
		);
	}
}
