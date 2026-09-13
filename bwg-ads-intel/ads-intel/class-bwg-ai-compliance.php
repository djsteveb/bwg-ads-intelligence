<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BWG\ComplianceRules\RuleSets\AdCopyRuleSet;
use BWG\ComplianceRules\RuleSets\EmailSmsRuleSet;

/**
 * Text compliance engine for ad copy.
 *
 * As of Track A item 1, the actual rule table and evaluation logic live in
 * the shared `bwg/compliance-rules` package (`AdCopyRuleSet` -- see
 * vendor/bwg/compliance-rules/src/RuleSets/AdCopyRuleSet.php) so this
 * plugin and bwg-comp-pl-one stop maintaining two independently-built
 * rule sets for the same HIPAA/42 CFR Part 2/FTC problem. This class is
 * now a thin adapter: it calls into the shared package and maps its
 * `Finding` objects back to this plugin's existing flag shape, so nothing
 * downstream (class-bwg-ai-ad-surface.php's call site, wp_bwg_ai_ads's
 * stored compliance_flags column, admin UI, reports) has to change.
 *
 * If the sibling plugin BWG_Compliance is active its check() is run first
 * and the results are merged so we never duplicate flags -- unchanged
 * behavior from before this extraction.
 */
class BWG_AI_Compliance {

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

		if ( ! class_exists( AdCopyRuleSet::class ) ) {
			// vendor/ not installed (e.g. a dev checkout before
			// `composer install`) -- degrade to whatever the sibling
			// plugin already produced rather than fataling.
			return $flags;
		}

		// Track A: "generalize past addiction treatment" -- which rule
		// table runs is a site-level setting (bwg_ai_healthcare_vertical),
		// defaulting to this plugin's original addiction-treatment scope
		// so existing installs see no behavior change.
		$vertical     = (string) get_option( 'bwg_ai_healthcare_vertical', 'addiction_treatment' );
		$existing_ids = array_column( $flags, 'rule_id' );
		$findings     = AdCopyRuleSet::forVertical( $vertical )->evaluate( $ad_copy, [ 'platform' => $platform ], $existing_ids );

		foreach ( $findings as $finding ) {
			$flags[] = [
				'rule_id'     => $finding->ruleId,
				'severity'    => $finding->severity,
				'category'    => $finding->category,
				'description' => $finding->description,
				'excerpt'     => (string) $finding->excerpt,
				'citation'    => (string) $finding->citation,
			];
		}

		// Sort: high → medium → low. Re-sorted here (not just relying on
		// AdCopyRuleSet's own internal sort) because $flags may also
		// contain the sibling plugin's un-sorted flags prepended above.
		usort( $flags, static function ( $a, $b ) {
			$order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
			return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
		} );

		return $flags;
	}

	/**
	 * Analyze an outbound email or SMS message body and return compliance
	 * flags -- the "extend the gate" roadmap item, sibling to
	 * analyze_ad_copy() above rather than a variant of it: EmailSmsRuleSet
	 * covers channel mechanics (SMS STOP/HELP/frequency, email CAN-SPAM
	 * unsubscribe/address) that ad copy has no equivalent of, plus this
	 * site's healthcare-vertical legal rules (reused, not reimplemented --
	 * see EmailSmsRuleSet's own doc comment). No sibling-plugin merge here
	 * -- BWG_Compliance::check() is specifically an ad-copy check, nothing
	 * upstream established email/SMS behavior for it to preserve.
	 *
	 * Same flag shape as analyze_ad_copy() above.
	 *
	 * @param string $content Raw message body text.
	 * @param string $channel 'email' | 'sms'.
	 * @return array
	 */
	public static function analyze_message( $content, $channel = 'email' ) {
		$content = (string) $content;

		if ( ! class_exists( EmailSmsRuleSet::class ) ) {
			return [];
		}

		$vertical = (string) get_option( 'bwg_ai_healthcare_vertical', 'addiction_treatment' );
		$findings = EmailSmsRuleSet::forChannel( (string) $channel, $vertical )->evaluate( $content );

		$flags = [];
		foreach ( $findings as $finding ) {
			$flags[] = [
				'rule_id'     => $finding->ruleId,
				'severity'    => $finding->severity,
				'category'    => $finding->category,
				'description' => $finding->description,
				'excerpt'     => (string) $finding->excerpt,
				'citation'    => (string) $finding->citation,
			];
		}

		return $flags;
	}
}
