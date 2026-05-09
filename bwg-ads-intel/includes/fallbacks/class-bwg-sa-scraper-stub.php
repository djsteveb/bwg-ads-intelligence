<?php
/**
 * Stub for BWG_SA_Scraper from bwg-speed-sitescout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWG_SA_Scraper' ) ) {

	class BWG_SA_Scraper {

		/**
		 * Fetch a URL and return [ 'body' => string, 'code' => int, 'error' => string|null ].
		 */
		public static function fetch( $url, $args = [] ) {
			$defaults = [
				'timeout'    => 15,
				'user-agent' => 'BWGAdsIntel/1.0',
			];
			$response = wp_remote_get( esc_url_raw( $url ), array_merge( $defaults, $args ) );

			if ( is_wp_error( $response ) ) {
				return [ 'body' => '', 'code' => 0, 'error' => $response->get_error_message() ];
			}

			return [
				'body'  => wp_remote_retrieve_body( $response ),
				'code'  => (int) wp_remote_retrieve_response_code( $response ),
				'error' => null,
			];
		}
	}
}
