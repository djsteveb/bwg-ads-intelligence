<?php
/**
 * Stub for BWG_CPA_Rate_Limiter from bwg-speed-sitescout.
 * BWG_AI_Rate_Limiter delegates to this when the sibling plugin is active.
 * This stub is never used at runtime — it only prevents fatal errors
 * if the autoloader somehow loads it; the real check delegates to our own impl.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWG_CPA_Rate_Limiter' ) ) {

	class BWG_CPA_Rate_Limiter {

		public static function check( $key, $limit, $window ) {
			// Falls back to BWG_AI_Rate_Limiter::check_own() via delegation in BWG_AI_Rate_Limiter.
			return true;
		}
	}
}
