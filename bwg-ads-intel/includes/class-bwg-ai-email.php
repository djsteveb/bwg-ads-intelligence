<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Email {

	const PROVIDER_WPMAIL   = 'wpmail';
	const PROVIDER_SENDGRID = 'sendgrid';
	const PROVIDER_POSTMARK = 'postmark';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Central dispatcher. Routes to wp_mail, SendGrid, or Postmark based on
	 * the bwg_ai_email_provider option. Using wp_mail means any SMTP plugin
	 * already installed will intercept it naturally — we never fight them.
	 *
	 * @param string   $to         Recipient email.
	 * @param string   $subject    Email subject line.
	 * @param string   $html_body  Full HTML string (inline CSS, no external sheets).
	 * @param int|null $session_id For audit logging. Pass null for system emails.
	 * @return bool
	 */
	public function send( $to, $subject, $html_body, $session_id = null ) {
		$provider   = get_option( 'bwg_ai_email_provider', self::PROVIDER_WPMAIL );
		$from_name  = get_option( 'bwg_ai_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'bwg_ai_from_email', get_option( 'admin_email' ) );

		switch ( $provider ) {
			case self::PROVIDER_SENDGRID:
				$result = $this->send_via_sendgrid( $to, $subject, $html_body, $from_name, $from_email );
				break;
			case self::PROVIDER_POSTMARK:
				$result = $this->send_via_postmark( $to, $subject, $html_body, $from_name, $from_email );
				break;
			default:
				$headers = [
					'Content-Type: text/html; charset=UTF-8',
					"From: {$from_name} <{$from_email}>",
				];
				$result = wp_mail( $to, $subject, $html_body, $headers );
		}

		if ( $session_id ) {
			$status = $result ? 'email_sent' : 'email_failed';
			BWG_AI_Session::log( $session_id, $status, "Subject: {$subject}" );
		}

		return (bool) $result;
	}

	/**
	 * Fires immediately after session creation: access code + resume link + what's next.
	 */
	public function send_save_spot( $session ) {
		return $this->send(
			$session->email,
			'Your Audit is Running — Save Your Access Code',
			$this->tpl_save_spot( $session ),
			$session->id
		);
	}

	/**
	 * Fires once EntityIQ returns ads: "We found X ads" teaser.
	 */
	public function send_ads_preview( $session, $ad_count ) {
		$ad_word = absint( $ad_count ) === 1 ? 'ad' : 'ads';
		return $this->send(
			$session->email,
			"We Found {$ad_count} {$ad_word} — Here's What We Discovered",
			$this->tpl_ads_preview( $session, absint( $ad_count ) ),
			$session->id
		);
	}

	/**
	 * Day 1 follow-up: compliance flag teaser + resume CTA.
	 */
	public function send_followup_day1( $session ) {
		return $this->send(
			$session->email,
			'Your Ad Compliance Report Is Waiting',
			$this->tpl_followup_day1( $session ),
			$session->id
		);
	}

	/**
	 * Day 3 follow-up: urgency + specific flag count.
	 */
	public function send_followup_day3( $session ) {
		$flag_count = $this->get_compliance_flag_count( $session->id );
		$flag_word  = $flag_count === 1 ? 'issue' : 'issues';
		return $this->send(
			$session->email,
			"Still {$flag_count} Compliance {$flag_word} in Your Running Ads",
			$this->tpl_followup_day3( $session, $flag_count ),
			$session->id
		);
	}

	/**
	 * Day 7 follow-up: final outreach + direct booking link.
	 */
	public function send_followup_day7( $session ) {
		return $this->send(
			$session->email,
			'Your Audit Results — Final Notice',
			$this->tpl_followup_day7( $session ),
			$session->id
		);
	}

	/**
	 * Per-platform access request email with step-by-step instructions.
	 *
	 * @param object $session
	 * @param string $platform  'meta' | 'google'
	 */
	public function send_access_request( $session, $platform ) {
		$platform_name = ucfirst( sanitize_text_field( $platform ) );
		return $this->send(
			$session->email,
			"Grant {$platform_name} Ad Account Access — Next Step",
			$this->tpl_access_request( $session, $platform ),
			$session->id
		);
	}

	/**
	 * Report ready: tokenized link to the generated report.
	 */
	public function send_report_ready( $session, $report_token ) {
		return $this->send(
			$session->email,
			'Your Ads Intelligence Report Is Ready',
			$this->tpl_report_ready( $session, sanitize_text_field( $report_token ) ),
			$session->id
		);
	}

	// -------------------------------------------------------------------------
	// Drip cron handler — hooked to bwg_ai_send_access_followup (hourly)
	// -------------------------------------------------------------------------

	public function send_followups() {
		if ( ! wp_doing_cron() ) {
			return;
		}

		$drip = [
			1 => 'send_followup_day1',
			3 => 'send_followup_day3',
			7 => 'send_followup_day7',
		];

		foreach ( $drip as $days => $method ) {
			$sessions = BWG_AI_Session::get_due_for_followup( $days );
			foreach ( $sessions as $session ) {
				$action_key = "followup_day{$days}";
				if ( $this->already_sent( $session->id, $action_key ) ) {
					continue;
				}
				$this->$method( $session );
				BWG_AI_Session::log( $session->id, $action_key, "Day {$days} follow-up email sent." );
			}
		}

		$this->notify_on_abuse();
	}

	/**
	 * Send an admin alert if abnormal activity thresholds are breached.
	 * Called once per hour by the send_followups cron.
	 *
	 * Thresholds: >100 new sessions in the last hour, >50 failed resume
	 * attempts in the last hour (potential brute-force scraping).
	 */
	public function notify_on_abuse() {
		global $wpdb;
		$p    = $wpdb->prefix . 'bwg_ai_';
		$hour = gmdate( 'Y-m-d H:i:s', time() - 3600 );

		$sessions_hr = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$p}sessions` WHERE created_at >= %s",
				$hour
			)
		);

		$failed_resumes_hr = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$p}audit_log` WHERE action = 'resume_failed' AND created_at >= %s",
				$hour
			)
		);

		$alerts = [];
		if ( $sessions_hr > 100 ) {
			$alerts[] = "{$sessions_hr} new sessions created in the last hour (threshold: 100).";
		}
		if ( $failed_resumes_hr > 50 ) {
			$alerts[] = "{$failed_resumes_hr} failed resume attempts in the last hour (threshold: 50).";
		}

		if ( empty( $alerts ) ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );
		$subject     = "[{$site_name}] BWG Ads Intelligence — Abuse Alert";
		$body        = "Unusual activity detected on the Ads Intelligence plugin:\n\n"
		               . implode( "\n", $alerts )
		               . "\n\nPlease review your site's admin panel.\n\n"
		               . admin_url( 'admin.php?page=bwg-ads-intel' );

		wp_mail( $admin_email, $subject, $body );
		BWG_AI_Session::log( null, 'abuse_alert', implode( ' | ', $alerts ) );
	}

	// -------------------------------------------------------------------------
	// Provider implementations
	// -------------------------------------------------------------------------

	private function send_via_sendgrid( $to, $subject, $html_body, $from_name, $from_email ) {
		$api_key = bwg_ai_decrypt_secret( get_option( 'bwg_ai_sendgrid_api_key', '' ) );
		if ( ! $api_key ) {
			return false;
		}

		$response = wp_remote_post( 'https://api.sendgrid.com/v3/mail/send', [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'personalizations' => [ [ 'to' => [ [ 'email' => $to ] ] ] ],
				'from'             => [ 'email' => $from_email, 'name' => $from_name ],
				'subject'          => $subject,
				'content'          => [ [ 'type' => 'text/html', 'value' => $html_body ] ],
			] ),
			'timeout' => 15,
		] );

		return ! is_wp_error( $response ) && 202 === (int) wp_remote_retrieve_response_code( $response );
	}

	private function send_via_postmark( $to, $subject, $html_body, $from_name, $from_email ) {
		$api_key = bwg_ai_decrypt_secret( get_option( 'bwg_ai_postmark_api_key', '' ) );
		if ( ! $api_key ) {
			return false;
		}

		$response = wp_remote_post( 'https://api.postmarkapp.com/email', [
			'headers' => [
				'X-Postmark-Server-Token' => $api_key,
				'Content-Type'            => 'application/json',
				'Accept'                  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'From'     => "{$from_name} <{$from_email}>",
				'To'       => $to,
				'Subject'  => $subject,
				'HtmlBody' => $html_body,
			] ),
			'timeout' => 15,
		] );

		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check the audit_log for a prior send of this action to avoid re-sending.
	 */
	private function already_sent( $session_id, $action ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}bwg_ai_audit_log`
				 WHERE session_id = %d AND action = %s LIMIT 1",
				absint( $session_id ),
				$action
			)
		);
	}

	/**
	 * Count total compliance flags across all ads for a session.
	 */
	private function get_compliance_flag_count( $session_id ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT compliance_flags FROM `{$wpdb->prefix}bwg_ai_ads`
				 WHERE session_id = %d AND compliance_flags IS NOT NULL AND compliance_flags != ''",
				absint( $session_id )
			)
		);
		$count = 0;
		foreach ( $rows as $json ) {
			$flags  = json_decode( $json, true );
			$count += is_array( $flags ) ? count( $flags ) : 0;
		}
		return $count;
	}

	/**
	 * Build the resume URL for this session.
	 */
	private function resume_url( $session ) {
		$base = get_option( 'bwg_ai_shortcode_page_url', home_url( '/' ) );
		return add_query_arg( 'resume', rawurlencode( $session->resume_token ), $base );
	}

	// -------------------------------------------------------------------------
	// Email templates — inline CSS, no external stylesheets
	// -------------------------------------------------------------------------

	private function tpl_save_spot( $session ) {
		$access_code = esc_html( $session->access_code );
		$resume_url  = esc_url( $this->resume_url( $session ) );

		$body = '
		<h2 style="color:#1a1a2e;font-family:Georgia,serif;font-size:26px;margin:0 0 16px;">Your Audit is Underway</h2>
		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">We\'re scanning your ad footprint across platforms. This takes a few minutes. <strong>Save your access code below</strong> — you\'ll need it to return to your results if you close this tab.</p>

		<div style="background:#f8f6f0;border:2px solid #c9a84c;border-radius:8px;padding:24px;text-align:center;margin:0 0 32px;">
			<p style="color:#4a4a4a;font-size:12px;letter-spacing:0.1em;text-transform:uppercase;margin:0 0 8px;">Your Access Code</p>
			<p style="color:#1a1a2e;font-family:\'Courier New\',Courier,monospace;font-size:34px;font-weight:700;letter-spacing:0.25em;margin:0;">' . $access_code . '</p>
		</div>

		<p style="color:#4a4a4a;font-size:15px;font-weight:600;margin:0 0 8px;">What happens next:</p>
		<ol style="color:#4a4a4a;font-size:15px;line-height:1.8;margin:0 0 32px;padding-left:20px;">
			<li>We confirm your business info (Google Business Profile, social accounts)</li>
			<li>We scan Meta Ad Library for every active ad tied to your accounts</li>
			<li>We run HIPAA and platform compliance checks on every ad</li>
			<li>You review findings and get a full risk report with prioritized fixes</li>
		</ol>

		<div style="text-align:center;margin:0 0 32px;">
			<a href="' . $resume_url . '" style="background:#1a1a2e;color:#ffffff;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">Return to My Audit</a>
		</div>

		<p style="color:#aaa;font-size:12px;line-height:1.5;margin:0;">This link is unique to your session — do not share it. Your access code is case-insensitive.</p>';

		return $this->wrap( $body, 'Your Audit is Running — Save Your Access Code' );
	}

	private function tpl_ads_preview( $session, $ad_count ) {
		$resume_url = esc_url( $this->resume_url( $session ) );
		$ad_word    = $ad_count === 1 ? 'ad' : 'ads';
		$count_text = esc_html( (string) $ad_count );

		$body = '
		<h2 style="color:#1a1a2e;font-family:Georgia,serif;font-size:26px;margin:0 0 16px;">We Found ' . $count_text . ' ' . $ad_word . '</h2>
		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">Your audit scan is complete. We discovered <strong>' . $count_text . ' active ' . $ad_word . '</strong> running across platforms and put them through our compliance engine. You have findings waiting.</p>

		<div style="background:#fff8f0;border-left:4px solid #e07b39;border-radius:0 8px 8px 0;padding:20px 24px;margin:0 0 32px;">
			<p style="color:#1a1a2e;font-size:15px;font-weight:600;margin:0 0 8px;">Your report includes:</p>
			<ul style="color:#4a4a4a;font-size:15px;line-height:1.8;margin:0;padding-left:20px;">
				<li>HIPAA compliance flags on specific ad copy</li>
				<li>Platform policy violations (Meta, Google)</li>
				<li>LegitScript certification status</li>
				<li>Compliance risk score (0–100)</li>
				<li>Estimated ad spend at risk</li>
			</ul>
		</div>

		<div style="text-align:center;margin:0 0 32px;">
			<a href="' . $resume_url . '" style="background:#1a1a2e;color:#ffffff;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">View My Ad Findings</a>
		</div>';

		return $this->wrap( $body, "We Found {$count_text} {$ad_word} — Here's What We Discovered" );
	}

	private function tpl_followup_day1( $session ) {
		$resume_url = esc_url( $this->resume_url( $session ) );

		$body = '
		<h2 style="color:#1a1a2e;font-family:Georgia,serif;font-size:26px;margin:0 0 16px;">Your Compliance Report is Waiting</h2>
		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">You started an ad audit yesterday but haven\'t checked the results yet. Our compliance engine flagged issues we think you should know about before your next campaign spend.</p>

		<div style="background:#fff0f0;border-left:4px solid #c0392b;border-radius:0 8px 8px 0;padding:20px 24px;margin:0 0 32px;">
			<p style="color:#c0392b;font-size:15px;font-weight:600;margin:0 0 8px;">Why this matters now</p>
			<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0;">Treatment center ads face stricter enforcement than nearly any other industry. Meta and Google have suspended accounts and demanded repayment of past ad spend for HIPAA violations and policy breaches — often with no warning. Every day flagged ads run, your exposure grows.</p>
		</div>

		<div style="text-align:center;margin:0 0 32px;">
			<a href="' . $resume_url . '" style="background:#c0392b;color:#ffffff;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">See My Compliance Flags</a>
		</div>

		<p style="color:#aaa;font-size:12px;margin:0;">Your access code: <strong style="color:#4a4a4a;">' . esc_html( $session->access_code ) . '</strong></p>';

		return $this->wrap( $body, 'Your Ad Compliance Report Is Waiting' );
	}

	private function tpl_followup_day3( $session, $flag_count ) {
		$resume_url  = esc_url( $this->resume_url( $session ) );
		$flag_text   = esc_html( (string) $flag_count );
		$flag_word   = $flag_count === 1 ? 'issue' : 'issues';
		$booking_url = get_option( 'bwg_ai_booking_url', '' );

		$booking_btn = '';
		if ( $booking_url ) {
			$booking_btn = '<a href="' . esc_url( $booking_url ) . '" style="background:#c9a84c;color:#1a1a2e;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 28px;border-radius:6px;text-decoration:none;margin-left:12px;">Book a Strategy Call</a>';
		}

		$body = '
		<h2 style="color:#1a1a2e;font-family:Georgia,serif;font-size:26px;margin:0 0 16px;">Still ' . $flag_text . ' Compliance ' . $flag_word . ' in Your Running Ads</h2>
		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">Three days ago we audited your ad accounts. We found <strong>' . $flag_text . ' compliance ' . $flag_word . '</strong> in ads that may still be running right now.</p>

		<div style="background:#f8f6f0;border:1px solid #ddd;border-radius:8px;padding:24px;margin:0 0 32px;">
			<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 16px;">Every day these ads run, your risk compounds. Platform enforcement in the addiction treatment space has intensified — and retroactive enforcement (demanding repayment for past spend you\'ve already run) is common.</p>
			<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0;">We\'ve helped treatment centers correct these issues and protect $10k–$50k/month in ad spend. Your findings are specific and actionable.</p>
		</div>

		<div style="text-align:center;margin:0 0 32px;">
			<a href="' . $resume_url . '" style="background:#1a1a2e;color:#ffffff;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 28px;border-radius:6px;text-decoration:none;">' . ( $booking_url ? 'View Full Report' : 'View My Compliance Report' ) . '</a>' . $booking_btn . '
		</div>

		<p style="color:#aaa;font-size:12px;margin:0;">Your access code: <strong style="color:#4a4a4a;">' . esc_html( $session->access_code ) . '</strong></p>';

		return $this->wrap( $body, "Still {$flag_text} Compliance {$flag_word} in Your Running Ads" );
	}

	private function tpl_followup_day7( $session ) {
		$resume_url  = esc_url( $this->resume_url( $session ) );
		$booking_url = get_option( 'bwg_ai_booking_url', '' );
		$expires_in  = BWG_AI_Session::RESUME_TOKEN_EXPIRY - 7;

		$booking_section = '';
		if ( $booking_url ) {
			$booking_section = '
			<div style="text-align:center;margin:0 0 16px;">
				<a href="' . esc_url( $booking_url ) . '" style="background:#1a1a2e;color:#ffffff;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">Book a 30-Minute Call</a>
			</div>';
		}

		$body = '
		<h2 style="color:#1a1a2e;font-family:Georgia,serif;font-size:26px;margin:0 0 16px;">One Last Note on Your Audit</h2>
		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">A week ago you ran an ad compliance audit with us. Your results are still saved — but I wanted to reach out one final time before your report expires.</p>

		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">If you\'ve already addressed the issues internally — great. If not, we\'re here when you\'re ready. Our team specializes exclusively in treatment center advertising: HIPAA compliance, platform policy, LegitScript, and protecting ad accounts from enforcement actions.</p>

		' . $booking_section . '

		<div style="text-align:center;margin:0 0 32px;">
			<a href="' . $resume_url . '" style="color:#1a1a2e;display:inline-block;font-family:Arial,sans-serif;font-size:14px;padding:8px 0;text-decoration:underline;">View my audit report</a>
		</div>

		<p style="color:#aaa;font-size:12px;margin:0;">Access code: <strong style="color:#4a4a4a;">' . esc_html( $session->access_code ) . '</strong> &nbsp;·&nbsp; Report link expires in ' . absint( $expires_in ) . ' days.</p>';

		return $this->wrap( $body, 'Your Audit Results — Final Notice' );
	}

	private function tpl_access_request( $session, $platform ) {
		$resume_url    = esc_url( $this->resume_url( $session ) );
		$platform_name = esc_html( ucfirst( $platform ) );

		if ( 'meta' === $platform ) {
			$instructions = '
			<ol style="color:#4a4a4a;font-size:15px;line-height:1.8;margin:0 0 16px;padding-left:20px;">
				<li>Go to <strong>Meta Business Manager → Settings → People</strong></li>
				<li>Click <strong>Add People</strong> and enter our email address</li>
				<li>Set role to <strong>Analyst</strong> (view-only — we cannot change anything)</li>
				<li>Under <strong>Ad Accounts</strong>, grant access to all relevant accounts</li>
				<li>Click <strong>Invite</strong> and let us know when done</li>
			</ol>';
		} elseif ( 'google' === $platform ) {
			$instructions = '
			<ol style="color:#4a4a4a;font-size:15px;line-height:1.8;margin:0 0 16px;padding-left:20px;">
				<li>Sign into <strong>Google Ads → Tools &amp; Settings → Account access</strong></li>
				<li>Click the blue <strong>+</strong> button to invite a user</li>
				<li>Enter our email and set access level to <strong>Read only</strong></li>
				<li>Click <strong>Send invitation</strong> and let us know when sent</li>
			</ol>';
		} else {
			$instructions = '<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 16px;">Please reply to this email and we\'ll send you platform-specific instructions.</p>';
		}

		$body = '
		<h2 style="color:#1a1a2e;font-family:Georgia,serif;font-size:26px;margin:0 0 16px;">Grant ' . $platform_name . ' Access to Complete Your Audit</h2>
		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">To provide a complete picture of your ad account and run deeper compliance analysis, we need <strong>read-only</strong> access to your ' . $platform_name . ' ad accounts. We cannot make changes — only view data.</p>

		<div style="background:#f8f6f0;border:1px solid #ddd;border-radius:8px;padding:24px;margin:0 0 32px;">
			<p style="color:#1a1a2e;font-size:15px;font-weight:600;margin:0 0 16px;">How to grant access:</p>
			' . $instructions . '
		</div>

		<div style="text-align:center;margin:0 0 32px;">
			<a href="' . $resume_url . '" style="background:#1a1a2e;color:#ffffff;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">Return to My Audit</a>
		</div>';

		return $this->wrap( $body, "Grant {$platform_name} Ad Account Access" );
	}

	private function tpl_report_ready( $session, $report_token ) {
		$report_url = esc_url( rest_url( 'bwg/v1/ai/report/' . rawurlencode( $report_token ) ) );
		$resume_url = esc_url( $this->resume_url( $session ) );

		$body = '
		<h2 style="color:#1a1a2e;font-family:Georgia,serif;font-size:26px;margin:0 0 16px;">Your Ads Intelligence Report Is Ready</h2>
		<p style="color:#4a4a4a;font-size:15px;line-height:1.7;margin:0 0 24px;">Your full audit report has been generated. It includes your compliance risk score, estimated wasted spend, a platform snapshot, and a prioritized list of the highest-impact fixes — with a built-in switcher to marketing, compliance, agency, and admissions views of the same audit, and a PDF download.</p>

		<div style="text-align:center;margin:0 0 32px;">
			<a href="' . $report_url . '" style="background:#1a1a2e;color:#ffffff;display:inline-block;font-family:Arial,sans-serif;font-size:16px;font-weight:600;padding:14px 32px;border-radius:6px;text-decoration:none;">View My Report</a>
		</div>

		<p style="color:#4a4a4a;font-size:14px;line-height:1.7;margin:0 0 24px;">You can also <a href="' . $resume_url . '" style="color:#1a1a2e;">return to your audit</a> to review ad details, add more accounts, or request platform access.</p>

		<p style="color:#aaa;font-size:12px;margin:0;">This report link is private and unique to your account. It expires in 90 days.</p>';

		return $this->wrap( $body, 'Your Ads Intelligence Report Is Ready' );
	}

	// -------------------------------------------------------------------------
	// HTML shell — table-based layout, inline CSS, no external dependencies
	// -------------------------------------------------------------------------

	private function wrap( $content, $preview_text = '' ) {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$year      = gmdate( 'Y' );
		$preview   = $preview_text
			? '<div style="display:none;max-height:0;overflow:hidden;font-size:1px;color:#f0ede8;">' . esc_html( $preview_text ) . '</div>'
			: '';

		return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . esc_html( $preview_text ) . '</title>
</head>
<body style="margin:0;padding:0;background:#f0ede8;font-family:Arial,Helvetica,sans-serif;">
' . $preview . '
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f0ede8;">
  <tr>
    <td align="center" style="padding:40px 16px;">
      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="580" style="max-width:580px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <!-- Header -->
        <tr>
          <td style="background:#1a1a2e;padding:24px 40px;">
            <p style="color:#c9a84c;font-family:Georgia,serif;font-size:18px;font-weight:700;margin:0;letter-spacing:0.04em;">' . $site_name . '</p>
            <p style="color:#6680aa;font-size:11px;margin:4px 0 0;letter-spacing:0.12em;text-transform:uppercase;">Ads Intelligence</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:40px;">
            ' . $content . '
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f8f6f0;padding:20px 40px;border-top:1px solid #e8e4de;">
            <p style="color:#aaa;font-size:11px;line-height:1.6;margin:0;">&copy; ' . $year . ' ' . $site_name . '. You received this email because you submitted an ads audit request on our website.</p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>';
	}
}
