<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Ad_Surface {

	// -------------------------------------------------------------------------
	// Public API (called via do_action / cron)
	// -------------------------------------------------------------------------

	/**
	 * Queue a Meta Ad Library lookup after Phase 1 confirm.
	 *
	 * Hooked to: bwg_ai_queue_ad_surface (args: session_id, hints=[])
	 *
	 * Schedules the actual API call a couple of seconds out via WP-Cron so the
	 * REST request that triggered this (POST /confirm-discovery or
	 * POST /add-accounts) doesn't block on an outbound HTTP call to Meta.
	 *
	 * @param int   $session_id
	 * @param array $hints  Extra account identifiers added by the user.
	 */
	public function queue_job( $session_id, $hints = [] ) {
		$session_id = absint( $session_id );
		if ( ! BWG_AI_Session::get( $session_id ) ) {
			return;
		}

		BWG_AI_Session::log( $session_id, 'ad_surface_queued', 'Meta Ad Library lookup scheduled.' );
		wp_schedule_single_event( time() + 2, 'bwg_ai_run_ad_surface', [ $session_id, $hints ] );
	}

	/**
	 * Run the Meta Ad Library lookup and save results.
	 *
	 * Hooked to: bwg_ai_run_ad_surface (args: session_id, hints=[])
	 *
	 * When no Meta token is configured, no automated lookup is possible —
	 * the session is left at its current step so the front-end can offer the
	 * manual-entry flow (paste Ad Library URLs) instead of polling forever.
	 *
	 * @param int   $session_id
	 * @param array $hints
	 */
	public function run( $session_id, $hints = [] ) {
		if ( ! wp_doing_cron() ) {
			return;
		}

		$session_id = absint( $session_id );
		$session    = BWG_AI_Session::get( $session_id );
		if ( ! $session ) {
			return;
		}

		if ( ! BWG_AI_Meta_Ad_Library::is_configured() ) {
			BWG_AI_Session::log( $session_id, 'meta_token_missing', 'Meta Ad Library token not configured — falling back to manual ad entry.' );
			return;
		}

		global $wpdb;
		$discovered = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_discovered` WHERE session_id = %d LIMIT 1",
				$session_id
			)
		);

		$built_hints = $this->build_hints( $session, $discovered, $hints );
		$result      = BWG_AI_Meta_Ad_Library::search( $built_hints );

		if ( is_wp_error( $result ) ) {
			BWG_AI_Session::log( $session_id, 'meta_api_error', 'Meta Ad Library lookup failed: ' . $result->get_error_message() );
			return;
		}

		$this->save_ads( $session_id, $result, 'api' );
	}

	/**
	 * Save ads submitted through the manual-entry flow (used when no Meta
	 * token is configured, or when the user wants to add ads the search
	 * missed). Each entry is a pasted Ad Library snapshot URL with optional
	 * ad copy the user typed in.
	 *
	 * @param int   $session_id
	 * @param array $entries  [ { ad_snapshot_url, ad_copy? } ]
	 * @return int  Number of ads saved.
	 */
	public function save_manual_ads( $session_id, array $entries ) {
		$session_id = absint( $session_id );
		$normalized = [];

		foreach ( $entries as $entry ) {
			$snapshot_url = esc_url_raw( $entry['ad_snapshot_url'] ?? '' );
			if ( ! $snapshot_url ) {
				continue;
			}
			$normalized[] = [
				'platform'        => 'meta',
				'ad_id'           => md5( $session_id . '|' . $snapshot_url ),
				'ad_copy'         => sanitize_textarea_field( $entry['ad_copy'] ?? '' ),
				'ad_snapshot_url' => $snapshot_url,
				'run_dates'       => '',
				'spend_range'     => '',
			];
		}

		return $this->save_ads( $session_id, $normalized, 'manual' );
	}

	// -------------------------------------------------------------------------
	// Internal: save + compliance + email
	// -------------------------------------------------------------------------

	/**
	 * Persist ads, run compliance on each, advance session step, and fire the
	 * ads-preview drip email.
	 *
	 * @param int    $session_id
	 * @param array  $ads     Normalized ad objects.
	 * @param string $source  'api' or 'manual'.
	 * @return int  Number of ads saved.
	 */
	private function save_ads( $session_id, $ads, $source = 'api' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_ai_ads';
		$saved = 0;

		foreach ( $ads as $ad ) {
			$platform      = sanitize_text_field( $ad['platform'] ?? 'meta' );
			$ad_id_ext     = sanitize_text_field( $ad['ad_id'] ?? '' );
			$advertiser_id = sanitize_text_field( $ad['advertiser_id'] ?? '' );
			$ad_copy       = sanitize_textarea_field( $ad['ad_copy'] ?? '' );
			$snapshot_url  = esc_url_raw( $ad['ad_snapshot_url'] ?? '' );
			$run_dates     = sanitize_text_field( $ad['run_dates'] ?? '' );
			$spend_range   = sanitize_text_field( $ad['spend_range'] ?? '' );

			$flags = $this->run_compliance( $ad_copy, $platform );

			$wpdb->insert(
				$table,
				[
					'session_id'       => $session_id,
					'platform'         => $platform,
					'advertiser_id'    => $advertiser_id,
					'ad_id'            => $ad_id_ext,
					'ad_copy'          => $ad_copy,
					'ad_snapshot_url'  => $snapshot_url,
					'run_dates'        => $run_dates,
					'spend_range'      => $spend_range,
					'compliance_flags' => wp_json_encode( $flags ),
					'user_confirmed'   => 0,
					'source'           => $source,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);

			if ( $wpdb->insert_id ) {
				$saved++;
			}
		}

		if ( $saved > 0 && (int) BWG_AI_Session::get( $session_id )->step_completed < 2 ) {
			BWG_AI_Session::update_step( $session_id, 2 );
		}

		BWG_AI_Session::log( $session_id, 'ads_saved', "{$saved} ads saved (source: {$source})." );

		if ( $saved > 0 ) {
			$session = BWG_AI_Session::get( $session_id );
			if ( $session && class_exists( 'BWG_AI_Email' ) ) {
				( new BWG_AI_Email() )->send_ads_preview( $session, $saved );
			}
		}

		return $saved;
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
	public function build_hints( $session, $discovered, $extra_hints = [] ) {
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
}
