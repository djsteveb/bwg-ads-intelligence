<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Report {

	// Severity weights for risk score.
	const WEIGHT_HIGH   = 15;
	const WEIGHT_MEDIUM = 8;
	const WEIGHT_LOW    = 3;

	// Actions mapped from rule categories (used to derive top 3 actions).
	private static $rule_actions = [
		// HIPAA / Legal
		'outcome_guarantee'        => [ 'Remove treatment outcome guarantees ("100% success", "guaranteed sobriety") from all active ads.', 'high' ],
		'patient_identifier'       => [ 'Strip patient story identifiers — ad copy must not identify individuals without explicit 42 CFR Part 2 consent.', 'high' ],
		'availability_bait_switch'  => [ 'Remove misleading bed-availability language ("beds available now") from ads.', 'high' ],
		'cfr_part2_pattern'        => [ 'Add substance-use disclosure consent language or remove implied disclosures from ad copy.', 'high' ],
		'unlicensed_claim'         => [ 'Remove unlicensed-facility implied claims and add your JCAHO/CARF certification reference.', 'high' ],
		// Platform policy
		'missing_health_disclaimer' => [ 'Add the required health disclaimer to all addiction-related ads on Meta and Google.', 'medium' ],
		'before_after_language'    => [ 'Replace before/after framing with outcome-neutral language per Meta and Google ad policy.', 'medium' ],
		'non_legitscript_claim'    => [ 'Get LegitScript certified or remove certification-implied claims from ad copy.', 'medium' ],
		'missing_availability_disclaimer' => [ 'Add "call for availability" disclaimer to all ads referencing treatment capacity.', 'medium' ],
		// Best practice
		'no_phone_number'          => [ 'Add a trackable phone number to ad copy to improve lead attribution.', 'low' ],
		'no_insurance_mention'     => [ 'Mention accepted insurance to qualify leads earlier in the funnel.', 'low' ],
		'no_accreditation_reference' => [ 'Reference JCAHO, CARF, or SAMHSA affiliation to increase trust and Quality Score.', 'low' ],
		'excessive_urgency'        => [ 'Rewrite excessive-urgency language to comply with emotional-health ad guidelines.', 'low' ],
	];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Generate an executive report for a session and store it.
	 *
	 * @param int    $session_id
	 * @param string $audience  'executive' (only audience for MVP).
	 * @return string|WP_Error  Report token UUID on success.
	 */
	public static function generate( $session_id, $audience = 'executive' ) {
		global $wpdb;

		$session_id = absint( $session_id );
		$session    = BWG_AI_Session::get( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'session_not_found', 'Session not found.', [ 'status' => 404 ] );
		}

		// Fetch raw data.
		$discovered = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}bwg_ai_discovered` WHERE session_id = %d LIMIT 1",
				$session_id
			)
		);

		$ads = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT platform, ad_copy, spend_range, compliance_flags, user_confirmed
				 FROM `{$wpdb->prefix}bwg_ai_ads` WHERE session_id = %d",
				$session_id
			)
		);

		$access_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT platform, access_status FROM `{$wpdb->prefix}bwg_ai_access` WHERE session_id = %d",
				$session_id
			)
		);

		// Decode JSON fields.
		foreach ( $ads as $ad ) {
			$ad->compliance_flags = json_decode( $ad->compliance_flags, true ) ?? [];
		}

		$access_map = [];
		foreach ( $access_rows as $row ) {
			$access_map[ $row->platform ] = $row->access_status;
		}

		// Compute components.
		$risk_score    = self::compute_risk_score( $ads );
		$wasted_spend  = self::estimate_wasted_spend( $ads );
		$top_actions   = self::derive_top_actions( $ads );
		$platform_snap = self::build_platform_snapshot( $ads, $access_map );
		$whats_working = self::build_whats_working( $discovered, $ads );
		$flag_counts   = self::count_flags_by_severity( $ads );

		$report_data = [
			'session_id'       => $session_id,
			'website_url'      => $session->website_url,
			'business_name'    => $discovered->business_name ?? '',
			'risk_score'       => $risk_score,
			'wasted_spend'     => $wasted_spend,
			'top_actions'      => $top_actions,
			'platform_snapshot'=> $platform_snap,
			'whats_working'    => $whats_working,
			'flag_counts'      => $flag_counts,
			'total_ads'        => count( $ads ),
			'audience'         => $audience,
		];

		// Check for existing report — regenerate idempotently.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT report_token FROM `{$wpdb->prefix}bwg_ai_reports`
				 WHERE session_id = %d AND audience_type = %s LIMIT 1",
				$session_id,
				$audience
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$wpdb->prefix . 'bwg_ai_reports',
				[
					'report_data'  => wp_json_encode( $report_data ),
					'generated_at' => gmdate( 'Y-m-d H:i:s' ),
				],
				[ 'session_id' => $session_id, 'audience_type' => $audience ],
				[ '%s', '%s' ],
				[ '%d', '%s' ]
			);
			return $existing->report_token;
		}

		$token = wp_generate_uuid4();

		$wpdb->insert(
			$wpdb->prefix . 'bwg_ai_reports',
			[
				'session_id'   => $session_id,
				'audience_type'=> $audience,
				'report_token' => $token,
				'report_data'  => wp_json_encode( $report_data ),
				'generated_at' => gmdate( 'Y-m-d H:i:s' ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '+90 days' ) ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		BWG_AI_Session::log( $session_id, 'report_generated', "Token: {$token}" );

		return $token;
	}

	// -------------------------------------------------------------------------
	// Risk score
	// -------------------------------------------------------------------------

	/**
	 * Compute a 0–100 risk score weighted by flag severity.
	 * Each high flag = 15 pts, medium = 8, low = 3. Score caps at 100.
	 */
	private static function compute_risk_score( array $ads ) {
		$score = 0;
		foreach ( $ads as $ad ) {
			foreach ( $ad->compliance_flags as $flag ) {
				switch ( $flag['severity'] ?? 'low' ) {
					case 'high':
						$score += self::WEIGHT_HIGH;
						break;
					case 'medium':
						$score += self::WEIGHT_MEDIUM;
						break;
					default:
						$score += self::WEIGHT_LOW;
				}
			}
		}
		return min( 100, $score );
	}

	// -------------------------------------------------------------------------
	// Wasted spend estimate
	// -------------------------------------------------------------------------

	/**
	 * Estimate wasted spend as the share of spend on non-compliant ads.
	 * spend_range values like "$500", "$1,200" or "$500–$1,200" are mid-pointed.
	 * Returns a formatted string such as "$2,400–$4,800/mo estimated".
	 */
	private static function estimate_wasted_spend( array $ads ) {
		$compliant_total   = 0.0;
		$noncompliant_total = 0.0;

		foreach ( $ads as $ad ) {
			$mid = self::parse_spend_midpoint( $ad->spend_range ?? '' );
			if ( ! empty( $ad->compliance_flags ) ) {
				$noncompliant_total += $mid;
			} else {
				$compliant_total += $mid;
			}
		}

		$total = $compliant_total + $noncompliant_total;
		if ( $total <= 0 || $noncompliant_total <= 0 ) {
			return null;
		}

		// Conservative estimate: 20–40% of non-compliant spend is wasted.
		$low  = (int) round( $noncompliant_total * 0.20 );
		$high = (int) round( $noncompliant_total * 0.40 );

		return '$' . number_format( $low ) . '–$' . number_format( $high ) . '/mo estimated';
	}

	private static function parse_spend_midpoint( $range ) {
		if ( ! $range ) { return 0.0; }
		// Strip currency symbols and spaces.
		$clean = preg_replace( '/[$,\s]/', '', $range );
		// Range like "500-1200" or "500–1200".
		if ( preg_match( '/^([\d.]+)[–\-]([\d.]+)$/', $clean, $m ) ) {
			return ( (float) $m[1] + (float) $m[2] ) / 2;
		}
		// Single value.
		if ( is_numeric( $clean ) ) {
			return (float) $clean;
		}
		return 0.0;
	}

	// -------------------------------------------------------------------------
	// Top 3 actions
	// -------------------------------------------------------------------------

	/**
	 * Derive the top 3 most urgent actions from the highest-severity flags found.
	 * Returns an array of [ 'action' => string, 'severity' => string ] items.
	 */
	private static function derive_top_actions( array $ads ) {
		$seen    = [];
		$actions = [];

		// Gather all unique rule_ids sorted by severity (high first).
		$by_severity = [ 'high' => [], 'medium' => [], 'low' => [] ];
		foreach ( $ads as $ad ) {
			foreach ( $ad->compliance_flags as $flag ) {
				$rule_id  = $flag['rule_id'] ?? '';
				$severity = $flag['severity'] ?? 'low';
				if ( $rule_id && ! isset( $seen[ $rule_id ] ) ) {
					$seen[ $rule_id ] = true;
					$bucket           = isset( $by_severity[ $severity ] ) ? $severity : 'low';
					$by_severity[ $bucket ][] = $rule_id;
				}
			}
		}

		foreach ( [ 'high', 'medium', 'low' ] as $sev ) {
			foreach ( $by_severity[ $sev ] as $rule_id ) {
				if ( isset( self::$rule_actions[ $rule_id ] ) ) {
					$actions[] = [
						'action'   => self::$rule_actions[ $rule_id ][0],
						'severity' => $sev,
					];
				} else {
					$actions[] = [
						'action'   => 'Review and resolve ' . $rule_id . ' violations across your active ads.',
						'severity' => $sev,
					];
				}
				if ( count( $actions ) >= 3 ) { break 2; }
			}
		}

		// If no flags at all, offer a generic best-practice action.
		if ( empty( $actions ) ) {
			$actions[] = [
				'action'   => 'Schedule a strategy call to review your ad spend allocation and creative refresh cycle.',
				'severity' => 'low',
			];
		}

		return $actions;
	}

	// -------------------------------------------------------------------------
	// Platform snapshot
	// -------------------------------------------------------------------------

	private static function build_platform_snapshot( array $ads, array $access_map ) {
		$platforms = [];
		foreach ( $ads as $ad ) {
			$p = $ad->platform ?? 'unknown';
			if ( ! isset( $platforms[ $p ] ) ) {
				$platforms[ $p ] = [ 'ad_count' => 0, 'flag_count' => 0, 'access_status' => $access_map[ $p ] ?? 'none' ];
			}
			$platforms[ $p ]['ad_count']++;
			$platforms[ $p ]['flag_count'] += count( $ad->compliance_flags );
		}
		return $platforms;
	}

	// -------------------------------------------------------------------------
	// What's working
	// -------------------------------------------------------------------------

	private static function build_whats_working( $discovered, array $ads ) {
		$items = [];

		if ( $discovered ) {
			if ( $discovered->gbp_place_id ) {
				$rating = $discovered->gbp_rating ? " ({$discovered->gbp_rating}★, {$discovered->gbp_review_count} reviews)" : '';
				$items[] = "Active Google Business Profile{$rating} — a strong local trust signal.";
			}
			if ( $discovered->legitscript_status === 'certified' || $discovered->legitscript_status === 'listed' ) {
				$items[] = 'LegitScript certified — unlocks Google restricted-category ads for treatment centers.';
			}
			if ( $discovered->pixel_meta_id ) {
				$items[] = 'Meta Pixel installed — retargeting and lookalike audiences are available.';
			}
			if ( $discovered->pixel_ga4_id || $discovered->pixel_gtm_id ) {
				$items[] = 'GA4 / GTM tracking in place — conversion data is flowing.';
			}
		}

		$clean_ads = array_filter( $ads, fn( $a ) => empty( $a->compliance_flags ) && intval( $a->user_confirmed ) === 1 );
		if ( count( $clean_ads ) > 0 ) {
			$n       = count( $clean_ads );
			$items[] = $n . ' confirmed ad' . ( $n === 1 ? '' : 's' ) . ' with no compliance flags — these are solid to keep running.';
		}

		if ( empty( $items ) ) {
			$items[] = 'Discovery complete — your ad footprint is now mapped and ready for optimisation.';
		}

		return $items;
	}

	// -------------------------------------------------------------------------
	// Flag counts
	// -------------------------------------------------------------------------

	private static function count_flags_by_severity( array $ads ) {
		$counts = [ 'high' => 0, 'medium' => 0, 'low' => 0 ];
		foreach ( $ads as $ad ) {
			foreach ( $ad->compliance_flags as $flag ) {
				$sev = $flag['severity'] ?? 'low';
				if ( isset( $counts[ $sev ] ) ) { $counts[ $sev ]++; }
			}
		}
		return $counts;
	}
}
