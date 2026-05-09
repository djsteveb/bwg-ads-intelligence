/**
 * BWG Ads Intelligence — Front-End Step Machine
 *
 * Steps:
 *   0 — URL + email entry form
 *   1 — Discovery running (progress bar polling)
 *   2 — Discovery review (confirm/edit discovered data)
 *   3 — Phase 2 queued (ad surface loading — filled in M7)
 *
 * State is persisted to localStorage keyed by session_id as a fallback
 * if the user closes and reopens without a resume link.
 */
( function ( $ ) {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* Config                                                               */
	/* ------------------------------------------------------------------ */
	var STORAGE_KEY   = 'bwgai_session';
	var POLL_INTERVAL = 3000; // ms between /discovery-status polls

	/* ------------------------------------------------------------------ */
	/* State                                                                */
	/* ------------------------------------------------------------------ */
	var state = {
		sessionId    : null,
		accessCode   : '',
		resumeToken  : '',
		step         : 0,
		websiteUrl   : '',
		discovered   : null,
		flags        : [],
		pollTimer    : null,
		pollAttempts : 0,
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
		// Try resume token / access code from URL (set by PHP on shortcode).
		var resumeToken = ( window.bwgAI && window.bwgAI.resumeToken ) || '';
		var accessCode  = ( window.bwgAI && window.bwgAI.accessCode  ) || '';

		// Also check localStorage for an in-progress session.
		var saved = loadState();

		if ( resumeToken || accessCode ) {
			doResume( resumeToken, accessCode );
			return;
		}

		if ( saved && saved.sessionId ) {
			// Restore from localStorage without a full resume call —
			// just render the saved step. User can enter access code if needed.
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
						'<button type="submit" class="bwg-ai-btn bwg-ai-btn-primary" id="bwg-ai-submit-0">' +
							'Start Free Audit' +
						'</button>' +
					'</div>' +
					'<p style="font-size:12px;color:var(--ink3);margin-top:16px;">Already started? ' +
						'<a href="#" id="bwg-ai-show-resume" style="color:var(--teal);">Enter your access code</a>' +
					'</p>' +
				'</form>' +
				'<div id="bwg-ai-resume-form" style="display:none;margin-top:16px;">' +
					field( 'access_code_input', 'Access Code', 'text', 'e.g. AB3XY7', 'Enter the 6-character code from your confirmation email.' ) +
					'<div class="bwg-ai-btn-row">' +
						'<button class="bwg-ai-btn bwg-ai-btn-outline" id="bwg-ai-resume-btn">Resume Session</button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		// Reinitialise Turnstile if loaded.
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
			doResume( '', code );
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

		// Client-side validation.
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

		var payload = {
			website_url   : url,
			email         : email,
			captcha_token : captchaToken,
		};

		apiPost( '/start', payload )
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
				var msg = apiError( xhr, 'Could not start the audit. Please try again.' );
				// If rate limited, show cooldown.
				if ( xhr.status === 429 ) {
					var retryAfter = ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.retry_after ) || 60;
					showCooldown( retryAfter );
				} else {
					showNotice( msg, 'error' );
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
				// Bail out after 3 minutes of polling (60 × 3s).
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
			starting      : 'Starting…',
			fetch         : 'Fetching website…',
			nap           : 'Extracting business details…',
			gbp           : 'Matching Google Business Profile…',
			social        : 'Detecting social profiles…',
			pixels        : 'Scanning for tracking pixels…',
			tech_stack    : 'Fingerprinting tech stack…',
			whois         : 'Looking up WHOIS / domain info…',
			legitscript   : 'Checking LegitScript status…',
			licensure     : 'Checking licensure signals…',
			complete      : 'Discovery complete!',
			in_progress   : 'Analysing…',
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

		var gbp    = d.gbp || {};
		var gbpHtml = cardHtml( 'Google Business Profile', [
			{ key: 'Status',   val: gbp.place_id ? 'Found' : 'Not found' },
			{ key: 'Rating',   val: gbp.rating ? gbp.rating + ' ★ (' + gbp.review_count + ' reviews)' : '' },
			{ key: 'Category', val: gbp.category },
		] );

		var social    = d.social || {};
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

		var pixels    = d.pixels || {};
		var pixelChips = chipsHtml( [
			pixels.meta     ? { label: 'Meta Pixel '  + pixels.meta,     cls: 'pixel' } : null,
			pixels.gtm      ? { label: 'GTM '         + pixels.gtm,      cls: 'pixel' } : null,
			pixels.ga4      ? { label: 'GA4 '         + pixels.ga4,      cls: 'pixel' } : null,
			pixels.tiktok   ? { label: 'TikTok Pixel '+ pixels.tiktok,   cls: 'pixel' } : null,
			pixels.linkedin ? { label: 'LinkedIn '    + pixels.linkedin,  cls: 'pixel' } : null,
		] );
		var pixelHtml = '<div class="bwg-ai-card">' +
			'<div class="bwg-ai-card-label">Tracking Pixels & Tags</div>' +
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
			{ key: 'Registrar',  val: whois.registrar },
			{ key: 'Registered', val: whois.created_at },
			{ key: 'Expires',    val: whois.expires_at },
			{ key: 'Nameservers',val: whois.nameservers },
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
					businessHtml +
					gbpHtml +
					socialHtml +
					pixelHtml +
					techHtml +
					whoisHtml +
					lsHtml +
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

		// Remove undefined fields.
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
	/* Step 3 — Phase 2 loading (ad surface queued)                        */
	/* ------------------------------------------------------------------ */
	function renderStep3() {
		state.step = 3;
		$steps.html(
			header( 'Phase 2 of 5', 'Pulling Your Ad Library', 'We\'re now fetching your active and historical ads from Meta Ad Library. This usually takes 1–3 minutes.', 3 ) +
			'<div class="bwg-ai-body">' +
				'<div class="bwg-ai-phase-next">' +
					'<div class="bwg-ai-phase-icon">📊</div>' +
					'<h3>Ad Surface Scan Running</h3>' +
					'<p>We\'re pulling your ads from Meta Ad Library. You\'ll receive an email when we have results to show you.</p>' +
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
	}

	/* ------------------------------------------------------------------ */
	/* Resume flow                                                          */
	/* ------------------------------------------------------------------ */
	function doResume( token, code ) {
		showNotice( 'Resuming your session…', 'info' );
		$steps.html( '<div style="padding:40px;text-align:center;"><div class="bwg-ai-spinner dark"></div></div>' );

		apiPost( '/resume', { resume_token: token, access_code: code } )
			.done( function ( res ) {
				clearNotice();
				state.sessionId   = res.session_id;
				state.accessCode  = res.access_code;
				state.resumeToken = res.resume_token;
				state.websiteUrl  = res.website_url;
				state.discovered  = res.discovered;
				saveState();

				var step = parseInt( res.step, 10 );
				if ( step === 0 ) {
					// Discovery not started yet — go to polling.
					renderStep1();
				} else if ( step < 1 ) {
					renderStep1();
				} else if ( step === 1 ) {
					// Discovery data present — show review.
					renderStep2();
				} else {
					// Step 3+ — show phase 2 loading screen.
					renderStep3();
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
		if ( state.step <= 0 )      { renderStep0(); }
		else if ( state.step === 1 ) { renderStep1(); }
		else if ( state.step === 2 ) { renderStep2(); }
		else                         { renderStep3(); }
	}

	/* ------------------------------------------------------------------ */
	/* Helpers — API                                                        */
	/* ------------------------------------------------------------------ */
	function apiPost( endpoint, data ) {
		return $.ajax( {
			url     : window.bwgAI.restUrl + endpoint,
			method  : 'POST',
			headers : { 'X-WP-Nonce': window.bwgAI.nonce },
			data    : JSON.stringify( data ),
			contentType : 'application/json',
		} );
	}

	function apiGet( endpoint ) {
		return $.ajax( {
			url     : window.bwgAI.restUrl + endpoint,
			method  : 'GET',
			headers : { 'X-WP-Nonce': window.bwgAI.nonce },
		} );
	}

	function apiError( xhr, fallback ) {
		if ( xhr.responseJSON ) {
			return xhr.responseJSON.message || fallback;
		}
		return fallback;
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
			var val    = r.val || '';
			var valHtml;
			if ( r.editable ) {
				valHtml = '<input class="bwg-ai-inline-input" id="edit-' + esc( r.editable ) + '" type="text" value="' + esc( val ) + '">';
			} else {
				valHtml = '<span class="bwg-ai-card-val' + ( val ? '' : ' empty' ) + '">' + esc( val || '—' ) + '</span>';
			}
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

	function clearNotice() {
		$notice.hide().text( '' );
	}

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
		if ( state.pollTimer ) {
			clearTimeout( state.pollTimer );
			state.pollTimer = null;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers — Validation                                                 */
	/* ------------------------------------------------------------------ */
	function isValidUrl( url ) {
		return /^https?:\/\/.+\..+/.test( url );
	}

	function isValidEmail( email ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers — Misc                                                       */
	/* ------------------------------------------------------------------ */
	function esc( str ) {
		if ( str === null || str === undefined ) { return ''; }
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#x27;' );
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

	function getUserEmail() {
		return $( '#email' ).val() || '';
	}

	/* ------------------------------------------------------------------ */
	/* localStorage persistence                                             */
	/* ------------------------------------------------------------------ */
	function saveState() {
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( {
				sessionId   : state.sessionId,
				accessCode  : state.accessCode,
				resumeToken : state.resumeToken,
				websiteUrl  : state.websiteUrl,
				step        : state.step,
				discovered  : state.discovered,
				flags       : state.flags,
			} ) );
		} catch ( e ) { /* storage full or private mode */ }
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
