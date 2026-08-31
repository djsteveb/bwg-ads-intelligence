<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Ads Transparency Center integration (M12).
 *
 * Unlike Meta, Google has no bulk data API equivalent to ads_archive and no
 * per-ad hosted snapshot link — the Transparency Center is a JS-rendered
 * results page with no documented public API. Rather than reintroducing an
 * EntityIQ-shaped dependency (a job queue calling a headless browser we'd
 * have to run ourselves), this captures the advertiser's Transparency
 * Center results page through a configurable render-provider API
 * (class-bwg-ai-render-provider.php) and stores it as a single ad record
 * summarizing what's visible there. This is a coarser result than Meta's
 * per-ad records — one screenshot standing in for the whole result set —
 * and is flagged as such in the saved record so the compliance/report UI
 * doesn't imply per-ad detail it doesn't have.
 *
 * When no render provider is configured, falls back to manual entry, same
 * as Meta without a token.
 */
class BWG_AI_Google_Transparency {

	/**
	 * Whether an automated Google Transparency capture is possible.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return BWG_AI_Render_Provider::is_configured();
	}

	/**
	 * Capture the Google Ads Transparency Center domain-search results page
	 * for the advertiser and store it as a screenshot-backed ad record.
	 *
	 * @param int   $session_id
	 * @param array $hints  Output of BWG_AI_Ad_Surface::build_hints().
	 * @return array|WP_Error  A single-element normalized ad array, or WP_Error.
	 */
	public static function search( $session_id, array $hints ) {
		if ( ! self::is_configured() ) {
			return new WP_Error( 'google_render_not_configured', 'Screenshot API is not configured.' );
		}

		$domain = self::extract_domain( $hints );
		if ( ! $domain ) {
			return new WP_Error( 'google_no_domain', 'No website URL available to search the Transparency Center with.' );
		}

		$transparency_url = 'https://adstransparency.google.com/?region=anywhere&domain=' . rawurlencode( $domain );

		$capture = BWG_AI_Render_Provider::capture( $transparency_url );
		if ( is_wp_error( $capture ) ) {
			return $capture;
		}

		$ext   = self::ext_from_content_type( $capture['content_type'] );
		$saved = BWG_AI_Screenshot_Store::save( $session_id, 'google', $capture['binary'], $ext );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [ [
			'platform'          => 'google',
			'ad_id'             => md5( $session_id . '|google-transparency|' . $domain ),
			'ad_copy'           => "Google Ads Transparency Center results for {$domain}. This is a full-page capture of the results list, not individual per-ad records — review the screenshot directly for ad-by-ad detail.",
			'screenshot_path'   => $saved['relative_path'],
			'screenshot_bytes'  => $saved['bytes'],
			'run_dates'         => '',
			'spend_range'       => '',
		] ];
	}

	/**
	 * Pull a bare domain (no scheme/path) out of the advertiser hints.
	 *
	 * @param array $hints
	 * @return string
	 */
	private static function extract_domain( array $hints ) {
		if ( empty( $hints['website_url'] ) ) {
			return '';
		}
		$host = wp_parse_url( $hints['website_url'], PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./i', '', strtolower( $host ) ) : '';
	}

	private static function ext_from_content_type( $content_type ) {
		$map = [
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		];
		return $map[ $content_type ] ?? 'png';
	}
}
