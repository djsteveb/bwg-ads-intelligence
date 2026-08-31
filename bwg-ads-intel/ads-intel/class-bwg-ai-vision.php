<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claude vision compliance analysis (M13).
 *
 * Text compliance (class-bwg-ai-compliance.php) only ever sees ad copy —
 * it can't catch a HIPAA problem that's baked into the creative itself
 * (a before/after photo pair, a patient testimonial on camera, a "100%
 * success" badge rendered as an image). This calls the Claude API with the
 * ad's actual image and a HIPAA-focused prompt, returning flags in the same
 * shape as the text rules so the gallery UI can show one merged list.
 *
 * Uses raw wp_remote_post() rather than the Anthropic PHP SDK — this plugin
 * has no Composer/vendor dependency tree (every other external API call in
 * it — Meta, Google Places, Turnstile, the screenshot render API — is a
 * plain wp_remote_get()/wp_remote_post() call for the same reason), so
 * adding one just for this call would be inconsistent with the rest of the
 * codebase and would require site owners to run `composer install`.
 *
 * Called from class-bwg-ai-ad-surface.php::save_ads() right after text
 * compliance, guarded by is_configured() — skipped silently (never blocks
 * ad saving) when no API key is set.
 */
class BWG_AI_Vision {

	const API_URL      = 'https://api.anthropic.com/v1/messages';
	const API_VERSION  = '2023-06-01';
	const MODEL        = 'claude-opus-5';

	const SYSTEM_PROMPT = <<<'PROMPT'
You are a compliance reviewer for addiction treatment center advertising, checking ad creative (images) for HIPAA, 42 CFR Part 2, FTC, and Meta/Google health-advertising policy risk — the same categories a human compliance officer would flag before this ad could run again.

Look specifically for things only visible in the image, not inferable from ad copy alone:
- Patient/client photos, testimonials, or identifiable before/after imagery used without visible consent framing
- Outcome-guarantee badges, seals, or graphics ("100% success", "guaranteed", checkmark/award badges implying certification that may not be verifiable)
- Before/after visual comparisons (weight, appearance, "transformation" imagery) tied to treatment
- Urgency/scarcity graphics ("beds available now", countdown timers, "call now" overlays) without a visible disclaimer
- Missing required disclaimer text that should be visible on the creative (e.g. no phone number, no licensure/accreditation mark)
- Any other HIPAA/42 CFR Part 2 concern specific to what's depicted

Respond with ONLY a JSON array (no markdown fences, no prose before or after) of flag objects. Each object:
{"rule_id": "vision_<short_snake_case_id>", "severity": "high"|"medium"|"low", "category": "HIPAA / Legal"|"Platform policy"|"Best practice", "description": "<one sentence>", "citation": "<regulation or policy reference>"}

If you find nothing to flag, respond with an empty JSON array: []
PROMPT;

	/**
	 * Whether a Claude API key is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== bwg_ai_get_claude_api_key();
	}

	/**
	 * Analyze one ad's creative. Never throws or blocks the caller — any
	 * failure (no creative available, API error, unparseable response)
	 * comes back as an empty result rather than a WP_Error, since vision
	 * analysis is a best-effort enhancement, not a required step.
	 *
	 * @param int    $session_id
	 * @param string $platform  'meta' | 'google'
	 * @param array  $ad        The normalized ad array (pre-insert) — reads
	 *                          screenshot_path / ad_image_url / ad_snapshot_url.
	 * @return array{
	 *   analyzed: bool,
	 *   reason?: string,
	 *   flags: array,
	 *   screenshot_path?: string,
	 *   screenshot_bytes?: int,
	 * }
	 */
	public static function analyze( $session_id, $platform, array $ad ) {
		if ( ! self::is_configured() ) {
			return [ 'analyzed' => false, 'reason' => 'not_configured', 'flags' => [] ];
		}

		$image = self::resolve_creative( $session_id, $platform, $ad );
		if ( is_wp_error( $image ) ) {
			return [ 'analyzed' => false, 'reason' => $image->get_error_code(), 'flags' => [] ];
		}

		$response = self::call_claude( $image['binary'], $image['media_type'], (string) ( $ad['ad_copy'] ?? '' ) );
		if ( is_wp_error( $response ) ) {
			BWG_AI_Session::log( $session_id, 'vision_api_error', 'Claude vision call failed: ' . $response->get_error_message() );
			$result = [ 'analyzed' => false, 'reason' => 'api_error', 'flags' => [] ];
		} else {
			$result = [ 'analyzed' => true, 'flags' => self::parse_flags( $response ) ];
		}

		if ( ! empty( $image['newly_captured'] ) ) {
			$result['screenshot_path']  = $image['relative_path'];
			$result['screenshot_bytes'] = $image['bytes'];
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Creative resolution
	// -------------------------------------------------------------------------

	/**
	 * Get raw image bytes + media type for the ad's creative, from whichever
	 * source is available. For a Meta ad that only has ad_snapshot_url (an
	 * HTML page, not an image), captures a screenshot of it via the same
	 * render-provider used for Google Ads Transparency (M12) and persists
	 * it through the screenshot store so it isn't re-captured next time.
	 *
	 * @return array{binary:string, media_type:string, newly_captured?:bool, relative_path?:string, bytes?:int}|WP_Error
	 */
	private static function resolve_creative( $session_id, $platform, array $ad ) {
		// Already-captured local screenshot (e.g. this Google ad in the same save_ads() pass).
		if ( ! empty( $ad['screenshot_path'] ) ) {
			$full = BWG_AI_Screenshot_Store::full_path( $ad['screenshot_path'] );
			if ( $full ) {
				$binary = file_get_contents( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false !== $binary ) {
					$filetype = wp_check_filetype( $full );
					return [ 'binary' => $binary, 'media_type' => $filetype['type'] ?: 'image/png' ];
				}
			}
		}

		// A direct image URL (not currently populated by the Meta client, but
		// supported here for CSV-imported or future ad sources).
		if ( ! empty( $ad['ad_image_url'] ) ) {
			$response = wp_remote_get( $ad['ad_image_url'], [ 'timeout' => 20 ] );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$content_type = wp_remote_retrieve_header( $response, 'content-type' );
				$body         = wp_remote_retrieve_body( $response );
				if ( $body && $content_type && 0 === strpos( $content_type, 'image/' ) ) {
					return [ 'binary' => $body, 'media_type' => $content_type ];
				}
			}
			return new WP_Error( 'image_fetch_failed', 'Could not fetch ad_image_url.' );
		}

		// Meta's hosted ad snapshot page — capture it via the render provider
		// (same abstraction as Google Ads Transparency, M12) so vision has
		// something to look at.
		if ( ! empty( $ad['ad_snapshot_url'] ) ) {
			if ( ! class_exists( 'BWG_AI_Render_Provider' ) || ! BWG_AI_Render_Provider::is_configured() ) {
				return new WP_Error( 'no_render_provider', 'No screenshot render API configured to capture the Meta ad snapshot.' );
			}

			$capture = BWG_AI_Render_Provider::capture( $ad['ad_snapshot_url'] );
			if ( is_wp_error( $capture ) ) {
				return $capture;
			}

			$ext   = 0 === strpos( $capture['content_type'], 'image/jpeg' ) ? 'jpg' : ( 0 === strpos( $capture['content_type'], 'image/webp' ) ? 'webp' : 'png' );
			$saved = BWG_AI_Screenshot_Store::save( $session_id, $platform, $capture['binary'], $ext );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			return [
				'binary'         => $capture['binary'],
				'media_type'     => $capture['content_type'],
				'newly_captured' => true,
				'relative_path'  => $saved['relative_path'],
				'bytes'          => $saved['bytes'],
			];
		}

		return new WP_Error( 'no_creative', 'No image, screenshot, or ad snapshot available to analyze.' );
	}

	// -------------------------------------------------------------------------
	// Claude API call
	// -------------------------------------------------------------------------

	/**
	 * @param string $binary       Raw image bytes.
	 * @param string $media_type   e.g. 'image/png'
	 * @param string $ad_copy      Ad copy text, given as context alongside the image.
	 * @return string|WP_Error     Claude's raw text response, or WP_Error.
	 */
	private static function call_claude( $binary, $media_type, $ad_copy ) {
		$allowed_types = [ 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ];
		if ( ! in_array( $media_type, $allowed_types, true ) ) {
			$media_type = 'image/png';
		}

		$user_text = $ad_copy
			? "Ad copy running alongside this creative, for context:\n\n{$ad_copy}"
			: 'No ad copy text was available — review the image alone.';

		$body = [
			'model'         => self::MODEL,
			'max_tokens'    => 2048,
			'system'        => self::SYSTEM_PROMPT,
			'output_config' => [ 'effort' => 'low' ],
			'messages'      => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'   => 'image',
							'source' => [
								'type'       => 'base64',
								'media_type' => $media_type,
								'data'       => base64_encode( $binary ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
							],
						],
						[
							'type' => 'text',
							'text' => $user_text,
						],
					],
				],
			],
		];

		$response = wp_remote_post( self::API_URL, [
			'timeout' => 45,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => bwg_ai_get_claude_api_key(),
				'anthropic-version' => self::API_VERSION,
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = $data['error']['message'] ?? "Claude API returned HTTP {$code}.";
			return new WP_Error( 'claude_api_error', $message, [ 'status' => $code ] );
		}

		if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
			return new WP_Error( 'claude_refusal', 'Claude declined to analyze this image.' );
		}

		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'claude_empty_response', 'Claude returned no text content.' );
		}

		return $text;
	}

	// -------------------------------------------------------------------------
	// Response parsing
	// -------------------------------------------------------------------------

	/**
	 * Parse Claude's JSON-array response into the shared flag shape, tagging
	 * each with source: 'vision' so the UI can distinguish them from text
	 * compliance flags. Tolerant of the model wrapping the array in a
	 * markdown code fence despite instructions not to.
	 *
	 * @param string $text
	 * @return array
	 */
	private static function parse_flags( $text ) {
		$text = trim( $text );
		if ( 0 === strpos( $text, '```' ) ) {
			$text = preg_replace( '/^```(?:json)?\s*/', '', $text );
			$text = preg_replace( '/```\s*$/', '', $text );
			$text = trim( $text );
		}

		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$allowed_severity = [ 'high', 'medium', 'low' ];
		$flags = [];

		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) || empty( $item['description'] ) ) {
				continue;
			}
			$severity = in_array( $item['severity'] ?? '', $allowed_severity, true ) ? $item['severity'] : 'low';
			$flags[]  = [
				'rule_id'     => sanitize_key( $item['rule_id'] ?? 'vision_flag' ),
				'severity'    => $severity,
				'category'    => sanitize_text_field( $item['category'] ?? 'HIPAA / Legal' ),
				'description' => sanitize_text_field( $item['description'] ),
				'excerpt'     => '',
				'citation'    => sanitize_text_field( $item['citation'] ?? '' ),
				'source'      => 'vision',
			];
		}

		usort( $flags, static function ( $a, $b ) {
			$order = [ 'high' => 0, 'medium' => 1, 'low' => 2 ];
			return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
		} );

		return $flags;
	}
}
