<?php
/**
 * BWG Suite Bridge — shared inter-plugin cache helpers.
 * Guarded against re-declaration; safe to include from multiple plugins.
 */

if ( ! function_exists( 'bwg_normalize_domain' ) ) :

function bwg_normalize_domain( string $url_or_domain ): string {
	if ( ! preg_match( '#^https?://#', $url_or_domain ) ) {
		$url_or_domain = 'https://' . $url_or_domain;
	}
	$host = strtolower( parse_url( $url_or_domain, PHP_URL_HOST ) ?? $url_or_domain );
	return preg_replace( '#^www\.#', '', $host );
}

/**
 * Encrypt/decrypt the shared bwg_remote_site_token / bwg_remote_access_token
 * options at rest. Same AES-256-CBC + AUTH_KEY/SECURE_AUTH_KEY scheme already
 * used for provider API keys elsewhere in the suite (see class-bwg-pce-settings.php),
 * reimplemented here since this file is copy-deployed into plugins that don't
 * have that class.
 */
function bwg_suite_encrypt_secret( string $plain ): string {
	if ( $plain === '' ) {
		return '';
	}
	$auth_key        = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'auth-key-fallback';
	$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'secure-auth-key-fallback';
	$key             = hash( 'sha256', $auth_key . $secure_auth_key, true );
	$iv              = random_bytes( 16 );
	$cipher          = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return $cipher === false ? '' : base64_encode( $cipher . '::' . $iv );
}

function bwg_suite_decrypt_secret( string $stored ): string {
	if ( $stored === '' ) {
		return '';
	}
	$decoded = base64_decode( $stored, true );
	if ( $decoded === false ) {
		return '';
	}
	$parts = explode( '::', $decoded, 2 );
	if ( count( $parts ) !== 2 ) {
		return '';
	}
	[ $cipher, $iv ]  = $parts;
	$auth_key        = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'auth-key-fallback';
	$secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'secure-auth-key-fallback';
	$key             = hash( 'sha256', $auth_key . $secure_auth_key, true );
	$plain           = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return $plain === false ? '' : $plain;
}

function bwg_cache_table_exists(): bool {
	global $wpdb;
	$t = $wpdb->prefix . 'bwg_data_cache';
	return $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) === $t;
}

function bwg_cache_get( string $domain, string $data_type, int $min_quality = 1 ): ?array {
	if ( ! bwg_cache_table_exists() ) return null;
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT data, quality, source_plugin, fetched_at, expires_at
		 FROM {$wpdb->prefix}bwg_data_cache
		 WHERE domain = %s AND data_type = %s AND expires_at > NOW() AND quality >= %d LIMIT 1",
		bwg_normalize_domain( $domain ), $data_type, $min_quality
	) );
	if ( ! $row ) return null;
	$data = json_decode( $row->data, true );
	if ( $data === null ) return null;
	$data['_cache_meta'] = [
		'source_plugin' => $row->source_plugin,
		'fetched_at'    => $row->fetched_at,
		'expires_at'    => $row->expires_at,
		'quality'       => (int) $row->quality,
	];
	return $data;
}

function bwg_cache_set( string $domain, string $data_type, array $data, string $source_plugin, int $quality = 2, int $ttl_seconds = 0 ): bool {
	if ( ! bwg_cache_table_exists() ) return false;
	global $wpdb;
	if ( $ttl_seconds <= 0 ) $ttl_seconds = bwg_cache_ttl( $data_type );
	return false !== $wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}bwg_data_cache
		    (domain, data_type, data, source_plugin, quality, fetched_at, expires_at)
		 VALUES (%s, %s, %s, %s, %d, %s, %s)
		 ON DUPLICATE KEY UPDATE
		    data=VALUES(data), source_plugin=VALUES(source_plugin),
		    quality=VALUES(quality), fetched_at=VALUES(fetched_at), expires_at=VALUES(expires_at)",
		bwg_normalize_domain( $domain ), $data_type, wp_json_encode( $data ),
		$source_plugin, $quality,
		gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + $ttl_seconds )
	) );
}

function bwg_cache_ttl( string $data_type ): int {
	static $defaults = [
		'psi_mobile'             => 86400,
		'psi_desktop'            => 86400,
		'places_basic'           => 259200,
		'places_full'            => 259200,
		'gbp_audit'              => 172800,
		'dataforseo_local_pack'  => 86400,
		'dataforseo_serp'        => 172800,
		'brightlocal_citations'  => 604800,
		'tech_stack'             => 604800,
		'security_headers'       => 259200,
		'domain_authority'       => 604800,
		'screenshot_url'         => 604800,
		'health_check'           => 21600,
		'schema_score'           => 604800,
		'entity_ids'             => 2592000,
		'compliance_score'       => 2592000,
		'social_presence'        => 604800,
		'yelp_reviews'           => 604800,
		'site_metadata'          => 86400,
		'webring_listing'        => 3600,
	];
	$ttls = get_option( 'bwg_shared_cache_ttls', [] );
	return (int) ( $ttls[ 'ttl_' . $data_type ] ?? $defaults[ $data_type ] ?? 86400 );
}

function bwg_suite_active_plugins(): array {
	$active = [];
	if ( class_exists( 'BWG_Speed_Test' ) || function_exists( 'bwg_run_migrations' ) )
		$active['speedscout'] = [ 'label' => 'SpeedScout', 'provides' => [ 'psi_mobile', 'psi_desktop', 'compliance_score' ], 'consumes' => [ 'places_basic', 'dataforseo_serp', 'health_check', 'schema_score' ] ];
	if ( class_exists( 'BWG_Compliance_Auditor' ) )
		$active['compliance'] = [ 'label' => 'Compliance', 'provides' => [ 'compliance_score', 'security_headers', 'site_metadata' ], 'consumes' => [ 'psi_desktop', 'health_check' ] ];
	if ( class_exists( 'BWG_PCE_DB' ) || defined( 'BWG_PCE_VERSION' ) )
		$active['automail'] = [ 'label' => 'AutoMail', 'provides' => [ 'psi_mobile', 'psi_desktop', 'tech_stack', 'security_headers', 'domain_authority', 'yelp_reviews', 'screenshot_url', 'brightlocal_citations', 'dataforseo_local_pack', 'dataforseo_serp' ], 'consumes' => [ 'places_basic', 'health_check', 'webring_listing' ] ];
	if ( class_exists( 'WP_Webring_Pro' ) )
		$active['webring'] = [ 'label' => 'Webring', 'provides' => [ 'places_basic', 'places_full', 'health_check', 'site_metadata', 'screenshot_url', 'social_presence', 'webring_listing' ], 'consumes' => [ 'psi_mobile', 'schema_score', 'compliance_score' ] ];
	if ( defined( 'ENTITYIQ_VERSION' ) || get_option( 'entityiq_settings' ) !== false )
		$active['entityiq'] = [ 'label' => 'EntityIQ', 'provides' => [ 'schema_score', 'entity_ids', 'gbp_audit', 'brightlocal_citations', 'dataforseo_local_pack', 'social_presence', 'site_metadata' ], 'consumes' => [ 'psi_mobile', 'places_basic', 'health_check', 'webring_listing' ] ];
	return $active;
}

function bwg_get_webring_table(): ?string {
	global $wpdb;
	$t = $wpdb->prefix . 'webring_links';
	return $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) === $t ? $t : null;
}

/**
 * Shared-credential peeking: lets a plugin reuse a sibling BWG plugin's
 * already-configured API key instead of requiring the admin to re-enter the
 * same credential in every plugin. This never writes or moves any secret —
 * each source's fetch closure reads that plugin's own storage location
 * in-process (same PHP request, same WordPress install) and returns the
 * decrypted value; nothing is transmitted between sites or plugins.
 *
 * Each source needs its own decoder because the suite's plugins didn't all
 * standardize on the same storage/encryption scheme historically:
 *   - bridge-compatible AES-256-CBC via bwg_suite_decrypt_secret() (most
 *     plugins, after settings were migrated to encrypt at rest)
 *   - EntityIQ's own AES-256-CBC scheme (different key derivation, IV-prefixed
 *     base64 instead of the bridge's cipher::iv format) — replicated inline
 *     below since the underlying WordPress constants it derives its key from
 *     (SECURE_AUTH_SALT) are readable by any plugin on the same install.
 *   - Webring, which stores its settings as a JSON blob in a custom
 *     `wp_webring_settings` table rather than wp_options at all.
 */
function bwg_suite_shared_credential_sources( string $canonical_name ): array {
	switch ( $canonical_name ) {
		case 'google_places_api_key':
			return [
				[
					'label'  => 'SpeedScout',
					'active' => static function () {
						return class_exists( 'BWG_Speed_Test' ) || function_exists( 'bwg_run_migrations' );
					},
					'fetch'  => static function () {
						$raw = (string) get_option( 'bwg_sa_google_places_key', '' );
						if ( $raw === '' ) {
							return '';
						}
						// SpeedScout's own AES-256-CBC scheme (see
						// site-audit/class-bwg-sa-admin.php::decrypt_option()):
						// key = sha256(SECURE_AUTH_SALT) truncated to 32 bytes,
						// IV-prefixed base64 (not the bridge's cipher::iv format).
						if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
							return $raw; // legacy/never-encrypted value
						}
						$key     = substr( hash( 'sha256', SECURE_AUTH_SALT, true ), 0, 32 );
						$decoded = base64_decode( $raw, true );
						if ( $decoded === false || strlen( $decoded ) < 17 ) {
							return $raw; // not our ciphertext format — treat as already-plaintext
						}
						$iv     = substr( $decoded, 0, 16 );
						$cipher = substr( $decoded, 16 );
						$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
						return $plain === false ? $raw : $plain;
					},
				],
				[
					'label'  => 'Ads Intelligence',
					'active' => static function () {
						return defined( 'BWG_AI_VERSION' ) || class_exists( 'BWG_AI_Security' );
					},
					'fetch'  => static function () {
						return bwg_suite_decrypt_secret( (string) get_option( 'bwg_ai_google_places_key', '' ) );
					},
				],
				[
					'label'  => 'EntityIQ',
					'active' => static function () {
						return defined( 'ENTITYIQ_VERSION' ) || get_option( 'entityiq_settings' ) !== false;
					},
					'fetch'  => static function () {
						$raw = (string) get_option( 'entityiq_google_places_api_key_enc', '' );
						if ( $raw === '' ) {
							return '';
						}
						$salt    = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'entityiq-fallback-salt-please-define-SECURE_AUTH_SALT';
						$key     = hash( 'sha256', $salt . 'entityiq-v1', true );
						$decoded = base64_decode( $raw, true );
						if ( $decoded === false || strlen( $decoded ) < 17 ) {
							return '';
						}
						$iv     = substr( $decoded, 0, 16 );
						$cipher = substr( $decoded, 16 );
						$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
						return $plain === false ? '' : $plain;
					},
				],
				[
					'label'  => 'Webring',
					'active' => static function () {
						return class_exists( 'WP_Webring_Pro' );
					},
					'fetch'  => static function () {
						global $wpdb;
						$table = $wpdb->prefix . 'webring_settings';
						if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
							return '';
						}
						$row = $wpdb->get_row( $wpdb->prepare( "SELECT value FROM {$table} WHERE id = %s", 'global' ), ARRAY_A );
						if ( ! $row ) {
							return '';
						}
						$settings = json_decode( $row['value'], true );
						$stored   = is_array( $settings ) ? (string) ( $settings['googlePlacesApiKey'] ?? '' ) : '';
						if ( $stored === '' ) {
							return '';
						}
						// Webring's settings blob is plaintext, but decrypt gracefully
						// falls back to the raw value if it was never encrypted.
						$decrypted = bwg_suite_decrypt_secret( $stored );
						return $decrypted !== '' ? $decrypted : $stored;
					},
				],
			];
		default:
			return [];
	}
}

/**
 * Find the first configured copy of a named credential among active sibling
 * plugins. Returns null if none is available (either no sibling is active,
 * or none has the credential configured).
 *
 * @param string $canonical_name  e.g. 'google_places_api_key'.
 * @param string $exclude_label   Pass the calling plugin's own label (e.g.
 *                                'SpeedScout') to skip its own registry
 *                                entry — relevant since a plugin's own
 *                                option is always checked separately/first
 *                                by its caller, so it should never also
 *                                "find" itself as a sibling source.
 */
function bwg_suite_find_shared_credential( string $canonical_name, string $exclude_label = '' ): ?array {
	foreach ( bwg_suite_shared_credential_sources( $canonical_name ) as $source ) {
		if ( $exclude_label !== '' && $source['label'] === $exclude_label ) {
			continue;
		}
		if ( ! $source['active']() ) {
			continue;
		}
		$value = trim( (string) $source['fetch']() );
		if ( $value === '' ) {
			continue;
		}
		return [
			'value'        => $value,
			'source_label' => $source['label'],
		];
	}
	return null;
}

/**
 * For settings-page UI: describe every known source's install/configured
 * status without ever exposing the secret value itself, so a plugin can
 * show "shared from X" when available, or a greyed "could come from X if
 * installed/configured" hint when not — without a live secret in the markup.
 *
 * @param string $exclude_label  See bwg_suite_find_shared_credential() — pass
 *                               the calling plugin's own label so its own
 *                               entry doesn't appear in its own hint list.
 */
function bwg_suite_credential_source_statuses( string $canonical_name, string $exclude_label = '' ): array {
	$statuses = [];
	foreach ( bwg_suite_shared_credential_sources( $canonical_name ) as $source ) {
		if ( $exclude_label !== '' && $source['label'] === $exclude_label ) {
			continue;
		}
		$active     = (bool) $source['active']();
		$configured = $active && trim( (string) $source['fetch']() ) !== '';
		$statuses[] = [
			'label'      => $source['label'],
			'installed'  => $active,
			'configured' => $configured,
		];
	}
	return $statuses;
}

function bwg_suite_fetch_from_remote( string $domain, array $data_types ): array {
	$remote_url   = get_option( 'bwg_remote_site_url' );
	$remote_token = bwg_suite_decrypt_secret( (string) get_option( 'bwg_remote_site_token' ) );

	if ( ! $remote_url || ! $remote_token ) return [];

	$response = wp_remote_post(
		trailingslashit( $remote_url ) . 'wp-json/bwg-suite/v1/cache/query',
		[
			'headers' => [
				'X-BWG-Remote-Token' => $remote_token,
				'Content-Type'       => 'application/json',
			],
			'body'    => wp_json_encode( [ 'domain' => $domain, 'data_types' => $data_types ] ),
			'timeout' => 8,
		]
	);

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return [];
	}

	$body    = json_decode( wp_remote_retrieve_body( $response ), true );
	$results = $body['results'] ?? [];

	foreach ( $results as $type => $data ) {
		$meta       = $data['_cache_meta'] ?? [];
		$fetched_at = strtotime( $meta['fetched_at'] ?? 'now' );
		$ttl        = bwg_cache_ttl( $type );
		$remaining  = max( 0, $ttl - ( time() - $fetched_at ) );
		if ( $remaining > 300 ) {
			bwg_cache_set( $domain, $type, $data, 'remote:' . ( $body['site_url'] ?? $remote_url ), 2, $remaining );
		}
	}

	return $results;
}

endif; // function_exists guard
