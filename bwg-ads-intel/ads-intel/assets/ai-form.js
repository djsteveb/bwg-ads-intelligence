/**
 * BWG Ads Intelligence — Front-End Step Machine
 *
 * Steps (front-end rendering):
 *   0 — URL + email entry form
 *   1 — Discovery running (progress bar polling)
 *   2 — Discovery review (confirm/edit discovered data)
 *   3 — Ad surface loading (Meta Ad Library lookup running, polling for completion)
 *   4 — Ad gallery (confirm/flag each ad, add more accounts)
 *   5 — Access funnel (platform cards, request access, CSV upload)
 *   6 — Report stub (M9)
 *
 * DB step_completed ↔ render step mapping (resume):
 *   DB 0  → render 1 (discovery polling)
 *   DB 1  → render 2 (discovery review)
 *   DB 2  → render 4 (gallery)
 *   DB 3+ → render 5 (access funnel)
 */
( function ( $ ) {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* Config                                                               */
	/* ------------------------------------------------------------------ */
	var STORAGE_KEY        = 'bwgai_session';
	var POLL_INTERVAL      = 3000;  // discovery poll ms
	var ADS_POLL_INTERVAL  = 5000;  // ad-surface poll ms
	var ADS_POLL_MAX       = 120;   // 10 minutes before giving up

	/* ------------------------------------------------------------------ */
	/* State                                                                */
	/* ------------------------------------------------------------------ */
	var state = {
		sessionId             : null,
		accessCode            : '',
		resumeToken           : '',
		step                  : 0,
		websiteUrl            : '',
		discovered            : null,
		flags                 : [],
		ads                   : [],
		adsConfirmed          : {},   // { db_ad_id: true (confirmed) | false (flagged) }
		accessStatus          : {},   // { meta: 'pending'|'granted'|'export', google: ... }
		pollTimer             : null,
		pollAttempts          : 0,
		adsSurfacePollTimer   : null,
		adsSurfacePollAttempts: 0,
	};

	/* ------------------------------------------------------------------ */
	/* DOM refs                                                             */
	/* ------------------------------------------------------------------ */
	var $app    = $( '#bwg-ai-app' );
	var $steps  = $( '#bwg-ai-steps' );
	var $notice = $( '#bwg-ai-notice' );

	/* ------------------------------------------------------------------ */
	/* Boot                                                                 */
	/* ------------------------------------------------------------------ */
	function init() {
		var resumeToken = ( window.bwgAI && window.bwgAI.resumeToken ) || '';
		var accessCode  = ( window.bwgAI && window.bwgAI.accessCode  ) || '';
		var saved       = loadState();

		if ( resumeToken || accessCode ) {
			doResume( resumeToken, accessCode );
			return;
		}

		if ( saved && saved.sessionId ) {
			restoreFromLocal( saved );
			return;
		}

		renderStep0();
	}

	/* ------------------------------------------------------------------ */
	/* Step 0 — URL + email entry                                          */
	/* ------------------------------------------------------------------ */
	function renderStep0() {
		state.step = 0;
		var captchaSiteKey = ( window.bwgAI && window.bwgAI.captchaSiteKey ) || '';
		var captchaHtml    = captchaSiteKey
			? '<div class="cf-turnstile" data-sitekey="' + esc( captchaSiteKey ) + '" data-theme="light"></div>'
			: '';

		$steps.html(
			header( 'Phase 1 of 5', 'Free Ads Intelligence Audit', 'Enter your website URL to discover your complete advertising footprint — across every major platform.', 0 ) +
			'<div class="bwg-ai-body">' +
				'<form id="bwg-ai-form-0">' +
					field( 'website_url', 'Website URL', 'url', 'https://yourtreatmentcenter.com', 'We\'ll detect your ad accounts, pixels, and business profile automatically.' ) +
					field( 'email', 'Your Email', 'email', 'you@example.com', 'We\'ll send you your access code and a progress update when we find your ads.' ) +
					captchaHtml +
					'<div class="bwg-ai-btn-row">' +
						'<button type="submit" class="bwg-ai-btn bwg-ai-btn-primary" id="bwg-ai-submit-0">Start Free Audit</button>' +
					'</div>' +
					'<p style="font-size:12px;color:var(--ink3);margin-top:16px;">Already started? ' +
						'<a href="#" id="bwg-ai-show-resume" style="color:var(--teal);">Enter your access code</a>' +
					'</p>' +
				'</form>' +
				'<div id="bwg-ai-resume-form" style="display:none;margin-top:16px;">' +
					field( 'access_code_input', 'Access Code', 'text', 'e.g. AB3XY7', 'Enter the 6-character code from your confirmation email.' ) +
					( captchaSiteKey ? '<div id="bwg-ai-resume-turnstile" class="cf-turnstile" data-sitekey="' + esc( captchaSiteKey ) + '" data-theme="light" style="margin-top:12px;"></div>' : '' ) +
					'<div class="bwg-ai-btn-row">' +
						'<button class="bwg-ai-btn bwg-ai-btn-outline" id="bwg-ai-resume-btn">Resume Session</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		if ( window.turnstile ) {
			window.turnstile.render( '.cf-turnstile' );
		}

		$( '#bwg-ai-show-resume' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( '#bwg-ai-resume-form' ).slideToggle( 180 );
		} );

		$( '#bwg-ai-resume-btn' ).on( 'click', function () {
			var code = $( '#access_code_input' ).val().trim().toUpperCase();
			if ( ! code ) { return; }
			var resumeCaptchaToken = '';
			if ( window.turnstile ) {
				resumeCaptchaToken = window.turnstile.getResponse( '#bwg-ai-resume-turnstile' ) || '';
			}
			doResume( '', code, resumeCaptchaToken );
		} );

		$( '#bwg-ai-form-0' ).on( 'submit', function ( e ) {
			e.preventDefault();
			submitStart();
		} );
	}

	function submitStart() {
		clearNotice();
		var url   = $( '#website_url' ).val().trim();
		var email = $( '#email' ).val().trim();

		if ( ! url || ! isValidUrl( url ) ) {
			return fieldError( 'website_url', 'Please enter a valid website URL (https://...).' );
		}
		if ( ! email || ! isValidEmail( email ) ) {
			return fieldError( 'email', 'Please enter a valid email address.' );
		}

		var captchaToken = '';
		if ( window.turnstile ) {
			captchaToken = window.turnstile.getResponse();
		}

		setLoading( '#bwg-ai-submit-0', true );

		apiPost( '/start', { website_url: url, email: email, captcha_token: captchaToken } )
			.done( function ( res ) {
				state.sessionId   = res.session_id;
				state.accessCode  = res.access_code;
				state.resumeToken = res.resume_token;
				state.websiteUrl  = url;
				saveState();
				renderStep1();
			} )
			.fail( function ( xhr ) {
				setLoading( '#bwg-ai-submit-0', false );
				if ( xhr.status === 429 ) {
					var retry = ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.retry_after ) || 60;
					showCooldown( retry );
				} else {
					showNotice( apiError( xhr, 'Could not start the audit. Please try again.' ), 'error' );
				}
				if ( window.turnstile ) { window.turnstile.reset(); }
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Step 1 — Discovery running (progress polling)                       */
	/* ------------------------------------------------------------------ */
	function renderStep1() {
		state.step = 1;
		$steps.html(
			header( 'Phase 1 of 5', 'Discovering Your Ad Footprint', 'Scanning your website, social profiles, pixels, WHOIS records, and LegitScript status. This takes about 30–60 seconds.', 1 ) +
			'<div class="bwg-ai-body">' +
				'<div class="bwg-ai-access-code-box">' +
					'<div>' +
						'<div class="bwg-ai-access-code-label">Your Access Code</div>' +
						'<div class="bwg-ai-access-code-value">' + esc( state.accessCode ) + '</div>' +
					'</div>' +
					'<button class="bwg-ai-btn bwg-ai-btn-outline bwg-ai-copy-btn" id="bwg-ai-copy-code">Copy Code</button>' +
				'</div>' +
				'<p style="font-size:13px;color:var(--ink3);margin-bottom:24px;">We emailed this to <strong>' + esc( getUserEmail() ) + '</strong>. Save it to resume anytime.</p>' +
				'<div class="bwg-ai-progress-wrap">' +
					'<div class="bwg-ai-progress-header">' +
						'<span class="bwg-ai-progress-label">Scanning...</span>' +
						'<span class="bwg-ai-progress-pct" id="bwg-ai-pct">0%</span>' +
					'</div>' +
					'<div class="bwg-ai-progress-bar"><div class="bwg-ai-progress-fill" id="bwg-ai-fill"></div></div>' +
					'<div class="bwg-ai-progress-task" id="bwg-ai-task">Starting discovery...</div>' +
				'</div>' +
			'</div>'
		);

		$( '#bwg-ai-copy-code' ).on( 'click', function () {
			copyToClipboard( state.accessCode );
			$( this ).text( 'Copied!' );
			var $btn = $( this );
			setTimeout( function () { $btn.text( 'Copy Code' ); }, 2000 );
		} );

		startPolling();
	}

	function startPolling() {
		state.pollAttempts = 0;
		pollDiscovery();
	}

	function pollDiscovery() {
		if ( ! state.sessionId ) { return; }

		apiGet( '/discovery-status/' + state.sessionId )
			.done( function ( res ) {
				var pct  = ( res.discovered && res.discovered.confidence && res.discovered.confidence.progress_pct ) || 0;
				var task = ( res.discovered && res.discovered.confidence && res.discovered.confidence.current_task ) || '';

				updateProgress( pct, task );

				if ( pct >= 100 || res.step >= 1 ) {
					clearPollTimer();
					state.discovered = res.discovered;
					state.flags      = res.flags || [];
					saveState();
					renderStep2();
					return;
				}

				state.pollAttempts++;
				if ( state.pollAttempts > 60 ) {
					clearPollTimer();
					showNotice( 'Discovery is taking longer than expected. Please refresh or use your access code to resume.', 'info' );
					return;
				}

				state.pollTimer = setTimeout( pollDiscovery, POLL_INTERVAL );
			} )
			.fail( function () {
				state.pollTimer = setTimeout( pollDiscovery, POLL_INTERVAL * 2 );
			} );
	}

	function updateProgress( pct, task ) {
		var pctInt = Math.min( 100, Math.round( pct ) );
		$( '#bwg-ai-fill' ).css( 'width', pctInt + '%' );
		$( '#bwg-ai-pct' ).text( pctInt + '%' );

		var labels = {
			starting    : 'Starting…',
			fetch       : 'Fetching website…',
			nap         : 'Extracting business details…',
			gbp         : 'Matching Google Business Profile…',
			social      : 'Detecting social profiles…',
			pixels      : 'Scanning for tracking pixels…',
			tech_stack  : 'Fingerprinting tech stack…',
			whois       : 'Looking up WHOIS / domain info…',
			legitscript : 'Checking LegitScript status…',
			licensure   : 'Checking licensure signals…',
			complete    : 'Discovery complete!',
			in_progress : 'Analysing…',
		};
		var label = labels[ task ] || ( task ? task : 'Scanning…' );
		$( '#bwg-ai-task' ).text( label );
		$( '.bwg-ai-progress-label' ).text( pctInt < 100 ? 'Scanning…' : 'Complete' );
	}

	/* ------------------------------------------------------------------ */
	/* Step 2 — Discovery review                                           */
	/* ------------------------------------------------------------------ */
	function renderStep2() {
		state.step = 2;
		var d = state.discovered || {};

		var businessHtml = cardHtml( 'Business Information', [
			{ key: 'Name',    val: d.business_name,    editable: 'business_name' },
			{ key: 'Address', val: d.business_address, editable: 'business_address' },
			{ key: 'Phone',   val: d.business_phone,   editable: 'business_phone' },
		] );

		var gbp     = d.gbp || {};
		var gbpHtml = cardHtml( 'Google Business Profile', [
			{ key: 'Status',   val: gbp.place_id ? 'Found' : 'Not found' },
			{ key: 'Rating',   val: gbp.rating ? gbp.rating + ' ★ (' + gbp.review_count + ' reviews)' : '' },
			{ key: 'Category', val: gbp.category },
		] );

		var social      = d.social || {};
		var socialChips = chipsHtml( [
			social.facebook  ? { label: 'Facebook',  cls: 'social' } : null,
			social.instagram ? { label: 'Instagram', cls: 'social' } : null,
			social.linkedin  ? { label: 'LinkedIn',  cls: 'social' } : null,
			social.tiktok    ? { label: 'TikTok',    cls: 'social' } : null,
			social.youtube   ? { label: 'YouTube',   cls: 'social' } : null,
		] );
		var socialHtml = '<div class="bwg-ai-card">' +
			'<div class="bwg-ai-card-label">Social Profiles</div>' +
			( socialChips || '<span style="font-size:13px;color:var(--ink3)">None detected</span>' ) +
			'</div>';

		var pixels     = d.pixels || {};
		var pixelChips = chipsHtml( [
			pixels.meta     ? { label: 'Meta Pixel '   + pixels.meta,     cls: 'pixel' } : null,
			pixels.gtm      ? { label: 'GTM '          + pixels.gtm,      cls: 'pixel' } : null,
			pixels.ga4      ? { label: 'GA4 '          + pixels.ga4,      cls: 'pixel' } : null,
			pixels.tiktok   ? { label: 'TikTok Pixel ' + pixels.tiktok,   cls: 'pixel' } : null,
			pixels.linkedin ? { label: 'LinkedIn '     + pixels.linkedin,  cls: 'pixel' } : null,
		] );
		var pixelHtml = '<div class="bwg-ai-card">' +
			'<div class="bwg-ai-card-label">Tracking Pixels &amp; Tags</div>' +
			( pixelChips || '<span style="font-size:13px;color:var(--ink3)">None detected</span>' ) +
			'</div>';

		var techStack = d.tech_stack || [];
		var techChips = chipsHtml( techStack.map( function ( t ) { return { label: t, cls: 'tech' }; } ) );
		var techHtml  = '<div class="bwg-ai-card">' +
			'<div class="bwg-ai-card-label">Tech Stack</div>' +
			( techChips || '<span style="font-size:13px;color:var(--ink3)">None detected</span>' ) +
			'</div>';

		var whois    = d.whois || {};
		var whoisHtml = cardHtml( 'Domain Intel', [
			{ key: 'Registrar',   val: whois.registrar },
			{ key: 'Registered',  val: whois.created_at },
			{ key: 'Expires',     val: whois.expires_at },
			{ key: 'Nameservers', val: whois.nameservers },
		] );

		var lsStatus = d.legitscript || 'unknown';
		var lsColor  = { certified: 'var(--green)', not_found: 'var(--coral)', not_recommended: 'var(--coral)', rogue: 'var(--coral)', caution: 'var(--amber)', unknown: 'var(--ink3)', listed: 'var(--teal)' }[ lsStatus ] || 'var(--ink3)';
		var lsHtml   = '<div class="bwg-ai-card">' +
			'<div class="bwg-ai-card-label">LegitScript Status</div>' +
			'<span style="font-size:14px;font-weight:600;color:' + lsColor + ';">' + esc( lsStatus.replace( /_/g, ' ' ) ) + '</span>' +
			'</div>';

		var flagsHtml = '';
		if ( state.flags && state.flags.length ) {
			flagsHtml = '<div class="bwg-ai-flags">' +
				'<div class="bwg-ai-flags-title">Discovery Flags</div>';
			state.flags.forEach( function ( f ) {
				flagsHtml += '<div class="bwg-ai-flag ' + esc( f.severity ) + '">' +
					'<div class="bwg-ai-flag-dot"></div>' +
					'<div>' + esc( f.label ) + '</div>' +
					'</div>';
			} );
			flagsHtml += '</div>';
		}

		$steps.html(
			header( 'Phase 1 of 5', 'Review What We Found', 'Check the details below. You can correct any information before we move to the next step.', 2 ) +
			'<div class="bwg-ai-body">' +
				flagsHtml +
				'<div class="bwg-ai-cards">' +
					businessHtml + gbpHtml + socialHtml + pixelHtml + techHtml + whoisHtml + lsHtml +
				'</div>' +
				'<div class="bwg-ai-btn-row">' +
					'<button class="bwg-ai-btn bwg-ai-btn-primary" id="bwg-ai-confirm-discovery">Looks Good — Continue</button>' +
					'<span style="font-size:12px;color:var(--ink3);">Changes are saved automatically.</span>' +
				'</div>' +
			'</div>'
		);

		$( '#bwg-ai-confirm-discovery' ).on( 'click', function () {
			submitConfirmDiscovery();
		} );
	}

	function submitConfirmDiscovery() {
		clearNotice();
		setLoading( '#bwg-ai-confirm-discovery', true );

		var payload = {
			session_id       : state.sessionId,
			business_name    : $( '#edit-business_name' ).length    ? $( '#edit-business_name' ).val()    : undefined,
			business_address : $( '#edit-business_address' ).length ? $( '#edit-business_address' ).val() : undefined,
			business_phone   : $( '#edit-business_phone' ).length   ? $( '#edit-business_phone' ).val()   : undefined,
		};

		Object.keys( payload ).forEach( function ( k ) {
			if ( payload[ k ] === undefined ) { delete payload[ k ]; }
		} );

		apiPost( '/confirm-discovery', payload )
			.done( function () {
				saveState();
				renderStep3();
			} )
			.fail( function ( xhr ) {
				setLoading( '#bwg-ai-confirm-discovery', false );
				showNotice( apiError( xhr, 'Could not save. Please try again.' ), 'error' );
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Step 3 — Ad surface loading (Meta/Google lookups running, polls for completion)*/
	/* ------------------------------------------------------------------ */
	function renderStep3() {
		state.step = 3;
		$steps.html(
			header( 'Phase 2 of 5', 'Pulling Your Ad Library', 'We\'re fetching your active and historical ads from Meta Ad Library. This usually takes 1–3 minutes.', 3 ) +
			'<div class="bwg-ai-body">' +
				'<div class="bwg-ai-phase-next">' +
					'<div class="bwg-ai-phase-icon">📊</div>' +
					'<h3>Ad Surface Scan Running</h3>' +
					'<p>We\'re pulling your ads from Meta Ad Library. This page will update automatically when results are ready.</p>' +
					'<div class="bwg-ai-progress-wrap" style="max-width:320px;margin:0 auto 24px;">' +
						'<div class="bwg-ai-progress-bar"><div class="bwg-ai-progress-fill bwg-ai-progress-indeterminate" id="bwg-ai-ads-fill"></div></div>' +
						'<div class="bwg-ai-progress-task" id="bwg-ai-ads-task" style="text-align:center;margin-top:8px;">Scanning…</div>' +
					'</div>' +
					( window.bwgAI && window.bwgAI.scheduleUrl
						? '<a href="' + esc( window.bwgAI.scheduleUrl ) + '" class="bwg-ai-btn bwg-ai-btn-gold" target="_blank" rel="noopener">Book a Discovery Call</a>'
						: ''
					) +
				'</div>' +
				'<hr class="bwg-ai-divider">' +
				'<div class="bwg-ai-access-code-box" style="margin-bottom:0;">' +
					'<div>' +
						'<div class="bwg-ai-access-code-label">Resume Code</div>' +
						'<div class="bwg-ai-access-code-value">' + esc( state.accessCode ) + '</div>' +
					'</div>' +
					'<button class="bwg-ai-btn bwg-ai-btn-outline bwg-ai-copy-btn" id="bwg-ai-copy-code-3">Copy</button>' +
				'</div>' +
			'</div>'
		);

		$( '#bwg-ai-copy-code-3' ).on( 'click', function () {
			copyToClipboard( state.accessCode );
			$( this ).text( 'Copied!' );
			var $btn = $( this );
			setTimeout( function () { $btn.text( 'Copy' ); }, 2000 );
		} );

		// Poll the ad-surface endpoint so we can auto-advance when ads arrive.
		startAdSurfacePolling();
	}

	function startAdSurfacePolling() {
		state.adsSurfacePollAttempts = 0;
		clearAdSurfacePoll();
		state.adsSurfacePollTimer = setTimeout( pollAdSurface, ADS_POLL_INTERVAL );
	}

	function pollAdSurface() {
		if ( ! state.sessionId ) { return; }

		apiGet( '/ad-surface-status/' + state.sessionId )
			.done( function ( res ) {
				var dbStep   = parseInt( res.step, 10 );
				var adsFound = parseInt( res.ads_found, 10 );
				state.metaConfigured   = !! res.meta_configured;
				state.googleConfigured = !! res.google_configured;

				if ( dbStep >= 2 || adsFound > 0 ) {
					clearAdSurfacePoll();
					$( '#bwg-ai-ads-task' ).text( 'Found ' + adsFound + ' ad' + ( adsFound === 1 ? '' : 's' ) + '!' );
					setTimeout( renderStep4, 800 );
					return;
				}

				// No automated lookup is possible for either platform without
				// a token/API key configured — skip straight to the
				// manual-entry flow instead of polling for up to 10 minutes
				// for nothing.
				if ( ! state.metaConfigured && ! state.googleConfigured ) {
					clearAdSurfacePoll();
					setTimeout( renderStep4, 400 );
					return;
				}

				state.adsSurfacePollAttempts++;
				if ( state.adsSurfacePollAttempts >= ADS_POLL_MAX ) {
					clearAdSurfacePoll();
					$( '#bwg-ai-ads-task' ).text( 'Still working…' );
					showNotice( 'This is taking longer than usual. We\'ll email you when results are ready. Use your access code to return.', 'info' );
					return;
				}

				state.adsSurfacePollTimer = setTimeout( pollAdSurface, ADS_POLL_INTERVAL );
			} )
			.fail( function () {
				state.adsSurfacePollTimer = setTimeout( pollAdSurface, ADS_POLL_INTERVAL * 2 );
			} );
	}

	function clearAdSurfacePoll() {
		if ( state.adsSurfacePollTimer ) {
			clearTimeout( state.adsSurfacePollTimer );
			state.adsSurfacePollTimer = null;
		}
	}

	/* Manual ad entry (no Meta token configured) --------------------- */

	function manualAdsFormHtml( suffix ) {
		var id = function ( base ) { return 'bwg-ai-' + base + '-' + suffix; };
		// Default the platform picker to whichever platform has no automated
		// lookup configured, when only one needs it.
		var defaultPlatform = state.metaConfigured === false ? 'meta' : ( state.googleConfigured === false ? 'google' : 'meta' );
		return '<div class="bwg-ai-manual-ads-form" id="' + id( 'manual-form' ) + '">' +
			'<p style="font-size:14px;color:var(--ink2);margin-bottom:12px;font-weight:500;">Paste the URL of each ad, plus the ad copy if you have it.</p>' +
			'<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Platform</label>' +
			'<select class="bwg-ai-manual-platform" id="' + id( 'manual-platform' ) + '" style="margin-bottom:12px;">' +
				'<option value="meta"' + ( defaultPlatform === 'meta' ? ' selected' : '' ) + '>Meta (Facebook/Instagram) — Ad Library</option>' +
				'<option value="google"' + ( defaultPlatform === 'google' ? ' selected' : '' ) + '>Google — Ads Transparency Center</option>' +
			'</select>' +
			'<div class="bwg-ai-manual-rows" id="' + id( 'manual-rows' ) + '">' +
				manualRowHtml( suffix, 0 ) +
			'</div>' +
			'<button class="bwg-ai-btn bwg-ai-btn-outline bwg-ai-btn-sm" id="' + id( 'manual-add' ) + '" style="margin-top:8px;">+ Add another ad</button>' +
			'<div class="bwg-ai-btn-row" style="margin-top:16px;">' +
				'<button class="bwg-ai-btn bwg-ai-btn-primary" id="' + id( 'manual-submit' ) + '">Save These Ads</button>' +
			'</div>' +
		'</div>';
	}

	function manualRowHtml( suffix, idx ) {
		var rowId = 'bwg-ai-manual-row-' + suffix + '-' + idx;
		return '<div class="bwg-ai-manual-row" id="' + rowId + '" style="margin-bottom:12px;">' +
			'<input type="url" class="bwg-ai-manual-url" id="bwg-ai-manual-url-' + suffix + '-' + idx + '" placeholder="https://www.facebook.com/ads/library/?id=…" style="width:100%;margin-bottom:6px;">' +
			'<textarea class="bwg-ai-manual-copy" id="bwg-ai-manual-copy-' + suffix + '-' + idx + '" placeholder="Ad copy (optional)" rows="2" style="width:100%;"></textarea>' +
		'</div>';
	}

	function bindManualAdsForm( suffix ) {
		var id    = function ( base ) { return '#bwg-ai-' + base + '-' + suffix; };
		var count = [ 1 ];

		$( id( 'manual-add' ) ).on( 'click', function () {
			$( id( 'manual-rows' ) ).append( manualRowHtml( suffix, count[0] ) );
			count[0]++;
		} );

		$( id( 'manual-submit' ) ).on( 'click', function () {
			var platform = $( id( 'manual-platform' ) ).val() || 'meta';
			var entries  = [];
			for ( var i = 0; i < count[0]; i++ ) {
				var url  = $( '#bwg-ai-manual-url-' + suffix + '-' + i ).val();
				var copy = $( '#bwg-ai-manual-copy-' + suffix + '-' + i ).val();
				url = url ? url.trim() : '';
				if ( url ) { entries.push( { ad_snapshot_url: url, ad_copy: copy ? copy.trim() : '' } ); }
			}

			if ( ! entries.length ) {
				showNotice( 'Please paste at least one ad URL.', 'info' );
				return;
			}

			setLoading( id( 'manual-submit' ), true );

			apiPost( '/manual-ads', { session_id: state.sessionId, platform: platform, ads: entries } )
				.done( function ( res ) {
					showNotice( ( res.saved || entries.length ) + ' ad(s) saved.', 'success' );
					setLoading( id( 'manual-submit' ), false );
					renderStep4();
				} )
				.fail( function ( xhr ) {
					setLoading( id( 'manual-submit' ), false );
					showNotice( apiError( xhr, 'Could not save ads. Please try again.' ), 'error' );
				} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Step 4 — Ad gallery: confirm / flag / add more accounts             */
	/* ------------------------------------------------------------------ */
	function renderStep4() {
		clearAdSurfacePoll();
		state.step = 4;

		$steps.html(
			header( 'Phase 2 of 5', 'Your Ads — Confirm or Flag', 'Review every ad we found. Confirm the ones that belong to your account and flag any you don\'t recognize.', 4 ) +
			'<div class="bwg-ai-body">' +
				'<div id="bwg-ai-gallery-wrap">' +
					'<div style="text-align:center;padding:48px 0;">' +
						'<div class="bwg-ai-spinner dark"></div>' +
						'<p style="font-size:13px;color:var(--ink3);margin-top:14px;">Loading your ads…</p>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		apiGet( '/ads/' + state.sessionId )
			.done( function ( res ) {
				state.ads = res.ads || [];
				saveState();
				renderGallery();
			} )
			.fail( function ( xhr ) {
				$( '#bwg-ai-gallery-wrap' ).html(
					'<p style="color:var(--coral);font-size:14px;">Could not load ads. ' + apiError( xhr, 'Please refresh and try again.' ) + '</p>'
				);
			} );
	}

	function renderGallery() {
		var ads = state.ads;

		if ( ! ads.length ) {
			var neitherConfigured = state.metaConfigured === false && state.googleConfigured === false;

			if ( neitherConfigured ) {
				$( '#bwg-ai-gallery-wrap' ).html(
					'<div style="text-align:center;padding:32px 0 24px;">' +
						'<p style="font-size:15px;color:var(--ink2);font-weight:500;margin-bottom:8px;">Automatic ad lookup isn\'t available yet.</p>' +
						'<p style="font-size:13px;color:var(--ink3);max-width:420px;margin:0 auto;">Paste in links to your ads from the <a href="https://www.facebook.com/ads/library/" target="_blank" rel="noopener">Meta Ad Library</a> or the <a href="https://adstransparency.google.com/" target="_blank" rel="noopener">Google Ads Transparency Center</a> and we\'ll run compliance checks on them.</p>' +
					'</div>' +
					manualAdsFormHtml( 'main' )
				);
				bindManualAdsForm( 'main' );
				return;
			}

			$( '#bwg-ai-gallery-wrap' ).html(
				'<div style="text-align:center;padding:32px 0 24px;">' +
					'<p style="font-size:15px;color:var(--ink2);font-weight:500;margin-bottom:8px;">No ads found yet.</p>' +
					'<p style="font-size:13px;color:var(--ink3);max-width:400px;margin:0 auto;">This can happen if your ad accounts aren\'t publicly listed. Add your ad account details below so we can search more specifically' + ( state.metaConfigured === false || state.googleConfigured === false ? ', or paste in ads by hand for the platform we couldn\'t search automatically' : '' ) + '.</p>' +
				'</div>' +
				addAccountsFormHtml( 'main' ) +
				( ( state.metaConfigured === false || state.googleConfigured === false )
					? '<div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border,#e5e2da);">' + manualAdsFormHtml( 'main2' ) + '</div>'
					: '' )
			);
			bindAddAccountsForm( 'main' );
			if ( state.metaConfigured === false || state.googleConfigured === false ) {
				bindManualAdsForm( 'main2' );
			}
			return;
		}

		// Compliance summary banner.
		var totalFlags = 0;
		ads.forEach( function ( ad ) { totalFlags += ( ad.compliance_flags || [] ).length; } );
		var flagBanner = totalFlags
			? '<div class="bwg-ai-compliance-banner high"><strong>' + totalFlags + ' compliance flag' + ( totalFlags === 1 ? '' : 's' ) + '</strong> found across ' + ads.length + ' ad' + ( ads.length === 1 ? '' : 's' ) + '. Review the highlighted issues below before your next campaign spend.</div>'
			: '<div class="bwg-ai-compliance-banner ok">No major compliance issues detected in your ad copy.</div>';

		var cardsHtml = ads.map( function ( ad ) { return adCardHtml( ad ); } ).join( '' );

		$( '#bwg-ai-gallery-wrap' ).html(
			flagBanner +
			'<div class="bwg-ai-gallery">' + cardsHtml + '</div>' +
			'<div class="bwg-ai-gallery-footer">' +
				'<div class="bwg-ai-btn-row">' +
					'<button class="bwg-ai-btn bwg-ai-btn-primary" id="bwg-ai-confirm-ads">Save Selections &amp; Continue</button>' +
					'<span class="bwg-ai-gallery-count">' + ads.length + ' ad' + ( ads.length === 1 ? '' : 's' ) + ' found</span>' +
				'</div>' +
			'</div>' +
			'<div class="bwg-ai-add-accounts-wrap">' +
				'<div class="bwg-ai-btn-row">' +
					'<button class="bwg-ai-btn bwg-ai-btn-outline" id="bwg-ai-toggle-add-accounts">+ Add More Ad Accounts</button>' +
					( ( state.metaConfigured === false || state.googleConfigured === false )
						? '<button class="bwg-ai-btn bwg-ai-btn-outline" id="bwg-ai-toggle-manual-ads">+ Add Ads Manually</button>'
						: '' ) +
				'</div>' +
				'<div id="bwg-ai-add-accounts-panel" style="display:none;margin-top:16px;">' + addAccountsFormHtml( 'panel' ) + '</div>' +
				( ( state.metaConfigured === false || state.googleConfigured === false )
					? '<div id="bwg-ai-manual-ads-panel" style="display:none;margin-top:16px;">' + manualAdsFormHtml( 'panel' ) + '</div>'
					: '' ) +
			'</div>'
		);

		// Restore any saved confirm/flag state.
		Object.keys( state.adsConfirmed ).forEach( function ( adId ) {
			applyCardState( adId, state.adsConfirmed[ adId ] );
		} );

		// Card action buttons (direct binding — elements exist now).
		$( '#bwg-ai-gallery-wrap' ).on( 'click', '.bwg-ai-btn-confirm-ad', function () {
			var adId = $( this ).closest( '.bwg-ai-ad-card' ).data( 'ad-id' );
			toggleAdState( String( adId ), true );
		} );
		$( '#bwg-ai-gallery-wrap' ).on( 'click', '.bwg-ai-btn-flag-ad', function () {
			var adId = $( this ).closest( '.bwg-ai-ad-card' ).data( 'ad-id' );
			toggleAdState( String( adId ), false );
		} );

		$( '#bwg-ai-confirm-ads' ).on( 'click', submitConfirmAds );

		$( '#bwg-ai-toggle-add-accounts' ).on( 'click', function () {
			var $panel = $( '#bwg-ai-add-accounts-panel' );
			$panel.slideToggle( 200 );
			$( this ).text( $panel.is( ':hidden' ) ? '+ Add More Ad Accounts' : '− Hide' );
		} );
		bindAddAccountsForm( 'panel' );

		if ( state.metaConfigured === false || state.googleConfigured === false ) {
			$( '#bwg-ai-toggle-manual-ads' ).on( 'click', function () {
				var $panel = $( '#bwg-ai-manual-ads-panel' );
				$panel.slideToggle( 200 );
				$( this ).text( $panel.is( ':hidden' ) ? '+ Add Ads Manually' : '− Hide' );
			} );
			bindManualAdsForm( 'panel' );
		}
	}

	/* Ad card HTML --------------------------------------------------- */

	function adCardHtml( ad ) {
		var adId      = String( ad.id );
		var confirmed = state.adsConfirmed[ adId ];
		var cardExtra = confirmed === true ? ' confirmed' : ( confirmed === false ? ' flagged' : '' );

		var flags    = ad.compliance_flags || [];
		var topSev   = flags.length ? ( flags[0].severity || 'low' ) : '';
		var flagBadge = flags.length
			? '<span class="bwg-ai-flag-badge ' + esc( topSev ) + '">' + flags.length + ' flag' + ( flags.length === 1 ? '' : 's' ) + '</span>'
			: '';

		var excerpt = ad.ad_copy
			? esc( ad.ad_copy.substring( 0, 220 ) ) + ( ad.ad_copy.length > 220 ? '…' : '' )
			: '';

		var imgHtml;
		if ( ad.ad_image_url || ad.screenshot_url ) {
			imgHtml = '<div class="bwg-ai-ad-image">' +
				'<img src="' + esc( ad.ad_image_url || ad.screenshot_url ) + '" alt="" loading="lazy"' +
				' onerror="this.parentNode.innerHTML=\'<div class=\\\"bwg-ai-ad-image-ph\\\">No image</div>\';">' +
				'</div>';
		} else if ( ad.ad_snapshot_url ) {
			// Meta hosts its own rendered snapshot of the ad — link out to it
			// rather than capturing our own screenshot.
			imgHtml = '<div class="bwg-ai-ad-image bwg-ai-ad-snapshot-link">' +
				'<a href="' + esc( ad.ad_snapshot_url ) + '" target="_blank" rel="noopener">View Ad Snapshot ↗</a>' +
				'</div>';
		} else {
			imgHtml = '<div class="bwg-ai-ad-image"><div class="bwg-ai-ad-image-ph">No image</div></div>';
		}

		var metaParts = [];
		if ( ad.run_dates )   { metaParts.push( esc( ad.run_dates ) ); }
		if ( ad.spend_range ) { metaParts.push( esc( ad.spend_range ) ); }
		var metaLine = metaParts.length
			? '<div class="bwg-ai-ad-meta">' + metaParts.join( ' &nbsp;·&nbsp; ' ) + '</div>'
			: '';

		var visionBadge = ad.vision_analyzed
			? '<span class="bwg-ai-vision-badge" title="Ad creative was reviewed by AI vision analysis">👁 AI-reviewed</span>'
			: '';

		return '<div class="bwg-ai-ad-card' + cardExtra + '" data-ad-id="' + esc( adId ) + '">' +
			'<div class="bwg-ai-ad-card-top">' +
				'<span class="bwg-ai-platform-badge ' + esc( ad.platform || 'meta' ) + '">' + esc( ad.platform || 'meta' ) + '</span>' +
				flagBadge +
				visionBadge +
				'<span class="bwg-ai-card-state-label confirmed-label">✓ Confirmed</span>' +
				'<span class="bwg-ai-card-state-label flagged-label">✗ Not mine</span>' +
			'</div>' +
			imgHtml +
			( excerpt ? '<div class="bwg-ai-ad-copy">' + excerpt + '</div>' : '' ) +
			metaLine +
			( flags.length ? complianceMiniFlags( flags ) : '' ) +
			'<div class="bwg-ai-ad-actions">' +
				'<button class="bwg-ai-btn bwg-ai-btn-confirm-ad">✓ This is ours</button>' +
				'<button class="bwg-ai-btn bwg-ai-btn-flag-ad">✗ Don\'t recognize</button>' +
			'</div>' +
		'</div>';
	}

	function complianceMiniFlags( flags ) {
		var shown = flags.slice( 0, 3 );
		var html  = '<div class="bwg-ai-ad-flags">';
		shown.forEach( function ( f ) {
			html += '<div class="bwg-ai-ad-flag-row ' + esc( f.severity ) + '">' +
				'<span class="bwg-ai-flag-dot"></span>' +
				'<span>' + ( f.source === 'vision' ? '👁 ' : '' ) + esc( f.description ) + '</span>' +
				'</div>';
		} );
		if ( flags.length > 3 ) {
			html += '<div style="font-size:11px;color:var(--ink3);margin-top:4px;">+' + ( flags.length - 3 ) + ' more flag' + ( flags.length - 3 === 1 ? '' : 's' ) + '</div>';
		}
		html += '</div>';
		return html;
	}

	function toggleAdState( adId, confirmed ) {
		if ( state.adsConfirmed[ adId ] === confirmed ) {
			delete state.adsConfirmed[ adId ];
			$( '[data-ad-id="' + adId + '"]' ).removeClass( 'confirmed flagged' );
		} else {
			state.adsConfirmed[ adId ] = confirmed;
			applyCardState( adId, confirmed );
		}
		saveState();
	}

	function applyCardState( adId, confirmed ) {
		var $card = $( '[data-ad-id="' + adId + '"]' );
		if ( confirmed === true ) {
			$card.addClass( 'confirmed' ).removeClass( 'flagged' );
		} else if ( confirmed === false ) {
			$card.addClass( 'flagged' ).removeClass( 'confirmed' );
		}
	}

	function submitConfirmAds() {
		clearNotice();
		var confirmations = [];
		state.ads.forEach( function ( ad ) {
			var sel = state.adsConfirmed[ String( ad.id ) ];
			if ( sel !== undefined ) {
				confirmations.push( { ad_id: ad.id, confirmed: sel } );
			}
		} );

		if ( ! confirmations.length ) {
			showNotice( 'Please confirm or flag at least one ad before continuing.', 'info' );
			return;
		}

		setLoading( '#bwg-ai-confirm-ads', true );

		apiPost( '/confirm-ads', { session_id: state.sessionId, confirmations: confirmations } )
			.done( function () {
				saveState();
				showNotice( 'Selections saved! Advancing to access request step…', 'success' );
				setTimeout( renderStep5, 1200 );
			} )
			.fail( function ( xhr ) {
				setLoading( '#bwg-ai-confirm-ads', false );
				showNotice( apiError( xhr, 'Could not save selections. Please try again.' ), 'error' );
			} );
	}

	/* Add more accounts -------------------------------------------- */

	function addAccountsFormHtml( suffix ) {
		var id = function ( base ) { return 'bwg-ai-' + base + '-' + suffix; };
		return '<div class="bwg-ai-add-accounts-form" id="' + id( 'acct-form' ) + '">' +
			'<p style="font-size:14px;color:var(--ink2);margin-bottom:16px;font-weight:500;">Add account identifiers so we can search more specifically.</p>' +
			'<div class="bwg-ai-acct-fields" id="' + id( 'acct-fields' ) + '">' +
				accountFieldRowHtml( suffix, 0 ) +
			'</div>' +
			'<button class="bwg-ai-btn bwg-ai-btn-outline bwg-ai-btn-sm" id="' + id( 'acct-add' ) + '" style="margin-top:8px;">+ Add another</button>' +
			'<div class="bwg-ai-btn-row" style="margin-top:16px;">' +
				'<button class="bwg-ai-btn bwg-ai-btn-primary" id="' + id( 'acct-submit' ) + '">Search for More Ads</button>' +
			'</div>' +
		'</div>';
	}

	function accountFieldRowHtml( suffix, idx ) {
		var rowId = 'bwg-ai-acct-row-' + suffix + '-' + idx;
		return '<div class="bwg-ai-acct-row" id="' + rowId + '">' +
			'<select class="bwg-ai-acct-type" id="bwg-ai-acct-type-' + suffix + '-' + idx + '">' +
				'<option value="facebook_page">Facebook Page Name / URL</option>' +
				'<option value="meta_ad_account">Meta Ad Account ID</option>' +
				'<option value="google_ads_account">Google Ads Account ID</option>' +
				'<option value="business_name">Business Name</option>' +
			'</select>' +
			'<input type="text" class="bwg-ai-acct-identifier" id="bwg-ai-acct-val-' + suffix + '-' + idx + '" placeholder="e.g. Sunrise Recovery Center">' +
		'</div>';
	}

	function bindAddAccountsForm( suffix ) {
		var id       = function ( base ) { return '#bwg-ai-' + base + '-' + suffix; };
		var count    = [ 1 ];

		$( id( 'acct-add' ) ).on( 'click', function () {
			$( id( 'acct-fields' ) ).append( accountFieldRowHtml( suffix, count[0] ) );
			count[0]++;
		} );

		$( id( 'acct-submit' ) ).on( 'click', function () {
			var accounts = [];
			for ( var i = 0; i < count[0]; i++ ) {
				var type = $( '#bwg-ai-acct-type-' + suffix + '-' + i ).val();
				var val  = $( '#bwg-ai-acct-val-' + suffix + '-' + i ).val().trim();
				if ( val ) { accounts.push( { type: type, identifier: val } ); }
			}

			if ( ! accounts.length ) {
				showNotice( 'Please enter at least one account identifier.', 'info' );
				return;
			}

			setLoading( id( 'acct-submit' ), true );

			apiPost( '/add-accounts', { session_id: state.sessionId, accounts: accounts } )
				.done( function ( res ) {
					var queued = res.queued || accounts.length;
					showNotice( 'Searching ' + queued + ' additional account' + ( queued === 1 ? '' : 's' ) + '. We\'ll email you when more results are ready.', 'success' );
					setLoading( id( 'acct-submit' ), false );
					// Collapse panel if visible in panel mode.
					$( '#bwg-ai-toggle-add-accounts' ).text( '+ Add More Ad Accounts' );
					$( '#bwg-ai-add-accounts-panel' ).hide();
				} )
				.fail( function ( xhr ) {
					setLoading( id( 'acct-submit' ), false );
					showNotice( apiError( xhr, 'Could not submit. Please try again.' ), 'error' );
				} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Step 5 — Access funnel                                              */
	/* ------------------------------------------------------------------ */
	function renderStep5() {
		state.step = 5;
		saveState();

		var platforms = [ 'meta', 'google' ];
		var cardsHtml = platforms.map( function ( p ) {
			return platformCardHtml( p, state.accessStatus[ p ] || 'none' );
		} ).join( '' );

		$steps.html(
			header( 'Phase 3 of 5', 'Request Ad Account Access', 'Grant us read-only access so we can pull full spend data and run a deeper compliance analysis. Pick your platform below.', 4 ) +
			'<div class="bwg-ai-body">' +
				'<p class="bwg-ai-access-intro">We only need <strong>read-only</strong> access — we cannot run, pause, or edit your ads. Step-by-step instructions will be sent to your email when you click "Request Access".</p>' +
				'<div class="bwg-ai-platform-cards">' + cardsHtml + '</div>' +
				'<div class="bwg-ai-continue-wrap" id="bwg-ai-access-continue" style="display:none;margin-top:32px;">' +
					'<button class="bwg-ai-btn bwg-ai-btn-primary" id="bwg-ai-to-report">Continue to Your Report</button>' +
					'<p style="font-size:12px;color:var(--ink3);margin-top:8px;">You can always come back to grant access once you\'ve completed the steps in your email.</p>' +
				'</div>' +
			'</div>'
		);

		platforms.forEach( bindPlatformCard );
		maybeShowContinue();
	}

	function platformCardHtml( platform, status ) {
		var labels  = { meta: 'Meta (Facebook / Instagram)', google: 'Google Ads' };
		var icons   = { meta: '📘', google: '📊' };
		var label   = labels[ platform ] || platform;
		var icon    = icons[ platform ]  || '📁';
		var badge   = statusBadgeHtml( status );

		var actionHtml;
		if ( status === 'granted' || status === 'export' ) {
			actionHtml = '<div class="bwg-ai-platform-done">Access confirmed. Thank you!</div>';
		} else {
			var uploadZoneId = 'bwg-ai-upload-' + platform;
			var uploadInputId = 'bwg-ai-file-' + platform;
			actionHtml =
				'<button class="bwg-ai-btn bwg-ai-btn-primary bwg-ai-request-btn" data-platform="' + esc( platform ) + '">' +
					( status === 'pending' ? 'Re-send Instructions' : 'Request Access' ) +
				'</button>' +
				'<div class="bwg-ai-or-divider"><span>or upload an export instead</span></div>' +
				'<div class="bwg-ai-upload-zone" id="' + uploadZoneId + '" data-platform="' + esc( platform ) + '">' +
					'<input type="file" id="' + uploadInputId + '" accept=".csv,text/csv" style="display:none;">' +
					'<div class="bwg-ai-upload-icon">&#8613;</div>' +
					'<p>Drag &amp; drop a CSV export here, or <a href="#" class="bwg-ai-upload-browse" data-for="' + uploadInputId + '">browse</a></p>' +
					'<p class="bwg-ai-upload-hint">CSV exported from ' + esc( label ) + ' Ads Manager &mdash; max 10MB</p>' +
				'</div>';
		}

		var instructionsHtml =
			'<div class="bwg-ai-instructions-toggle" data-platform="' + esc( platform ) + '">' +
				'<button class="bwg-ai-link-btn bwg-ai-instr-toggle-btn" data-platform="' + esc( platform ) + '">' +
					'View step-by-step instructions' +
				'</button>' +
			'</div>' +
			'<div class="bwg-ai-instructions-body" id="bwg-ai-instr-' + platform + '" style="display:none;">' +
				( platform === 'meta' ? metaInstructions() : googleInstructions() ) +
			'</div>';

		return '<div class="bwg-ai-platform-card" id="bwg-ai-card-' + esc( platform ) + '" data-platform="' + esc( platform ) + '">' +
			'<div class="bwg-ai-platform-card-header">' +
				'<span class="bwg-ai-platform-icon">' + icon + '</span>' +
				'<span class="bwg-ai-platform-name">' + esc( label ) + '</span>' +
				badge +
			'</div>' +
			'<div class="bwg-ai-platform-card-body" id="bwg-ai-card-body-' + esc( platform ) + '">' +
				actionHtml +
			'</div>' +
			instructionsHtml +
		'</div>';
	}

	function statusBadgeHtml( status ) {
		var map = {
			none    : [ 'Not requested', 'badge-none'    ],
			pending : [ 'Pending',        'badge-pending' ],
			granted : [ 'Granted',        'badge-granted' ],
			export  : [ 'Export uploaded','badge-export'  ],
		};
		var pair  = map[ status ] || map.none;
		return '<span class="bwg-ai-status-badge ' + pair[1] + '">' + pair[0] + '</span>';
	}

	function metaInstructions() {
		var steps = [
			'Go to <strong>Meta Business Suite</strong> (business.facebook.com) and log in as an admin.',
			'Click <strong>Settings</strong> (gear icon, bottom-left) &rarr; <strong>Business Settings</strong>.',
			'Under <strong>Users</strong>, click <strong>Partners</strong> &rarr; <strong>Add</strong>.',
			'Enter Business ID <code>YOUR_BWG_BID</code> and click <strong>Next</strong>.',
			'Under <strong>Assign Assets</strong>, select your <strong>Ad Accounts</strong> and toggle <strong>View Performance</strong> only.',
			'Click <strong>Save Changes</strong>. You\'ll see our name appear in your Partners list.',
			'Optionally, export your Ads Manager CSV: <strong>Ads Manager &rarr; Columns &rarr; Export</strong> and upload it below.',
		];
		return instructionListHtml( steps );
	}

	function googleInstructions() {
		var steps = [
			'Sign in to <strong>Google Ads</strong> (ads.google.com) as an account admin.',
			'Click the <strong>Tools &amp; Settings</strong> wrench &rarr; <strong>Account access and security</strong>.',
			'Click <strong>+ (Add user)</strong> and enter <code>access@bwg.agency</code>.',
			'Set the access level to <strong>Read only</strong> and click <strong>Send invitation</strong>.',
			'We\'ll accept the invite within 1 business day and notify you when data is visible.',
			'Alternatively, export your campaigns: <strong>Campaigns &rarr; Download report</strong> (CSV) and upload it below.',
		];
		return instructionListHtml( steps );
	}

	function instructionListHtml( steps ) {
		var html = '<ol class="bwg-ai-instr-list">';
		steps.forEach( function ( s ) {
			html += '<li>' + s + '</li>';
		} );
		html += '</ol>';
		return html;
	}

	function bindPlatformCard( platform ) {
		// Request-access button.
		$( '#bwg-ai-card-' + platform ).on( 'click', '.bwg-ai-request-btn', function () {
			submitRequestAccess( platform );
		} );

		// Instructions toggle.
		$( '#bwg-ai-card-' + platform ).on( 'click', '.bwg-ai-instr-toggle-btn', function () {
			var $body = $( '#bwg-ai-instr-' + platform );
			$body.slideToggle( 200 );
			$( this ).text( $body.is( ':hidden' ) ? 'View step-by-step instructions' : 'Hide instructions' );
		} );

		// Upload zone drag-and-drop.
		bindUploadZone( platform );
	}

	function submitRequestAccess( platform ) {
		var $btn = $( '#bwg-ai-card-' + platform + ' .bwg-ai-request-btn' );
		var origText = $btn.text();
		$btn.prop( 'disabled', true ).text( 'Sending…' );

		apiPost( '/request-access', { session_id: state.sessionId, platform: platform } )
			.done( function ( res ) {
				state.accessStatus[ platform ] = res.status || 'pending';
				saveState();
				updatePlatformStatus( platform, state.accessStatus[ platform ] );
				showNotice( 'Instructions sent to your email! Follow the steps to grant access.', 'success' );
				maybeShowContinue();
			} )
			.fail( function ( xhr ) {
				$btn.prop( 'disabled', false ).text( origText );
				showNotice( apiError( xhr, 'Could not send request. Please try again.' ), 'error' );
			} );
	}

	function bindUploadZone( platform ) {
		var zoneId    = '#bwg-ai-upload-' + platform;
		var inputId   = '#bwg-ai-file-' + platform;
		var $zone     = $( zoneId );
		if ( ! $zone.length ) { return; }

		// Browse link.
		$zone.on( 'click', '.bwg-ai-upload-browse', function ( e ) {
			e.preventDefault();
			$( inputId ).trigger( 'click' );
		} );

		// File input change.
		$zone.find( 'input[type="file"]' ).on( 'change', function () {
			if ( this.files && this.files[0] ) {
				uploadCsvFile( platform, this.files[0] );
			}
		} );

		// Drag-over highlight.
		$zone.on( 'dragover', function ( e ) {
			e.preventDefault();
			$zone.addClass( 'drag-over' );
		} );
		$zone.on( 'dragleave drop', function () {
			$zone.removeClass( 'drag-over' );
		} );

		// Drop.
		$zone.on( 'drop', function ( e ) {
			e.preventDefault();
			var dt    = e.originalEvent.dataTransfer;
			var files = dt && dt.files;
			if ( files && files[0] ) {
				uploadCsvFile( platform, files[0] );
			}
		} );
	}

	function uploadCsvFile( platform, file ) {
		var $zone = $( '#bwg-ai-upload-' + platform );
		$zone.addClass( 'uploading' ).find( 'p' ).first().text( 'Uploading…' );

		var formData = new FormData();
		formData.append( 'export_file', file );
		formData.append( 'platform', platform );
		formData.append( 'session_id', state.sessionId );

		$.ajax( {
			url         : window.bwgAI.restUrl + '/upload-export',
			method      : 'POST',
			headers     : apiHeaders(),
			data        : formData,
			processData : false,
			contentType : false,
		} )
		.done( function ( res ) {
			state.accessStatus[ platform ] = 'export';
			saveState();
			updatePlatformStatus( platform, 'export' );
			showNotice( 'Export uploaded! Parsed ' + ( res.rows_parsed || 0 ) + ' rows from your ' + platform + ' export.', 'success' );
			maybeShowContinue();
		} )
		.fail( function ( xhr ) {
			$zone.removeClass( 'uploading' ).find( 'p' ).first().text( 'Drag & drop a CSV export here, or browse' );
			showNotice( apiError( xhr, 'Upload failed. Please check the file and try again.' ), 'error' );
		} );
	}

	function updatePlatformStatus( platform, status ) {
		var $card = $( '#bwg-ai-card-' + platform );
		$card.find( '.bwg-ai-status-badge' ).replaceWith( statusBadgeHtml( status ) );

		var $body = $( '#bwg-ai-card-body-' + platform );
		if ( status === 'granted' || status === 'export' ) {
			$body.html( '<div class="bwg-ai-platform-done">Access confirmed. Thank you!</div>' );
		} else {
			// Update just the button text if pending.
			$body.find( '.bwg-ai-request-btn' ).prop( 'disabled', false ).text( 'Re-send Instructions' );
			$body.find( '.bwg-ai-upload-zone' ).removeClass( 'uploading' );
		}
	}

	function maybeShowContinue() {
		var actioned = Object.keys( state.accessStatus ).some( function ( p ) {
			return !! state.accessStatus[ p ];
		} );
		if ( actioned ) {
			$( '#bwg-ai-access-continue' ).slideDown( 200 );
			$( '#bwg-ai-to-report' ).off( 'click' ).on( 'click', renderReportStub );
		}
	}

	function renderReportStub() {
		state.step = 6;
		saveState();

		$steps.html(
			header( 'Phase 4 of 5', 'Generating Your Report', 'Your audit is complete. We\'re building your executive intelligence report now.', 5 ) +
			'<div class="bwg-ai-body">' +
				'<div class="bwg-ai-phase-next" id="bwg-ai-report-wrap">' +
					'<div class="bwg-ai-phase-icon">📋</div>' +
					'<h3>Building Report…</h3>' +
					'<p>Assembling your executive intelligence report. This takes just a moment.</p>' +
					'<div class="bwg-ai-progress-wrap" style="max-width:280px;margin:16px auto 0;">' +
						'<div class="bwg-ai-progress-bar"><div class="bwg-ai-progress-fill bwg-ai-progress-indeterminate"></div></div>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		apiPost( '/email-report', { session_id: state.sessionId } )
			.done( function ( res ) {
				var reportUrl = res.report_url || '';
				var $wrap     = $( '#bwg-ai-report-wrap' );

				$wrap.html(
					'<div class="bwg-ai-phase-icon">&#127881;</div>' +
					'<h3>Your Report Is Ready</h3>' +
					'<p>We\'ve emailed your report link. You can also open it directly below.</p>' +
					( reportUrl
						? '<a href="' + esc( reportUrl ) + '" class="bwg-ai-btn bwg-ai-btn-primary" target="_blank" rel="noopener" style="margin-top:20px;display:inline-block;">View Executive Report</a>'
						: ''
					) +
					( window.bwgAI && window.bwgAI.scheduleUrl
						? '<br><a href="' + esc( window.bwgAI.scheduleUrl ) + '" class="bwg-ai-btn bwg-ai-btn-gold" target="_blank" rel="noopener" style="margin-top:12px;display:inline-block;">Book a Strategy Call</a>'
						: ''
					) +
					( reportUrl
						? '<p style="font-size:12px;color:var(--ink3);margin-top:16px;word-break:break-all;">' + esc( reportUrl ) + '</p>'
						: ''
					)
				);
			} )
			.fail( function ( xhr ) {
				var $wrap = $( '#bwg-ai-report-wrap' );
				$wrap.html(
					'<div class="bwg-ai-phase-icon">&#9888;</div>' +
					'<h3>Report Generation Failed</h3>' +
					'<p style="color:var(--coral);">' + esc( apiError( xhr, 'Could not generate your report. Please try again or use your access code to return later.' ) ) + '</p>' +
					'<button class="bwg-ai-btn bwg-ai-btn-outline" id="bwg-ai-retry-report" style="margin-top:16px;">Try Again</button>'
				);
				$( '#bwg-ai-retry-report' ).on( 'click', renderReportStub );
			} );
	}

	/* ------------------------------------------------------------------ */
	/* Resume flow                                                          */
	/* ------------------------------------------------------------------ */
	function doResume( token, code, captchaToken ) {
		showNotice( 'Resuming your session…', 'info' );
		$steps.html( '<div style="padding:40px;text-align:center;"><div class="bwg-ai-spinner dark"></div></div>' );

		apiPost( '/resume', { resume_token: token, access_code: code, captcha_token: captchaToken || '' } )
			.done( function ( res ) {
				clearNotice();
				state.sessionId   = res.session_id;
				state.accessCode  = res.access_code;
				state.resumeToken = res.resume_token;
				state.websiteUrl  = res.website_url;
				state.discovered  = res.discovered;
				saveState();

				var dbStep = parseInt( res.step, 10 );
				if ( dbStep < 1 ) {
					// Discovery not confirmed yet — show review if data exists, else poll.
					if ( res.discovered ) { renderStep2(); } else { renderStep1(); }
				} else if ( dbStep === 1 ) {
					// Discovery confirmed, waiting for the Meta Ad Library lookup or ads just arrived.
					renderStep2();
				} else if ( dbStep === 2 ) {
					// Ads arrived, but user hasn't confirmed yet — show gallery.
					renderStep4();
				} else {
					// DB step 3+ — ads confirmed, user is in the access funnel.
					renderStep5();
				}
			} )
			.fail( function ( xhr ) {
				clearNotice();
				showNotice( apiError( xhr, 'Access code not found. Please check and try again.' ), 'error' );
				renderStep0();
			} );
	}

	function restoreFromLocal( saved ) {
		state = $.extend( state, saved );
		if      ( state.step <= 0 ) { renderStep0(); }
		else if ( state.step === 1 ) { renderStep1(); }
		else if ( state.step === 2 ) { renderStep2(); }
		else if ( state.step === 3 ) { renderStep3(); }
		else if ( state.step === 4 ) { renderStep4(); }
		else                         { renderStep5(); }
	}

	/* ------------------------------------------------------------------ */
	/* Helpers — API                                                        */
	/* ------------------------------------------------------------------ */
	function apiHeaders() {
		var h = { 'X-WP-Nonce': window.bwgAI.nonce };
		if ( state.resumeToken ) { h['X-BWG-Session-Token'] = state.resumeToken; }
		return h;
	}

	function apiPost( endpoint, data ) {
		return $.ajax( {
			url         : window.bwgAI.restUrl + endpoint,
			method      : 'POST',
			headers     : apiHeaders(),
			data        : JSON.stringify( data ),
			contentType : 'application/json',
		} );
	}

	function apiGet( endpoint ) {
		return $.ajax( {
			url     : window.bwgAI.restUrl + endpoint,
			method  : 'GET',
			headers : apiHeaders(),
		} );
	}

	function apiError( xhr, fallback ) {
		return ( xhr.responseJSON && xhr.responseJSON.message ) ? xhr.responseJSON.message : fallback;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers — UI                                                         */
	/* ------------------------------------------------------------------ */
	function header( phase, title, subtitle, stepIndex ) {
		var totalSteps = 5;
		var dots = '';
		for ( var i = 0; i < totalSteps; i++ ) {
			var cls = i < stepIndex ? 'done' : ( i === stepIndex ? 'active' : '' );
			dots += '<div class="bwg-ai-step-dot ' + cls + '"></div>';
		}
		return '<div class="bwg-ai-header">' +
			'<div class="bwg-ai-header-label">' + esc( phase ) + '</div>' +
			'<h2>' + esc( title ) + '</h2>' +
			'<p>' + esc( subtitle ) + '</p>' +
			'<div class="bwg-ai-step-track">' + dots + '</div>' +
			'</div>';
	}

	function field( id, label, type, placeholder, hint ) {
		return '<div class="bwg-ai-field">' +
			'<label for="' + esc( id ) + '">' + esc( label ) + '</label>' +
			'<input type="' + esc( type ) + '" id="' + esc( id ) + '" name="' + esc( id ) + '" placeholder="' + esc( placeholder ) + '" autocomplete="off">' +
			( hint ? '<div class="bwg-ai-field-hint">' + esc( hint ) + '</div>' : '' ) +
			'<div class="bwg-ai-field-error" id="err-' + esc( id ) + '"></div>' +
			'</div>';
	}

	function cardHtml( title, rows ) {
		var rowsHtml = rows.map( function ( r ) {
			var val = r.val || '';
			var valHtml = r.editable
				? '<input class="bwg-ai-inline-input" id="edit-' + esc( r.editable ) + '" type="text" value="' + esc( val ) + '">'
				: '<span class="bwg-ai-card-val' + ( val ? '' : ' empty' ) + '">' + esc( val || '—' ) + '</span>';
			return '<div class="bwg-ai-card-row">' +
				'<span class="bwg-ai-card-key">' + esc( r.key ) + '</span>' +
				valHtml +
				'</div>';
		} ).join( '' );
		return '<div class="bwg-ai-card">' +
			'<div class="bwg-ai-card-label">' + esc( title ) + '</div>' +
			rowsHtml +
			'</div>';
	}

	function chipsHtml( items ) {
		var filtered = ( items || [] ).filter( Boolean );
		if ( ! filtered.length ) { return ''; }
		return '<div class="bwg-ai-chips">' +
			filtered.map( function ( item ) {
				return '<span class="bwg-ai-chip ' + esc( item.cls || '' ) + '">' + esc( item.label ) + '</span>';
			} ).join( '' ) +
			'</div>';
	}

	function showNotice( msg, type ) {
		$notice.text( msg ).removeClass( 'error success info' ).addClass( type ).show();
	}

	function clearNotice() { $notice.hide().text( '' ); }

	function fieldError( fieldId, msg ) {
		$( '#' + fieldId ).addClass( 'error' );
		$( '#err-' + fieldId ).text( msg ).show();
		$( '#' + fieldId ).on( 'input.bwgai', function () {
			$( this ).removeClass( 'error' );
			$( '#err-' + fieldId ).hide();
			$( this ).off( 'input.bwgai' );
		} );
	}

	function setLoading( selector, on ) {
		var $btn = $( selector );
		if ( on ) {
			$btn.prop( 'disabled', true ).addClass( 'loading' )
				.data( 'orig', $btn.html() )
				.html( '<span class="bwg-ai-spinner"></span> Working…' );
		} else {
			$btn.prop( 'disabled', false ).removeClass( 'loading' )
				.html( $btn.data( 'orig' ) || $btn.html() );
		}
	}

	function showCooldown( seconds ) {
		var remaining = seconds;
		showNotice( 'Rate limit reached. Try again in ' + remaining + 's.', 'error' );
		var timer = setInterval( function () {
			remaining--;
			if ( remaining <= 0 ) {
				clearInterval( timer );
				clearNotice();
				setLoading( '#bwg-ai-submit-0', false );
			} else {
				showNotice( 'Rate limit reached. Try again in ' + remaining + 's.', 'error' );
			}
		}, 1000 );
	}

	function clearPollTimer() {
		if ( state.pollTimer ) { clearTimeout( state.pollTimer ); state.pollTimer = null; }
	}

	/* ------------------------------------------------------------------ */
	/* Helpers — Validation                                                 */
	/* ------------------------------------------------------------------ */
	function isValidUrl( url )   { return /^https?:\/\/.+\..+/.test( url ); }
	function isValidEmail( em )  { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( em ); }

	/* ------------------------------------------------------------------ */
	/* Helpers — Misc                                                       */
	/* ------------------------------------------------------------------ */
	function esc( str ) {
		if ( str === null || str === undefined ) { return ''; }
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#x27;' );
	}

	function copyToClipboard( text ) {
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
		}
	}

	function getUserEmail() { return $( '#email' ).val() || ''; }

	/* ------------------------------------------------------------------ */
	/* localStorage persistence                                             */
	/* ------------------------------------------------------------------ */
	function saveState() {
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( {
				sessionId    : state.sessionId,
				accessCode   : state.accessCode,
				resumeToken  : state.resumeToken,
				websiteUrl   : state.websiteUrl,
				step         : state.step,
				discovered   : state.discovered,
				flags        : state.flags,
				ads          : state.ads,
				adsConfirmed : state.adsConfirmed,
				accessStatus : state.accessStatus,
			} ) );
		} catch ( e ) { /* storage full or private mode — silently ignore */ }
	}

	function loadState() {
		try {
			var raw = localStorage.getItem( STORAGE_KEY );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) { return null; }
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                 */
	/* ------------------------------------------------------------------ */
	$( document ).ready( function () {
		if ( ! $app.length ) { return; }
		init();
	} );

} )( jQuery );
