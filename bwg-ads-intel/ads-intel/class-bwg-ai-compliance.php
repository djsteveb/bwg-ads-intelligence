<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Text compliance engine for ad copy.
 *
 * Stub created in M5. Full rule set implemented in M6.
 * Called by BWG_AI_Ad_Surface::save_ads() when ads arrive from EntityIQ.
 */
class BWG_AI_Compliance {

	/**
	 * Analyze ad copy and return an array of compliance flags.
	 *
	 * Each flag: { rule_id, severity (high|medium|low), category, excerpt, citation }
	 *
	 * @param string $ad_copy   Raw ad body text.
	 * @param string $platform  'meta' | 'google' | etc.
	 * @return array
	 */
	public static function analyze_ad_copy( $ad_copy, $platform = 'meta' ) {
		// Rule engine implemented in M6.
		return [];
	}
}
