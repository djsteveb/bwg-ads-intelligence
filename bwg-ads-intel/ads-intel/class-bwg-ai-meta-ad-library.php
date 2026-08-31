<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Direct client for the Meta Graph API "Ad Library" (ads_archive) endpoint.
 *
 * Replaces the old EntityIQ-proxied job that never existed on the EntityIQ
 * side. This calls graph.facebook.com directly with a long-lived ads_read
 * token — no queue, no webhook, no headless browser. Meta hosts its own
 * rendered snapshot for every ad (ad_snapshot_url), so we link/embed that
 * instead of capturing a screenshot ourselves.
 */
class BWG_AI_Meta_Ad_Library {

	const API_VERSION = 'v21.0';

	/** Fields requested from the ads_archive endpoint. */
	const FIELDS = [
		'id',
		'ad_creation_time',
		'ad_delivery_start_time',
		'ad_delivery_stop_time',
		'ad_creative_bodies',
		'ad_creative_link_titles',
		'ad_snapshot_url',
		'page_id',
		'page_name',
		'publisher_platforms',
		'spend',
		'currency',
	];

	/**
	 * Whether a Meta Ad Library token is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== bwg_ai_get_meta_ad_library_token();
	}

	/**
	 * Search the Ad Library for ads matching the given advertiser hints.
	 *
	 * @param array $hints  Output of BWG_AI_Ad_Surface::build_hints() — expects
	 *                      'business_name' and/or an 'extra_accounts' entry of
	 *                      type 'meta_page_id'.
	 * @return array|WP_Error  Normalized ad objects, or WP_Error on failure.
	 */
	public static function search( array $hints ) {
		$token = bwg_ai_get_meta_ad_library_token();
		if ( '' === $token ) {
			return new WP_Error( 'meta_token_missing', 'Meta Ad Library token is not configured.' );
		}

		$page_id = self::extract_page_id( $hints );

		$params = [
			'access_token'          => $token,
			'ad_reached_countries'  => wp_json_encode( [ 'US' ] ),
			'ad_active_status'      => 'ALL',
			'fields'                => implode( ',', self::FIELDS ),
			'limit'                 => 50,
		];

		if ( $page_id ) {
			$params['search_page_ids'] = wp_json_encode( [ $page_id ] );
		} elseif ( ! empty( $hints['business_name'] ) ) {
			$params['search_terms'] = sanitize_text_field( $hints['business_name'] );
		} else {
			return new WP_Error( 'meta_no_search_criteria', 'No business name or Facebook page available to search with.' );
		}

		$url = 'https://graph.facebook.com/' . self::API_VERSION . '/ads_archive?' . http_build_query( $params );

		$response = wp_remote_get( $url, [ 'timeout' => 25 ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = $body['error']['message'] ?? "Meta Graph API returned HTTP {$code}.";
			return new WP_Error( 'meta_api_error', $message, [ 'status' => $code ] );
		}

		$rows = $body['data'] ?? [];

		return array_map( [ __CLASS__, 'normalize_ad' ], $rows );
	}

	/**
	 * Normalize a single ads_archive row into this plugin's ad shape
	 * (matches what BWG_AI_Ad_Surface::save_ads() expects).
	 *
	 * @param array $raw
	 * @return array
	 */
	private static function normalize_ad( array $raw ) {
		$copy_parts = array_merge(
			(array) ( $raw['ad_creative_bodies'] ?? [] ),
			(array) ( $raw['ad_creative_link_titles'] ?? [] )
		);

		$start = $raw['ad_delivery_start_time'] ?? '';
		$stop  = $raw['ad_delivery_stop_time'] ?? '';
		$run_dates = $start && $stop ? "{$start} – {$stop}" : ( $start ? "{$start} – present" : '' );

		$spend_range = '';
		if ( ! empty( $raw['spend'] ) && is_array( $raw['spend'] ) ) {
			$lower = $raw['spend']['lower_bound'] ?? '';
			$upper = $raw['spend']['upper_bound'] ?? '';
			$currency = $raw['currency'] ?? 'USD';
			if ( $lower && $upper ) {
				$spend_range = "{$currency} {$lower}–{$upper}";
			}
		}

		return [
			'platform'         => 'meta',
			'ad_id'            => (string) ( $raw['id'] ?? '' ),
			'advertiser_id'    => (string) ( $raw['page_id'] ?? '' ),
			'ad_copy'          => implode( "\n\n", array_filter( $copy_parts ) ),
			'ad_snapshot_url'  => (string) ( $raw['ad_snapshot_url'] ?? '' ),
			'run_dates'        => $run_dates,
			'spend_range'      => $spend_range,
		];
	}

	/**
	 * Pull a Meta page ID out of the advertiser hints, if one was supplied.
	 * Looks first at the Facebook page URL parsed from discovery, then any
	 * manually added "meta_page_id" account.
	 *
	 * @param array $hints
	 * @return string
	 */
	private static function extract_page_id( array $hints ) {
		if ( ! empty( $hints['extra_accounts'] ) ) {
			foreach ( $hints['extra_accounts'] as $acct ) {
				$type = $acct['type'] ?? '';
				$val  = trim( (string) ( $acct['identifier'] ?? '' ) );
				if ( ! $val || ! in_array( $type, [ 'meta_page_id', 'facebook_page' ], true ) ) {
					continue;
				}
				if ( ctype_digit( $val ) ) {
					return $val;
				}
				if ( preg_match( '#facebook\.com/(?:pages/[^/]+/)?(\d{6,})#', $val, $m ) ) {
					return $m[1];
				}
			}
		}

		if ( ! empty( $hints['facebook_page_url'] ) ) {
			// e.g. https://www.facebook.com/123456789012345 or /pagename
			if ( preg_match( '#facebook\.com/(?:pages/[^/]+/)?(\d{6,})#', $hints['facebook_page_url'], $m ) ) {
				return $m[1];
			}
		}

		return '';
	}
}
