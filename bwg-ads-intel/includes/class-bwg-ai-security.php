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
	 *
	 * @param string $token   The cf-turnstile-response token from the client.
	 * @param string $ip      Client IP for additional entropy.
	 * @return true|WP_Error
	 */
	public static function verify_captcha( $token, $ip = '' ) {
		$secret = bwg_ai_decrypt_secret( get_option( 'bwg_ai_captcha_secret_key', '' ) );

		if ( empty( $secret ) ) {
			// Fail closed: an unconfigured secret must not silently disable
			// the captcha check, or every install that hasn't set up
			// Turnstile yet would have no bot protection on this endpoint
			// at all, with no indication anywhere that it isn't working.
			BWG_AI_Session::log( null, 'captcha_misconfigured', 'Captcha secret key not configured; rejecting request.' );
			return new WP_Error( 'captcha_misconfigured', 'Security check is not available right now. Please try again later.', [ 'status' => 503 ] );
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

// -------------------------------------------------------------------------
// Secret encryption at rest (AES-256-CBC, keyed from WordPress AUTH_KEY /
// SECURE_AUTH_KEY). Matches the convention already established across the
// other BWG suite plugins so API keys/secrets are never stored in plaintext
// in wp_options.
// -------------------------------------------------------------------------

/**
 * Derive the 32-byte raw encryption key used for secret-at-rest encryption.
 *
 * @return string
 */
function bwg_ai_secret_encryption_key() {
	$auth_key        = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'auth-key-fallback';
	$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'secure-auth-key-fallback';
	return hash( 'sha256', $auth_key . $secure_auth_key, true );
}

/**
 * Encrypts a secret (API key, shared secret, etc.) for storage in wp_options.
 * Returns a base64-encoded "ciphertext::iv" string, or '' for empty input.
 *
 * @param string $plaintext
 * @return string
 */
function bwg_ai_encrypt_secret( $plaintext ) {
	$plaintext = (string) $plaintext;
	if ( '' === $plaintext ) {
		return '';
	}

	$key    = bwg_ai_secret_encryption_key();
	$iv     = random_bytes( 16 );
	$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $cipher ) {
		return '';
	}

	return base64_encode( $cipher . '::' . $iv );
}

/**
 * Decrypts a secret previously stored with bwg_ai_encrypt_secret().
 *
 * Tolerates legacy plaintext values (from before encryption-at-rest was
 * introduced) by falling back to returning the raw stored string whenever
 * it doesn't decode/decrypt as one of our ciphertext blobs, so existing
 * installs don't break on upgrade.
 *
 * @param string $stored
 * @return string
 */
function bwg_ai_decrypt_secret( $stored ) {
	$stored = (string) $stored;
	if ( '' === $stored ) {
		return '';
	}

	$decoded = base64_decode( $stored, true );
	if ( false === $decoded ) {
		return $stored;
	}

	$parts = explode( '::', $decoded, 2 );
	if ( 2 !== count( $parts ) ) {
		return $stored;
	}

	[ $cipher, $iv ] = $parts;
	$key   = bwg_ai_secret_encryption_key();
	$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

	return false !== $plain ? $plain : $stored;
}

/**
 * Resolve the Google Places API key: this plugin's own key if configured,
 * otherwise a sibling BWG suite plugin's key if one is available (see
 * includes/bwg-suite-bridge.php). Never writes anything.
 */
function bwg_ai_get_google_places_key(): string {
	$own = bwg_ai_decrypt_secret( (string) get_option( 'bwg_ai_google_places_key', '' ) );
	if ( '' !== $own ) {
		return $own;
	}
	if ( function_exists( 'bwg_suite_find_shared_credential' ) ) {
		$shared = bwg_suite_find_shared_credential( 'google_places_api_key', 'Ads Intelligence' );
		if ( null !== $shared ) {
			return $shared['value'];
		}
	}
	return '';
}

/**
 * Resolve the Meta Ad Library (Graph API ads_archive) access token.
 * Long-lived token from a Meta developer app with the ads_read permission.
 */
function bwg_ai_get_meta_ad_library_token(): string {
	return bwg_ai_decrypt_secret( (string) get_option( 'bwg_ai_meta_ad_library_token', '' ) );
}
