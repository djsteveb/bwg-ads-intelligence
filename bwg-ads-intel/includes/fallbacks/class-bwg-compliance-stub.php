<?php
/**
 * Stub for BWG_Compliance from bwg-speed-sitescout.
 * BWG_AI_Compliance calls check() first and supplements with its own rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWG_Compliance' ) ) {

	class BWG_Compliance {

		/**
		 * Run base compliance checks on ad copy text.
		 * Returns an array of flag objects; empty array if no issues found.
		 *
		 * @param string $text
		 * @param string $context 'ad_copy' | 'landing_page'
		 * @return array
		 */
		public static function check( $text, $context = 'ad_copy' ) {
			return [];
		}
	}
}
