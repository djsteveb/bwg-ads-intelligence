<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin abstraction over a hosted URL-to-image "screenshot" API. Used for
 * platforms that (unlike Meta's ads_archive) have no bulk data API and no
 * hosted per-ad snapshot link — currently just Google Ads Transparency
 * Center (M12). Not tied to any one vendor: any provider whose endpoint
 * accepts `?url=&access_key=` and returns raw image bytes works (e.g.
 * ScreenshotOne, ApiFlash, urlbox.io).
 *
 * Deliberately not EntityIQ/Playwright — no headless browser is bundled
 * with or run by this plugin. If no provider is configured, callers should
 * fall back to manual entry, same pattern as class-bwg-ai-meta-ad-library.php.
 */
class BWG_AI_Render_Provider {

	/**
	 * Whether a screenshot render API is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== bwg_ai_get_screenshot_api_url() && '' !== bwg_ai_get_screenshot_api_key();
	}

	/**
	 * Render a URL to an image via the configured provider.
	 *
	 * @param string $target_url
	 * @return array|WP_Error  [ 'binary' => string, 'content_type' => string ]
	 */
	public static function capture( $target_url ) {
		$endpoint = bwg_ai_get_screenshot_api_url();
		$key      = bwg_ai_get_screenshot_api_key();

		if ( ! $endpoint || ! $key ) {
			return new WP_Error( 'render_provider_not_configured', 'Screenshot API is not configured.' );
		}

		$url = add_query_arg(
			[
				'url'        => rawurlencode( $target_url ),
				'access_key' => $key,
				'format'     => 'png',
				'full_page'  => 'true',
			],
			$endpoint
		);

		$response = wp_remote_get( $url, [ 'timeout' => 40 ] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'render_provider_error', "Screenshot API returned HTTP {$code}.", [ 'status' => $code ] );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$body         = wp_remote_retrieve_body( $response );

		if ( ! $body || ! $content_type || 0 !== strpos( $content_type, 'image/' ) ) {
			return new WP_Error( 'render_provider_bad_response', 'Screenshot API did not return an image.' );
		}

		return [ 'binary' => $body, 'content_type' => $content_type ];
	}
}
