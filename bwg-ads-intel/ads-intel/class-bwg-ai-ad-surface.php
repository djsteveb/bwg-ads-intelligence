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
	 * Run the Meta + Google ad surface lookups and save results.
	 *
	 * Hooked to: bwg_ai_run_ad_surface (args: session_id, hints=[])
	 *
	 * Each platform is independent — a platform with no credential
	 * configured is skipped (logged) rather than blocking the other, and the
	 * front-end offers manual entry per-platform for whichever wasn't
	 * automated.
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

		global $wpdb;
		$discovered = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_discovered` WHERE session_id = %d LIMIT 1",
				$session_id
			)
		);

		$built_hints = $this->build_hints( $session, $discovered, $hints );

		if ( BWG_AI_Meta_Ad_Library::is_configured() ) {
			$result = BWG_AI_Meta_Ad_Library::search( $built_hints );
			if ( is_wp_error( $result ) ) {
				BWG_AI_Session::log( $session_id, 'meta_api_error', 'Meta Ad Library lookup failed: ' . $result->get_error_message() );
			} else {
				$this->save_ads( $session_id, $result, 'api' );
			}
		} else {
			BWG_AI_Session::log( $session_id, 'meta_token_missing', 'Meta Ad Library token not configured — falling back to manual ad entry.' );
		}

		if ( BWG_AI_Google_Transparency::is_configured() ) {
			$result = BWG_AI_Google_Transparency::search( $session_id, $built_hints );
			if ( is_wp_error( $result ) ) {
				BWG_AI_Session::log( $session_id, 'google_render_error', 'Google Ads Transparency capture failed: ' . $result->get_error_message() );
			} else {
				$this->save_ads( $session_id, $result, 'api' );
			}
		} else {
			BWG_AI_Session::log( $session_id, 'google_render_not_configured', 'Screenshot API not configured — falling back to manual Google ad entry.' );
		}
	}

	/**
	 * Save ads submitted through the manual-entry flow (used when no
	 * automated lookup is configured for a platform, or when the user wants
	 * to add ads the search missed). Each entry is a pasted Ad Library /
	 * Transparency Center URL with optional ad copy the user typed in.
	 *
	 * @param int    $session_id
	 * @param string $platform  'meta' or 'google'.
	 * @param array  $entries   [ { ad_snapshot_url, ad_copy? } ]
	 * @return int  Number of ads saved.
	 */
	public function save_manual_ads( $session_id, $platform, array $entries ) {
		$session_id = absint( $session_id );
		$platform   = in_array( $platform, [ 'meta', 'google' ], true ) ? $platform : 'meta';
		$normalized = [];

		foreach ( $entries as $entry ) {
			$snapshot_url = esc_url_raw( $entry['ad_snapshot_url'] ?? '' );
			if ( ! $snapshot_url ) {
				continue;
			}
			$normalized[] = [
				'platform'        => $platform,
				'ad_id'           => md5( $session_id . '|' . $platform . '|' . $snapshot_url ),
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
			$platform         = sanitize_text_field( $ad['platform'] ?? 'meta' );
			$ad_id_ext        = sanitize_text_field( $ad['ad_id'] ?? '' );
			$advertiser_id    = sanitize_text_field( $ad['advertiser_id'] ?? '' );
			$ad_copy          = sanitize_textarea_field( $ad['ad_copy'] ?? '' );
			$snapshot_url     = esc_url_raw( $ad['ad_snapshot_url'] ?? '' );
			$screenshot_path  = sanitize_text_field( $ad['screenshot_path'] ?? '' );
			$screenshot_bytes = isset( $ad['screenshot_bytes'] ) ? absint( $ad['screenshot_bytes'] ) : null;
			$run_dates        = sanitize_text_field( $ad['run_dates'] ?? '' );
			$spend_range      = sanitize_text_field( $ad['spend_range'] ?? '' );

			$flags = array_map( static function ( $f ) {
				$f['source'] = $f['source'] ?? 'text';
				return $f;
			}, $this->run_compliance( $ad_copy, $platform ) );

			// Vision compliance (M13) — best-effort, never blocks saving.
			// May also capture a screenshot of a Meta ad_snapshot_url page
			// (via the render provider) when nothing was captured already,
			// so vision gets an image to look at.
			$vision_analysis = [ 'analyzed' => false, 'reason' => 'not_configured', 'flags' => [] ];
			if ( class_exists( 'BWG_AI_Vision' ) && BWG_AI_Vision::is_configured() ) {
				$ad_for_vision = $ad;
				if ( $screenshot_path ) {
					$ad_for_vision['screenshot_path'] = $screenshot_path;
				}
				$vision_analysis = BWG_AI_Vision::analyze( $session_id, $platform, $ad_for_vision );

				if ( ! empty( $vision_analysis['flags'] ) ) {
					$flags = array_merge( $flags, $vision_analysis['flags'] );
				}
				if ( ! $screenshot_path && ! empty( $vision_analysis['screenshot_path'] ) ) {
					$screenshot_path  = sanitize_text_field( $vision_analysis['screenshot_path'] );
					$screenshot_bytes = absint( $vision_analysis['screenshot_bytes'] ?? 0 );
				}
				// Don't duplicate the (already large) raw file paths back into the JSON blob.
				unset( $vision_analysis['screenshot_path'], $vision_analysis['screenshot_bytes'] );
			}

			$wpdb->insert(
				$table,
				[
					'session_id'       => $session_id,
					'platform'         => $platform,
					'advertiser_id'    => $advertiser_id,
					'ad_id'            => $ad_id_ext,
					'ad_copy'          => $ad_copy,
					'ad_snapshot_url'  => $snapshot_url,
					'screenshot_path'  => $screenshot_path,
					'screenshot_bytes' => $screenshot_bytes,
					'run_dates'        => $run_dates,
					'spend_range'      => $spend_range,
					'compliance_flags' => wp_json_encode( $flags ),
					'vision_analysis'  => wp_json_encode( $vision_analysis ),
					'user_confirmed'   => 0,
					'source'           => $source,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
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
