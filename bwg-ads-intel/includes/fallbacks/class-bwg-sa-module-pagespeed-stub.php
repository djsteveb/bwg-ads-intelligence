<?php
/**
 * Stub for BWG_SA_Module_PageSpeed from bwg-speed-sitescout.
 * Phase 6 (deferred) — returns empty scores.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWG_SA_Module_PageSpeed' ) ) {

	class BWG_SA_Module_PageSpeed {

		public static function get_cwv( $url, $api_key = '' ) {
			return [
				'lcp'  => null,
				'cls'  => null,
				'fid'  => null,
				'ttfb' => null,
				'score'=> null,
			];
		}
	}
}
