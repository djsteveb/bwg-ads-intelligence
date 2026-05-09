<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase 1 — Discovery Engine
 *
 * Orchestrates all business fingerprinting sub-tasks for a session.
 * Called by the bwg_ai_run_discovery cron (fires 5s after /start).
 *
 * Sub-tasks run sequentially; each updates progress in wp_bwg_ai_discovered
 * so the /discovery-status endpoint can report incremental progress.
 *
 * If bwg-speed-sitescout is active, BWG_CPA_Discovery handles GBP/social/pixel
 * detection and we extend those results rather than duplicating the work.
 */
class BWG_AI_Discovery {

	/** Sub-tasks and their relative progress weight (must sum to 100). */
	const TASK_WEIGHTS = [
		'fetch'       => 5,
		'nap'         => 10,
		'gbp'         => 20,
		'social'      => 10,
		'pixels'      => 10,
		'tech_stack'  => 10,
		'whois'       => 15,
		'legitscript' => 15,
		'licensure'   => 5,
	];

	private $session;
	private $html    = '';
	private $headers = [];
	private $domain  = '';
	private $progress_pct = 0;

	// -------------------------------------------------------------------------
	// Cron entry point
	// -------------------------------------------------------------------------

	/**
	 * Main handler — called by bwg_ai_run_discovery cron with session_id arg.
	 *
	 * @param int $session_id
	 */
	public function run( $session_id ) {
		if ( ! wp_doing_cron() ) {
			return;
		}

		$this->session = BWG_AI_Session::get( absint( $session_id ) );
		if ( ! $this->session || $this->session->status !== 'active' ) {
			return;
		}

		$url          = $this->session->website_url;
		$this->domain = wp_parse_url( $url, PHP_URL_HOST );

		// Insert a placeholder discovered row so status polling has something to return.
		$this->insert_placeholder();

		try {
			$data = [];

			// 1. Fetch homepage HTML.
			$fetched = $this->fetch_page( $url );
			if ( ! $fetched ) {
				// Non-fatal — continue with empty HTML; sub-tasks handle missing content gracefully.
				BWG_AI_Session::log( $session_id, 'discovery_fetch_fail', 'Could not fetch homepage HTML.' );
			}
			$this->advance_progress( 'fetch' );

			// 2. NAP extraction.
			$data['nap'] = $this->extract_nap( $url );
			$this->advance_progress( 'nap' );
			$this->save_partial( $session_id, $data );

			// 3. Google Business Profile.
			$data['gbp'] = $this->match_gbp( $data['nap'] );
			$this->advance_progress( 'gbp' );
			$this->save_partial( $session_id, $data );

			// 4. Social profiles.
			$data['social'] = $this->detect_social_profiles( $url );
			$this->advance_progress( 'social' );

			// 5. Pixels.
			$data['pixels'] = $this->detect_pixels();
			$this->advance_progress( 'pixels' );
			$this->save_partial( $session_id, $data );

			// 6. Tech stack.
			$data['tech_stack'] = $this->fingerprint_tech_stack();
			$this->advance_progress( 'tech_stack' );

			// 7. WHOIS / RDAP.
			$data['whois'] = $this->lookup_whois( $this->domain );
			$this->advance_progress( 'whois' );
			$this->save_partial( $session_id, $data );

			// 8. LegitScript.
			$data['legitscript'] = $this->check_legitscript( $this->domain, $data['nap']['name'] ?? '' );
			$this->advance_progress( 'legitscript' );

			// 9. State licensure signals.
			$data['licensure'] = $this->check_licensure_signals();
			$this->advance_progress( 'licensure' );

			// Final save with flags + confidence.
			$this->save_final( $session_id, $data );
			BWG_AI_Session::update_step( $session_id, 1 );
			BWG_AI_Session::log( $session_id, 'discovery_complete', 'Phase 1 discovery complete.' );

		} catch ( Exception $e ) {
			BWG_AI_Session::update_status( $session_id, 'error' );
			BWG_AI_Session::log( $session_id, 'discovery_error', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Sub-task 1 — Fetch homepage
	// -------------------------------------------------------------------------

	private function fetch_page( $url ) {
		// Use BWG_SA_Scraper if available (handles redirects, UA, retries).
		if ( ! class_exists( 'BWG_SA_Scraper' ) ) {
			require_once BWG_AI_DIR . 'includes/fallbacks/class-bwg-sa-scraper-stub.php';
		}

		$result = BWG_SA_Scraper::fetch( $url );
		if ( $result['error'] || $result['code'] < 200 || $result['code'] >= 400 ) {
			return false;
		}

		$this->html = $result['body'];

		// Also try to fetch the /contact page for richer NAP data.
		$contact_url  = trailingslashit( $url ) . 'contact';
		$contact_result = BWG_SA_Scraper::fetch( $contact_url );
		if ( ! $contact_result['error'] && $contact_result['code'] === 200 ) {
			$this->html .= $contact_result['body'];
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Sub-task 2 — NAP extraction
	// -------------------------------------------------------------------------

	private function extract_nap( $url ) {
		if ( class_exists( 'BWG_CPA_Discovery' ) ) {
			$d = new BWG_CPA_Discovery( $url );
			$d->fetch();
			return $d->extract_nap();
		}

		$nap = [ 'name' => '', 'address' => '', 'phone' => '' ];

		if ( empty( $this->html ) ) {
			return $nap;
		}

		// Try schema.org/LocalBusiness JSON-LD first.
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $this->html, $matches ) ) {
			foreach ( $matches[1] as $json_raw ) {
				$schema = json_decode( trim( $json_raw ), true );
				if ( ! $schema ) {
					continue;
				}
				// Handle @graph arrays.
				$items = isset( $schema['@graph'] ) ? $schema['@graph'] : [ $schema ];
				foreach ( $items as $item ) {
					$type = $item['@type'] ?? '';
					if ( in_array( $type, [ 'LocalBusiness', 'MedicalBusiness', 'Organization', 'Hospital', 'MedicalClinic' ], true ) ) {
						$nap['name']    = $nap['name']    ?: sanitize_text_field( $item['name'] ?? '' );
						$nap['phone']   = $nap['phone']   ?: sanitize_text_field( $item['telephone'] ?? '' );
						$addr           = $item['address'] ?? [];
						if ( is_array( $addr ) ) {
							$parts = array_filter( [
								$addr['streetAddress'] ?? '',
								$addr['addressLocality'] ?? '',
								$addr['addressRegion'] ?? '',
								$addr['postalCode'] ?? '',
							] );
							$nap['address'] = $nap['address'] ?: sanitize_text_field( implode( ', ', $parts ) );
						} elseif ( is_string( $addr ) ) {
							$nap['address'] = $nap['address'] ?: sanitize_text_field( $addr );
						}
					}
				}
			}
		}

		// Fallback: tel: links.
		if ( empty( $nap['phone'] ) && preg_match( '/href=["\']tel:([+\d\s\-().]+)["\']/', $this->html, $m ) ) {
			$nap['phone'] = sanitize_text_field( $m[1] );
		}

		// Fallback: OG site_name for business name.
		if ( empty( $nap['name'] ) && preg_match( '/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\'](.*?)["\']/', $this->html, $m ) ) {
			$nap['name'] = sanitize_text_field( $m[1] );
		}

		// Last resort: <title> tag.
		if ( empty( $nap['name'] ) && preg_match( '/<title>(.*?)<\/title>/si', $this->html, $m ) ) {
			$title = strip_tags( $m[1] );
			// Strip common suffixes like "| Home" or "– Treatment Center".
			$title       = preg_replace( '/\s*[\|\-–]\s*.+$/', '', $title );
			$nap['name'] = sanitize_text_field( trim( $title ) );
		}

		return $nap;
	}

	// -------------------------------------------------------------------------
	// Sub-task 3 — Google Business Profile
	// -------------------------------------------------------------------------

	private function match_gbp( $nap ) {
		if ( class_exists( 'BWG_CPA_Discovery' ) ) {
			$d = new BWG_CPA_Discovery( $this->session->website_url );
			return $d->match_gbp( get_option( 'bwg_ai_google_places_key', '' ) );
		}

		$api_key = get_option( 'bwg_ai_google_places_key', '' );
		$result  = [ 'place_id' => '', 'rating' => null, 'review_count' => null, 'category' => '' ];

		if ( empty( $api_key ) ) {
			BWG_AI_Session::log( $this->session->id, 'gbp_skipped', 'Google Places API key not configured.' );
			return $result;
		}

		// Prefer business name + domain for findplacefromtext.
		$query = ! empty( $nap['name'] ) ? $nap['name'] : $this->domain;
		if ( ! empty( $nap['address'] ) ) {
			$query .= ' ' . $nap['address'];
		}

		$response = wp_remote_get(
			add_query_arg( [
				'input'     => rawurlencode( $query ),
				'inputtype' => 'textquery',
				'fields'    => 'place_id,name,rating,user_ratings_total,types',
				'key'       => $api_key,
			], 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json' ),
			[ 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return $result;
		}

		$body      = json_decode( wp_remote_retrieve_body( $response ), true );
		$candidate = $body['candidates'][0] ?? null;

		if ( $candidate ) {
			$result['place_id']     = sanitize_text_field( $candidate['place_id'] ?? '' );
			$result['rating']       = isset( $candidate['rating'] ) ? (float) $candidate['rating'] : null;
			$result['review_count'] = isset( $candidate['user_ratings_total'] ) ? (int) $candidate['user_ratings_total'] : null;
			$result['category']     = isset( $candidate['types'][0] ) ? sanitize_text_field( $candidate['types'][0] ) : '';
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Sub-task 4 — Social profile detection
	// -------------------------------------------------------------------------

	private function detect_social_profiles( $url ) {
		if ( class_exists( 'BWG_CPA_Discovery' ) ) {
			$d = new BWG_CPA_Discovery( $url );
			return $d->detect_social_profiles();
		}

		$social = [
			'facebook'  => '',
			'instagram' => '',
			'linkedin'  => '',
			'tiktok'    => '',
			'youtube'   => '',
		];

		if ( empty( $this->html ) ) {
			return $social;
		}

		$patterns = [
			'facebook'  => '/https?:\/\/(?:www\.)?facebook\.com\/(?!sharer|share|dialog|plugins|tr)([a-zA-Z0-9.\-_]+)\/?/i',
			'instagram' => '/https?:\/\/(?:www\.)?instagram\.com\/([a-zA-Z0-9._]+)\/?/i',
			'linkedin'  => '/https?:\/\/(?:www\.)?linkedin\.com\/company\/([a-zA-Z0-9\-_.]+)\/?/i',
			'tiktok'    => '/https?:\/\/(?:www\.)?tiktok\.com\/@([a-zA-Z0-9._]+)\/?/i',
			'youtube'   => '/https?:\/\/(?:www\.)?youtube\.com\/(?:channel\/|c\/|@)([a-zA-Z0-9_\-]+)\/?/i',
		];

		foreach ( $patterns as $platform => $pattern ) {
			if ( preg_match( $pattern, $this->html, $m ) ) {
				// Store the full matched URL.
				$social[ $platform ] = esc_url_raw( $m[0] );
			}
		}

		return $social;
	}

	// -------------------------------------------------------------------------
	// Sub-task 5 — Pixel / tag detection
	// -------------------------------------------------------------------------

	private function detect_pixels() {
		if ( class_exists( 'BWG_CPA_Discovery' ) ) {
			$d = new BWG_CPA_Discovery( $this->session->website_url );
			return $d->detect_pixels();
		}

		$pixels = [
			'meta_pixel_id' => '',
			'gtm_id'        => '',
			'ga4_id'        => '',
			'tiktok_id'     => '',
			'linkedin_id'   => '',
		];

		if ( empty( $this->html ) ) {
			return $pixels;
		}

		// Meta Pixel — fbq('init', 'PIXEL_ID')
		if ( preg_match( "/fbq\s*\(\s*['\"]init['\"]\s*,\s*['\"](\d{10,20})['\"]/i", $this->html, $m ) ) {
			$pixels['meta_pixel_id'] = sanitize_text_field( $m[1] );
		}

		// Google Tag Manager — GTM-XXXXXXX
		if ( preg_match( '/(GTM-[A-Z0-9]{4,10})/i', $this->html, $m ) ) {
			$pixels['gtm_id'] = sanitize_text_field( $m[1] );
		}

		// GA4 — G-XXXXXXXXXX
		if ( preg_match( "/(G-[A-Z0-9]{6,12})/i", $this->html, $m ) ) {
			$pixels['ga4_id'] = sanitize_text_field( $m[1] );
		}

		// TikTok Pixel — ttq.load('PIXEL_ID')
		if ( preg_match( "/ttq\.load\s*\(\s*['\"]([A-Z0-9]{15,25})['\"]/i", $this->html, $m ) ) {
			$pixels['tiktok_id'] = sanitize_text_field( $m[1] );
		}

		// LinkedIn Insight Tag — _linkedin_partner_id = "PARTNER_ID"
		if ( preg_match( '/_linkedin_partner_id\s*=\s*["\'](\d+)["\']/', $this->html, $m ) ) {
			$pixels['linkedin_id'] = sanitize_text_field( $m[1] );
		}

		return $pixels;
	}

	// -------------------------------------------------------------------------
	// Sub-task 6 — Tech stack fingerprint
	// -------------------------------------------------------------------------

	private function fingerprint_tech_stack() {
		$detected = [];
		$html     = $this->html;

		if ( empty( $html ) ) {
			return $detected;
		}

		$patterns = self::tech_patterns();

		foreach ( $patterns as $name => $checks ) {
			foreach ( $checks as $check ) {
				$matched = false;
				if ( isset( $check['html'] ) && preg_match( $check['html'], $html ) ) {
					$matched = true;
				}
				if ( isset( $check['header'] ) && ! empty( $this->headers ) ) {
					foreach ( $this->headers as $header_line ) {
						if ( preg_match( $check['header'], $header_line ) ) {
							$matched = true;
							break;
						}
					}
				}
				if ( $matched ) {
					$detected[] = sanitize_text_field( $name );
					break;
				}
			}
		}

		return array_values( array_unique( $detected ) );
	}

	/**
	 * Minimal Wappalyzer-style pattern set relevant to treatment center sites.
	 * Patterns are case-insensitive regex matched against full page HTML or headers.
	 */
	private static function tech_patterns() {
		return [
			// CMS
			'WordPress'        => [ [ 'html' => '/wp-content\//i' ] ],
			'Drupal'           => [ [ 'html' => '/sites\/default\/files/i' ] ],
			'Joomla'           => [ [ 'html' => '/\/components\/com_/i' ] ],
			'Webflow'          => [ [ 'html' => '/webflow\.com\/css/i' ] ],

			// Page builders / themes
			'Elementor'        => [ [ 'html' => '/elementor-/i' ] ],
			'Divi'             => [ [ 'html' => '/et-pb-/i' ] ],

			// Forms
			'Gravity Forms'    => [ [ 'html' => '/gform_wrapper/i' ] ],
			'HubSpot Forms'    => [ [ 'html' => '/hubspot\.com\/forms/i' ] ],
			'Typeform'         => [ [ 'html' => '/typeform\.com/i' ] ],
			'JotForm'          => [ [ 'html' => '/jotform\.com/i' ] ],

			// Chat / messaging
			'Intercom'         => [ [ 'html' => '/widget\.intercom\.io/i' ] ],
			'Drift'            => [ [ 'html' => '/js\.driftt\.com/i' ] ],
			'LiveChat'         => [ [ 'html' => '/livechatinc\.com/i' ] ],
			'Podium'           => [ [ 'html' => '/connect\.podium\.com/i' ] ],
			'Tidio'            => [ [ 'html' => '/code\.tidio\.co/i' ] ],

			// Call tracking
			'CallRail'         => [ [ 'html' => '/callrail\.com/i' ] ],
			'CallTrackingMetrics' => [ [ 'html' => '/calltrackingmetrics\.com/i' ] ],

			// CRM / marketing
			'HubSpot'          => [ [ 'html' => '/hs-scripts\.com/i' ] ],
			'Salesforce'       => [ [ 'html' => '/salesforce\.com/i' ] ],
			'ActiveCampaign'   => [ [ 'html' => '/activecampaign\.com/i' ] ],
			'Mailchimp'        => [ [ 'html' => '/chimpstatic\.com/i' ] ],

			// Reviews / reputation
			'Birdeye'          => [ [ 'html' => '/birdeye\.com/i' ] ],
			'Podium Reviews'   => [ [ 'html' => '/reviews\.podium\.com/i' ] ],

			// Telehealth / scheduling
			'Zocdoc'           => [ [ 'html' => '/zocdoc\.com/i' ] ],
			'Acuity Scheduling'=> [ [ 'html' => '/acuityscheduling\.com/i' ] ],
			'Calendly'         => [ [ 'html' => '/calendly\.com/i' ] ],

			// Cookie / consent
			'OneTrust'         => [ [ 'html' => '/onetrust\.com/i' ] ],
			'Cookiebot'        => [ [ 'html' => '/cookiebot\.com/i' ] ],

			// CDN / infrastructure
			'Cloudflare'       => [ [ 'header' => '/cloudflare/i' ] ],
			'AWS CloudFront'   => [ [ 'header' => '/CloudFront/i' ] ],
		];
	}

	// -------------------------------------------------------------------------
	// Sub-task 7 — WHOIS / RDAP
	// -------------------------------------------------------------------------

	private function lookup_whois( $domain ) {
		$result = [
			'registrar'    => '',
			'created_at'   => null,
			'expires_at'   => null,
			'nameservers'  => '',
			'domain_age_days' => null,
			'flag_new_domain' => false,
		];

		// Strip www.
		$domain = preg_replace( '/^www\./i', '', $domain );

		$response = wp_remote_get(
			'https://rdap.org/domain/' . rawurlencode( $domain ),
			[ 'timeout' => 10, 'headers' => [ 'Accept' => 'application/json' ] ]
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $result;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! $data ) {
			return $result;
		}

		// Registrar.
		foreach ( $data['entities'] ?? [] as $entity ) {
			$roles = $entity['roles'] ?? [];
			if ( in_array( 'registrar', $roles, true ) ) {
				$result['registrar'] = sanitize_text_field( $entity['vcardArray'][1][1][3] ?? $entity['handle'] ?? '' );
				break;
			}
		}

		// Dates.
		foreach ( $data['events'] ?? [] as $event ) {
			$action = $event['eventAction'] ?? '';
			$date   = isset( $event['eventDate'] ) ? gmdate( 'Y-m-d', strtotime( $event['eventDate'] ) ) : null;
			if ( $action === 'registration' ) {
				$result['created_at'] = $date;
			} elseif ( in_array( $action, [ 'expiration', 'deletion' ], true ) ) {
				$result['expires_at'] = $date;
			}
		}

		// Nameservers.
		$ns = [];
		foreach ( $data['nameservers'] ?? [] as $nameserver ) {
			$ns[] = sanitize_text_field( $nameserver['ldhName'] ?? '' );
		}
		$result['nameservers'] = implode( ', ', array_filter( $ns ) );

		// Domain age / new-domain flag.
		if ( $result['created_at'] ) {
			$age_days                  = (int) floor( ( time() - strtotime( $result['created_at'] ) ) / 86400 );
			$result['domain_age_days'] = $age_days;
			$result['flag_new_domain'] = $age_days < 180; // Less than 6 months = red flag in addiction marketing.
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Sub-task 8 — LegitScript
	// -------------------------------------------------------------------------

	private function check_legitscript( $domain, $business_name ) {
		$result = [
			'status' => 'unknown',
			'raw'    => '',
		];

		// LegitScript public search — no official API for generic use.
		// We scrape their pharmacy/advertiser lookup page.
		$domain   = preg_replace( '/^www\./i', '', $domain );
		$lookup_url = 'https://legitscript.com/pharmacy/' . rawurlencode( $domain ) . '/';

		$response = wp_remote_get( $lookup_url, [
			'timeout'    => 12,
			'user-agent' => 'Mozilla/5.0 (compatible; BWGAdsIntel/1.0)',
		] );

		if ( is_wp_error( $response ) ) {
			return $result;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		$result['raw'] = substr( sanitize_textarea_field( $body ), 0, 500 );

		if ( $code === 404 ) {
			$result['status'] = 'not_found';
			return $result;
		}

		// Parse status indicators from page content.
		if ( stripos( $body, 'legitscript certified' ) !== false || stripos( $body, 'certified advertiser' ) !== false ) {
			$result['status'] = 'certified';
		} elseif ( stripos( $body, 'not recommended' ) !== false ) {
			$result['status'] = 'not_recommended';
		} elseif ( stripos( $body, 'rogue' ) !== false ) {
			$result['status'] = 'rogue';
		} elseif ( stripos( $body, 'caution' ) !== false ) {
			$result['status'] = 'caution';
		} elseif ( $code === 200 ) {
			$result['status'] = 'listed';
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Sub-task 9 — Licensure signals
	// -------------------------------------------------------------------------

	private function check_licensure_signals() {
		$signals  = [];
		$html     = $this->html;

		if ( empty( $html ) ) {
			return [ 'found' => [], 'missing_flag' => true ];
		}

		$checks = [
			'SAMHSA'    => '/samhsa/i',
			'JCAHO/TJC' => '/\b(?:jcaho|tjc|joint commission)\b/i',
			'CARF'      => '/\bcarf\b/i',
			'NAATP'     => '/naatp/i',
			'LegitScript (on-page)' => '/legitscript/i',
			'State License Number'  => '/(?:license|lic\.?)\s*(?:no\.?|number|#)\s*[\dA-Z\-]{4,}/i',
			'SAMHSA NPN'  => '/npn\s*[\dA-Z\-]{4,}/i',
		];

		foreach ( $checks as $label => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				$signals[] = $label;
			}
		}

		return [
			'found'        => $signals,
			'missing_flag' => empty( $signals ),
		];
	}

	// -------------------------------------------------------------------------
	// Progress tracking
	// -------------------------------------------------------------------------

	private function advance_progress( $task ) {
		$this->progress_pct = min( 99, $this->progress_pct + ( self::TASK_WEIGHTS[ $task ] ?? 0 ) );
		$this->update_progress_in_db( $this->progress_pct, $task );
	}

	private function update_progress_in_db( $pct, $current_task ) {
		global $wpdb;
		$confidence = wp_json_encode( [
			'progress_pct'  => $pct,
			'current_task'  => $current_task,
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		] );
		$wpdb->update(
			$wpdb->prefix . 'bwg_ai_discovered',
			[ 'discovery_confidence' => $confidence ],
			[ 'session_id' => $this->session->id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	// -------------------------------------------------------------------------
	// DB writes
	// -------------------------------------------------------------------------

	/**
	 * Insert an empty row so polling has something to return immediately.
	 */
	private function insert_placeholder() {
		global $wpdb;
		// Only insert if not already present (idempotent — cron may retry).
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$wpdb->prefix}bwg_ai_discovered` WHERE session_id = %d LIMIT 1",
				$this->session->id
			)
		);
		if ( $existing ) {
			return;
		}
		$wpdb->insert(
			$wpdb->prefix . 'bwg_ai_discovered',
			[
				'session_id'          => $this->session->id,
				'discovery_confidence'=> wp_json_encode( [ 'progress_pct' => 0, 'current_task' => 'starting' ] ),
			],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Incrementally write completed sub-tasks to the DB during the run.
	 */
	private function save_partial( $session_id, $data ) {
		global $wpdb;
		$row = $this->build_db_row( $data );
		// Add progress.
		$row['discovery_confidence'] = wp_json_encode( [
			'progress_pct' => $this->progress_pct,
			'current_task' => 'in_progress',
		] );

		$formats = array_fill( 0, count( $row ), '%s' );
		$wpdb->update(
			$wpdb->prefix . 'bwg_ai_discovered',
			$row,
			[ 'session_id' => absint( $session_id ) ],
			$formats,
			[ '%d' ]
		);
	}

	/**
	 * Final write — includes flags and marks progress at 100.
	 */
	private function save_final( $session_id, $data ) {
		global $wpdb;
		$row   = $this->build_db_row( $data );
		$flags = $this->compute_flags( $data );

		$row['discovery_flags']      = wp_json_encode( $flags );
		$row['discovery_confidence'] = wp_json_encode( [
			'progress_pct'  => 100,
			'current_task'  => 'complete',
			'flag_count'    => count( $flags ),
		] );

		$formats = array_fill( 0, count( $row ), '%s' );
		$wpdb->update(
			$wpdb->prefix . 'bwg_ai_discovered',
			$row,
			[ 'session_id' => absint( $session_id ) ],
			$formats,
			[ '%d' ]
		);
	}

	/**
	 * Map discovery data arrays to DB column names.
	 */
	private function build_db_row( $data ) {
		$row = [];

		if ( isset( $data['nap'] ) ) {
			$row['business_name']    = $data['nap']['name']    ?? '';
			$row['business_address'] = $data['nap']['address'] ?? '';
			$row['business_phone']   = $data['nap']['phone']   ?? '';
		}

		if ( isset( $data['gbp'] ) ) {
			$row['gbp_place_id']     = $data['gbp']['place_id']     ?? '';
			$row['gbp_rating']       = $data['gbp']['rating']       ?? null;
			$row['gbp_review_count'] = $data['gbp']['review_count'] ?? null;
			$row['gbp_category']     = $data['gbp']['category']     ?? '';
		}

		if ( isset( $data['social'] ) ) {
			$row['social_facebook_url']  = $data['social']['facebook']  ?? '';
			$row['social_instagram_url'] = $data['social']['instagram'] ?? '';
			$row['social_linkedin_url']  = $data['social']['linkedin']  ?? '';
			$row['social_tiktok_url']    = $data['social']['tiktok']    ?? '';
			$row['social_youtube_url']   = $data['social']['youtube']   ?? '';
		}

		if ( isset( $data['pixels'] ) ) {
			$row['pixel_meta_id']     = $data['pixels']['meta_pixel_id'] ?? '';
			$row['pixel_gtm_id']      = $data['pixels']['gtm_id']        ?? '';
			$row['pixel_ga4_id']      = $data['pixels']['ga4_id']        ?? '';
			$row['pixel_tiktok_id']   = $data['pixels']['tiktok_id']     ?? '';
			$row['pixel_linkedin_id'] = $data['pixels']['linkedin_id']   ?? '';
		}

		if ( isset( $data['tech_stack'] ) ) {
			$row['tech_stack'] = wp_json_encode( $data['tech_stack'] );
		}

		if ( isset( $data['whois'] ) ) {
			$row['whois_registrar']    = $data['whois']['registrar']   ?? '';
			$row['whois_created_at']   = $data['whois']['created_at']  ?? null;
			$row['whois_expires_at']   = $data['whois']['expires_at']  ?? null;
			$row['whois_nameservers']  = $data['whois']['nameservers'] ?? '';
		}

		if ( isset( $data['legitscript'] ) ) {
			$row['legitscript_status'] = $data['legitscript']['status'] ?? '';
			$row['legitscript_raw']    = $data['legitscript']['raw']    ?? '';
		}

		if ( isset( $data['licensure'] ) ) {
			$row['licensure_signals'] = wp_json_encode( $data['licensure'] );
		}

		return $row;
	}

	// -------------------------------------------------------------------------
	// Flag computation
	// -------------------------------------------------------------------------

	/**
	 * Derive human-readable discovery flags from the complete data set.
	 * These appear in the admin and the Phase 1 review UI.
	 */
	private function compute_flags( $data ) {
		$flags = [];

		// New domain.
		if ( ! empty( $data['whois']['flag_new_domain'] ) ) {
			$age  = $data['whois']['domain_age_days'] ?? 0;
			$flags[] = [
				'id'       => 'new_domain',
				'severity' => 'high',
				'label'    => "Domain is only {$age} days old — red flag for addiction treatment advertising eligibility.",
			];
		}

		// LegitScript not certified.
		$ls_status = $data['legitscript']['status'] ?? 'unknown';
		if ( in_array( $ls_status, [ 'not_found', 'not_recommended', 'rogue' ], true ) ) {
			$flags[] = [
				'id'       => 'legitscript_' . $ls_status,
				'severity' => 'high',
				'label'    => 'LegitScript status: ' . ucwords( str_replace( '_', ' ', $ls_status ) ) . '. Google and Meta require LegitScript certification for addiction treatment ads.',
			];
		}

		// No licensure signals on site.
		if ( ! empty( $data['licensure']['missing_flag'] ) ) {
			$flags[] = [
				'id'       => 'no_licensure_signals',
				'severity' => 'medium',
				'label'    => 'No SAMHSA, JCAHO, CARF, or state license references found on the website.',
			];
		}

		// No GBP match.
		if ( empty( $data['gbp']['place_id'] ) ) {
			$flags[] = [
				'id'       => 'no_gbp',
				'severity' => 'low',
				'label'    => 'No Google Business Profile found. Missing local credibility signal.',
			];
		}

		// Tracking pixels on a health site — note for compliance (not a blocker).
		if ( ! empty( $data['pixels']['meta_pixel_id'] ) ) {
			$flags[] = [
				'id'       => 'meta_pixel_present',
				'severity' => 'medium',
				'label'    => 'Meta Pixel detected. Verify it is not firing on intake/contact forms — HIPAA risk.',
			];
		}

		if ( ! empty( $data['pixels']['ga4_id'] ) || ! empty( $data['pixels']['gtm_id'] ) ) {
			$flags[] = [
				'id'       => 'google_tag_present',
				'severity' => 'low',
				'label'    => 'Google Tag/GA4 detected. Verify it is not capturing PHI on thank-you or intake pages.',
			];
		}

		return $flags;
	}
}
