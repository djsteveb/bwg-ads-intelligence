<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text compliance engine for ad copy.
 *
 * Checks ad copy against three tiers of rules:
 *   high   — HIPAA / Legal violations (outcome guarantees, 42 CFR Part 2, bait availability)
 *   medium — Platform policy violations (Meta, Google)
 *   low    — Best-practice gaps (missing phone, accreditation, insurance mention)
 *
 * If the sibling plugin BWG_Compliance is active its check() is run first and
 * the results are merged so we never duplicate flags.
 */
class BWG_AI_Compliance {

	// -------------------------------------------------------------------------
	// Rule table
	// -------------------------------------------------------------------------

	/**
	 * Each rule has the following keys:
	 *   id         string  Unique, stable identifier.
	 *   severity   string  'high' | 'medium' | 'low'
	 *   category   string  Human-readable group name.
	 *   description string Short description of what the rule checks.
	 *   citation   string  Regulation or policy reference.
	 *   pattern    string|null  Regex that triggers the flag when it MATCHES.
	 *   absent     string|null  Regex that triggers the flag when it is ABSENT.
	 *   negate     string|null  If set and matches, the pattern flag is suppressed.
	 */
	private static $rules = [

		// =====================================================================
		// HIPAA / Legal — high severity
		// =====================================================================

		[
			'id'          => 'hipaa_outcome_guarantee',
			'severity'    => 'high',
			'category'    => 'HIPAA / Legal',
			'description' => 'Outcome guarantee for addiction treatment',
			'citation'    => 'FTC Act § 5; HIPAA 45 CFR § 164; NAD Guidelines',
			'pattern'     => '/\b(
				100\s*%\s*(success|effective|recovery|cure|sobriety|sober|clean)
				|guaranteed?\s+(sobriety|recovery|results?|cure|treatment|success)
				|cure\s+(addiction|alcoholism|drug\s+abuse)
				|permanently\s+(sober|clean|cured?)
				|never\s+relapse\s+again
				|addiction.free\s+for\s+(life|good|ever)
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'hipaa_patient_testimonial',
			'severity'    => 'high',
			'category'    => 'HIPAA / Legal',
			'description' => 'Patient testimonial that may disclose treatment status without consent',
			'citation'    => '42 CFR Part 2; HIPAA § 164.502',
			'pattern'     => '/\b(
				(real\s+)?(patient|client|resident)\s+(testimonial|story|review|result)
				|before\s+(i|we|they|he|she)\s+(went|entered|started|tried|came|checked\s+in)\s+to\s+(treatment|rehab|detox|the\s+program|the\s+center|the\s+facility)
				|i\s+was\s+(addicted|struggling\s+with|abusing|dependent\s+on)
				|my\s+(addiction|recovery|sobriety)\s+story
				|hear\s+from\s+our\s+(patients|clients|graduates|alumni)\b
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'hipaa_bait_availability',
			'severity'    => 'high',
			'category'    => 'HIPAA / Legal',
			'description' => 'Bait-and-switch availability claim ("beds available now")',
			'citation'    => 'FTC Act § 5 (bait advertising); LegitScript Certification Standards § 4',
			'pattern'     => '/\b(
				beds?\s+available\s+(now|today|immediately|tonight)
				|immediate\s+(admission|intake|enrollment|placement|bed)
				|admit\s+(today|now|immediately|tonight)
				|open\s+beds?\s*(now|today|available)?
				|same[\s\-]day\s+(admission|intake|treatment|detox)
				|walk[\s\-]in(s)?\s+(welcome|accepted|available)
				|available\s+(now|today)\s+(for\s+)?(admission|treatment|intake|enrollment)
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'hipaa_cfr_part2_pattern',
			'severity'    => 'high',
			'category'    => 'HIPAA / Legal',
			'description' => 'Possible 42 CFR Part 2 disclosure — substance use + named individual',
			'citation'    => '42 CFR Part 2 (Confidentiality of Substance Use Disorder Patient Records)',
			'pattern'     => '/\b(substance\s+use\s+disorder|drug\s+(abuse|dependency|use\s+disorder)|alcohol\s+(abuse|dependency|use\s+disorder)|opioid\s+(addiction|disorder|dependence|use\s+disorder))\b.{0,250}\b(name|patient|client|individual|person|resident|he|she|they)\b/is',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'hipaa_unlicensed_claim',
			'severity'    => 'high',
			'category'    => 'HIPAA / Legal',
			'description' => 'Medical or clinical authority claim without verifiable credentials',
			'citation'    => 'FTC Act § 5; State medical practice acts',
			'pattern'     => '/\b(
				medically\s+(supervised|proven|approved|endorsed)\s+(detox|treatment|program|withdrawal)
				|doctor[\s\-]supervised\s+(detox|withdrawal|treatment)
				|clinically\s+proven\s+(treatment|program|method|approach)
				|fda[\s\-]approved\s+(treatment|program|method|therapy)\b(?!\s+medication)
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		// =====================================================================
		// Platform policy — medium severity
		// =====================================================================

		[
			'id'          => 'policy_before_after',
			'severity'    => 'medium',
			'category'    => 'Platform policy',
			'description' => 'Before/after comparison language prohibited by Meta and Google health ad policies',
			'citation'    => 'Meta Advertising Policies — Health & Wellness; Google Ads Healthcare Policy',
			'pattern'     => '/\b(
				before\s+(treatment|rehab|detox|recovery)\s+(vs\.?|versus|compared\s+to)\s+after
				|after\s+(treatment|rehab|detox|recovery)\s+(vs\.?|versus|compared\s+to)\s+before
				|before\s+and\s+after\s+(treatment|rehab|recovery|sobriety|getting\s+(sober|clean))
				|transformation\s+(story|journey)\s+(from|before|after)\s+(addiction|rehab|treatment)
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'policy_admissions_cta_no_disclaimer',
			'severity'    => 'medium',
			'category'    => 'Platform policy',
			'description' => 'Admissions CTA without "call for availability" or results-may-vary disclaimer',
			'citation'    => 'Google Healthcare Advertising Policy; Meta Special Ad Category — Health',
			'pattern'     => '/\b(
				call\s+(now|today|us)\s+(for\s+)?(help|treatment|rehab|detox|admission|availability)
				|get\s+(help|treatment|admitted)\s+(now|today|immediately|tonight)
				|start\s+(treatment|recovery|rehab)\s+(now|today|immediately|tonight)
				|enroll\s+(now|today|immediately)
				|begin\s+(treatment|your\s+recovery)\s+(now|today|immediately)
			)\b/ix',
			'absent'      => null,
			'negate'      => '/\b(
				call\s+for\s+availability
				|results?\s+may\s+vary
				|not\s+all\s+(patients?|individuals?)\s+qualify
				|consult\s+(a\s+)?doctor
				|individual\s+results?\s+(may\s+)?vary
				|subject\s+to\s+availability
			)\b/ix',
		],

		[
			'id'          => 'policy_insurance_guarantee',
			'severity'    => 'medium',
			'category'    => 'Platform policy',
			'description' => 'Insurance coverage guarantee or "free treatment" claim',
			'citation'    => 'FTC Act § 5; CMS Anti-Kickback considerations; LegitScript Standards',
			'pattern'     => '/\b(
				insurance\s+covers?\s+(everything|all\s+costs?|100\s*%|fully|completely|the\s+entire\s+cost)
				|fully?\s+covered\s+by\s+(your\s+)?insurance
				|no\s+out[\s\-]of[\s\-]pocket\s+(cost|expense)
				|(100\s*%\s+)?free\s+treatment
				|treatment\s+at\s+no\s+(cost|charge)
				|zero\s+(cost|copay|deductible)\s+treatment
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'policy_platform_cert_claim',
			'severity'    => 'medium',
			'category'    => 'Platform policy',
			'description' => 'Platform certification or partnership claim (requires LegitScript verification)',
			'citation'    => 'Google Healthcare Advertising Policy — LegitScript certification required',
			'pattern'     => '/\b(
				google[\s\-](certified|approved|trusted\s+partner)
				|meta[\s\-](certified|approved|partner)
				|facebook[\s\-](certified|approved)
				|bing[\s\-](certified|approved)
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'policy_personal_hardship_targeting',
			'severity'    => 'medium',
			'category'    => 'Platform policy',
			'description' => 'Personal hardship targeting language prohibited by Meta Special Ad Category rules',
			'citation'    => 'Meta Special Ad Categories — Health; Meta Advertising Standards § 4',
			'pattern'     => '/\b(
				(struggling|suffering)\s+with\s+(addiction|alcohol|drugs?|opioids?|substance)
				|feel\s+(hopeless|helpless|out\s+of\s+control)\s+(with\s+)?(addiction|alcohol|drugs?)
				|hit\s+(rock\s+bottom|your\s+lowest)
				|lose\s+(everything|your\s+(family|job|home))\s+(to\s+)?(addiction|alcohol|drugs?)
				|loved\s+one\s+(battling|struggling\s+with|addicted\s+to)
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		// =====================================================================
		// Best practice — low severity
		// =====================================================================

		[
			'id'          => 'bp_no_phone_number',
			'severity'    => 'low',
			'category'    => 'Best practice',
			'description' => 'No phone number in ad copy',
			'citation'    => 'LegitScript Advertising Standards; NAATP Best Practices',
			'pattern'     => null,
			'absent'      => '/(\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/',
			'negate'      => null,
		],

		[
			'id'          => 'bp_no_accreditation',
			'severity'    => 'low',
			'category'    => 'Best practice',
			'description' => 'No accreditation or licensure reference',
			'citation'    => 'NAATP Best Practices; LegitScript Standards',
			'pattern'     => null,
			'absent'      => '/\b(JCAHO|TJC|The\s+Joint\s+Commission|CARF|SAMHSA|NAATP|LegitScript|state[\s\-]licensed|licensed\s+by\s+the\s+state|accredited|Joint\s+Commission)\b/i',
			'negate'      => null,
		],

		[
			'id'          => 'bp_no_insurance_mention',
			'severity'    => 'low',
			'category'    => 'Best practice',
			'description' => 'No mention of insurance or payment options',
			'citation'    => 'NAATP Best Practices',
			'pattern'     => null,
			'absent'      => '/\b(insurance|medicaid|medicare|private\s+pay|self[\s\-]pay|financing|payment\s+plan|sliding[\s\-]scale|most\s+insurance|verify\s+(your\s+)?insurance)\b/i',
			'negate'      => null,
		],

		[
			'id'          => 'bp_excessive_urgency',
			'severity'    => 'low',
			'category'    => 'Best practice',
			'description' => 'Excessive urgency / scarcity language',
			'citation'    => 'FTC Advertising Guidelines; Meta & Google ad quality guidelines',
			'pattern'     => '/\b(
				act\s+now
				|limited[\s\-]time\s+(offer|only|deal)
				|last\s+chance
				|don\'?t\s+wait(\s+another\s+(day|minute|second))?
				|hurry[\s,!]
				|offer\s+expires?
				|today\s+only
				|spots?\s+(are\s+)?filling\s+up\s+fast
				|only\s+\d+\s+spots?\s+(left|remaining|available)
				|limited\s+(spots?|openings?|availability)\s+(left|remaining)
			)\b/ix',
			'absent'      => null,
			'negate'      => null,
		],

		[
			'id'          => 'bp_no_contact_cta',
			'severity'    => 'low',
			'category'    => 'Best practice',
			'description' => 'No clear contact call-to-action',
			'citation'    => 'NAATP Best Practices',
			'pattern'     => null,
			'absent'      => '/\b(call|contact|visit|chat|reach\s+out|get\s+in\s+touch|speak\s+(with|to)|talk\s+(with|to)|learn\s+more|find\s+out|click|apply)\b/i',
			'negate'      => null,
		],
	];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Analyze ad copy and return an array of compliance flags.
	 *
	 * Each flag shape:
	 * {
	 *   rule_id:     string,
	 *   severity:    'high' | 'medium' | 'low',
	 *   category:    string,
	 *   description: string,
	 *   excerpt:     string,   // surrounding text context (empty for absent-pattern flags)
	 *   citation:    string,
	 * }
	 *
	 * @param string $ad_copy   Raw ad body text.
	 * @param string $platform  'meta' | 'google' | etc.
	 * @return array
	 */
	public static function analyze_ad_copy( $ad_copy, $platform = 'meta' ) {
		$ad_copy = (string) $ad_copy;
		$flags   = [];

		// Run sibling plugin first if active — merge its flags.
		if ( class_exists( 'BWG_Compliance' ) && method_exists( 'BWG_Compliance', 'check' ) ) {
			$sibling = BWG_Compliance::check( $ad_copy, $platform );
			if ( is_array( $sibling ) ) {
				$flags = $sibling;
			}
		}

		$existing_ids = array_column( $flags, 'rule_id' );
		$copy_len     = mb_strlen( trim( $ad_copy ) );

		foreach ( self::$rules as $rule ) {
			// Skip if sibling plugin already reported this rule.
			if ( in_array( $rule['id'], $existing_ids, true ) ) {
				continue;
			}

			$triggered = false;
			$excerpt   = '';

			if ( null !== $rule['absent'] ) {
				// "Absent" rules only fire on substantive copy (≥ 40 chars).
				// Very short text (image-only ads) shouldn't generate noise.
				if ( $copy_len < 40 ) {
					continue;
				}
				if ( ! preg_match( $rule['absent'], $ad_copy ) ) {
					$triggered = true;
				}
			} elseif ( null !== $rule['pattern'] ) {
				if ( preg_match( $rule['pattern'], $ad_copy, $m ) ) {
					// Negation: presence of a disclaimer suppresses the flag.
					if ( null !== $rule['negate'] && preg_match( $rule['negate'], $ad_copy ) ) {
						continue;
					}
					$triggered = true;
					$excerpt   = self::excerpt_around( $ad_copy, $m[0] );
				}
			}

			if ( $triggered ) {
				$flags[] = [
					'rule_id'     => $rule['id'],
					'severity'    => $rule['severity'],
					'category'    => $rule['category'],
					'description' => $rule['description'],
					'excerpt'     => $excerpt,
					'citation'    => $rule['citation'],
				];
			}
		}

		// Sort: high → medium → low.
		usort( $flags, static function ( $a, $b ) {
			$order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
			return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
		} );

		return $flags;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract a short excerpt of surrounding text around the matched string.
	 *
	 * @param string $text   Full ad copy.
	 * @param string $match  The matched substring.
	 * @param int    $ctx    Characters of context on each side.
	 * @return string  Escaped excerpt.
	 */
	private static function excerpt_around( $text, $match, $ctx = 80 ) {
		$pos = mb_stripos( $text, $match );
		if ( false === $pos ) {
			return esc_html( mb_substr( $match, 0, 120 ) );
		}

		$start   = max( 0, $pos - $ctx );
		$end     = min( mb_strlen( $text ), $pos + mb_strlen( $match ) + $ctx );
		$excerpt = mb_substr( $text, $start, $end - $start );

		if ( $start > 0 ) {
			$excerpt = '…' . $excerpt;
		}
		if ( $end < mb_strlen( $text ) ) {
			$excerpt .= '…';
		}

		return esc_html( $excerpt );
	}
}
