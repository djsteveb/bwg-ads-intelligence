<?php
/**
 * Stub for BWG_CPA_Discovery from bwg-speed-sitescout.
 * Used when the sibling plugin is not active.
 * Implements the method signatures BWG_AI_Discovery calls; returns empty/safe defaults.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BWG_CPA_Discovery' ) ) {

	class BWG_CPA_Discovery {

		protected $url;
		protected $html;

		public function __construct( $url ) {
			$this->url  = esc_url_raw( $url );
			$this->html = '';
		}

		/** Fetch the homepage HTML. Returns true on success. */
		public function fetch() {
			$response = wp_remote_get( $this->url, [
				'timeout'    => 15,
				'user-agent' => 'BWGAdsIntel/1.0',
			] );
			if ( is_wp_error( $response ) ) {
				return false;
			}
			$this->html = wp_remote_retrieve_body( $response );
			return true;
		}

		/** Extract social profile URLs from page source. */
		public function detect_social_profiles() {
			return [
				'facebook'  => '',
				'instagram' => '',
				'linkedin'  => '',
				'tiktok'    => '',
				'youtube'   => '',
			];
		}

		/** Detect pixel/tag IDs in page source. */
		public function detect_pixels() {
			return [
				'meta_pixel_id' => '',
				'gtm_id'        => '',
				'ga4_id'        => '',
				'tiktok_id'     => '',
				'linkedin_id'   => '',
			];
		}

		/** Extract NAP (Name, Address, Phone) from schema.org or visible text. */
		public function extract_nap() {
			return [
				'name'    => '',
				'address' => '',
				'phone'   => '',
			];
		}

		/** Match domain to a Google Business Profile listing. */
		public function match_gbp( $api_key = '' ) {
			return [
				'place_id'     => '',
				'rating'       => null,
				'review_count' => null,
				'category'     => '',
			];
		}
	}
}
