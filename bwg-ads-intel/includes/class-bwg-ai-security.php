<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Security {

	/** Private IP ranges that must never be targeted by URL inputs. */
	const PRIVATE_IP_PATTERNS = [
		'/^10\./i',
		'/^172\.(1[6-9]|2\d|3[01])\./i',
		'/^192\.168\./i',
		'/^127\./i',
		'/^::1$/i',
		'/^fc[0-9a-f]{2}:/i',   // IPv6 unique local
		'/^fe80:/i',             // IPv6 link-local
		'/^0\./i',               // 0.x.x.x
		'/^169\.254\./i',        // link-local
		'/^localhost$/i',
	];

	// -------------------------------------------------------------------------
	// Nonce / capability
	// -------------------------------------------------------------------------

	/**
	 * Verify the WP REST nonce from the request.
	 * Returns true on success, WP_Error on failure.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function verify_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		if ( ! $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'invalid_nonce', 'Invalid or missing nonce.', [ 'status' => 403 ] );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Input sanitization & validation
	// -------------------------------------------------------------------------

	/**
	 * Sanitize and validate a URL submitted by the public form.
	 * Returns the cleaned URL string, or WP_Error if invalid.
	 *
	 * Rejects: non http(s) schemes, private/loopback IP targets, and bare IPs.
	 *
	 * @param string $raw
	 * @return string|WP_Error
	 */
	public static function sanitize_url_input( $raw ) {
		$url = esc_url_raw( trim( $raw ) );

		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', 'Please enter a valid website URL.', [ 'status' => 400 ] );
		}

		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], [ 'http', 'https' ], true ) ) {
			return new WP_Error( 'invalid_url', 'URL must use http or https.', [ 'status' => 400 ] );
		}

		if ( empty( $parsed['host'] ) ) {
			return new WP_Error( 'invalid_url', 'URL must include a host.', [ 'status' => 400 ] );
		}

		$host = strtolower( $parsed['host'] );

		// Resolve the host to IP to catch DNS-rebinding to private ranges.
		$ip = gethostbyname( $host );

		foreach ( self::PRIVATE_IP_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $host ) || preg_match( $pattern, $ip ) ) {
				return new WP_Error( 'invalid_url', 'URL target is not allowed.', [ 'status' => 400 ] );
			}
		}

		// Require at least one dot in the host (reject bare hostnames like "localhost").
		if ( strpos( $host, '.' ) === false ) {
			return new WP_Error( 'invalid_url', 'Please enter a full domain name.', [ 'status' => 400 ] );
		}

		return $url;
	}

	/**
	 * Sanitize an email address. Returns cleaned email or WP_Error.
	 *
	 * @param string $raw
	 * @return string|WP_Error
	 */
	public static function sanitize_email_input( $raw ) {
		$email = sanitize_email( trim( $raw ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Please enter a valid email address.', [ 'status' => 400 ] );
		}
		return $email;
	}

	// -------------------------------------------------------------------------
	// Cloudflare Turnstile captcha
	// -------------------------------------------------------------------------

	/**
	 * Verify a Cloudflare Turnstile token.
	 * Returns true on success, WP_Error on failure.
	 * If no secret key is configured, verification is skipped (dev environments).
	 *
	 * @param string $token   The cf-turnstile-response token from the client.
	 * @param string $ip      Client IP for additional entropy.
	 * @return true|WP_Error
	 */
	public static function verify_captcha( $token, $ip = '' ) {
		$secret = get_option( 'bwg_ai_captcha_secret_key', '' );

		if ( empty( $secret ) ) {
			// Not configured — skip in dev. Log a notice.
			BWG_AI_Session::log( null, 'captcha_skipped', 'Captcha secret key not configured.' );
			return true;
		}

		if ( empty( $token ) ) {
			return new WP_Error( 'captcha_missing', 'Please complete the security check.', [ 'status' => 400 ] );
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			[
				'timeout' => 10,
				'body'    => [
					'secret'   => $secret,
					'response' => sanitize_text_field( $token ),
					'remoteip' => $ip,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'captcha_error', 'Could not verify security check. Please try again.', [ 'status' => 503 ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['success'] ) ) {
			$codes = isset( $body['error-codes'] ) ? implode( ', ', $body['error-codes'] ) : 'unknown';
			BWG_AI_Session::log( null, 'captcha_failed', 'Turnstile verification failed: ' . $codes, [ 'ip' => $ip ] );
			return new WP_Error( 'captcha_failed', 'Security check failed. Please try again.', [ 'status' => 400 ] );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// EntityIQ webhook signature (HMAC-SHA256)
	// -------------------------------------------------------------------------

	/**
	 * Verify the HMAC-SHA256 signature on an incoming EntityIQ webhook request.
	 *
	 * Expected headers:
	 *   X-BWG-Signature: sha256=<hex>
	 *   X-BWG-Timestamp: <unix timestamp>
	 *
	 * The signed payload is: raw_body + timestamp.
	 * Requests older than 5 minutes are rejected to prevent replay attacks.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function verify_webhook_signature( WP_REST_Request $request ) {
		$secret = get_option( 'bwg_ai_entityiq_secret', '' );

		if ( empty( $secret ) ) {
			return new WP_Error( 'webhook_not_configured', 'Webhook secret not configured.', [ 'status' => 503 ] );
		}

		$sig_header = $request->get_header( 'X-BWG-Signature' );
		$timestamp  = $request->get_header( 'X-BWG-Timestamp' );

		if ( ! $sig_header || ! $timestamp ) {
			return new WP_Error( 'webhook_missing_headers', 'Missing signature headers.', [ 'status' => 401 ] );
		}

		// Replay protection: reject if timestamp is more than 5 minutes old.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new WP_Error( 'webhook_replay', 'Request timestamp out of range.', [ 'status' => 401 ] );
		}

		$raw_body = $request->get_body();
		$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body . $timestamp, $secret );

		// Constant-time comparison to prevent timing attacks.
		if ( ! hash_equals( $expected, $sig_header ) ) {
			BWG_AI_Session::log( null, 'webhook_sig_fail', 'Webhook signature mismatch.' );
			return new WP_Error( 'webhook_invalid_sig', 'Invalid webhook signature.', [ 'status' => 401 ] );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// IP helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the client IP address. Only trusts REMOTE_ADDR — never
	 * X-Forwarded-For or similar headers that clients can spoof.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	// -------------------------------------------------------------------------
	// Access code auth (for /resume endpoint)
	// -------------------------------------------------------------------------

	/**
	 * Resolve a resume request via access code or resume token.
	 * Returns the session object or WP_Error.
	 *
	 * @param string $access_code
	 * @param string $resume_token
	 * @return object|WP_Error
	 */
	public static function resolve_resume( $access_code, $resume_token ) {
		if ( ! empty( $resume_token ) ) {
			$session = BWG_AI_Session::get_by_resume_token( sanitize_text_field( $resume_token ) );
			if ( $session ) {
				return $session;
			}
		}

		if ( ! empty( $access_code ) ) {
			$session = BWG_AI_Session::get_by_access_code( sanitize_text_field( $access_code ) );
			if ( $session ) {
				return $session;
			}
		}

		return new WP_Error( 'session_not_found', 'Access code or resume token not found.', [ 'status' => 404 ] );
	}
}
