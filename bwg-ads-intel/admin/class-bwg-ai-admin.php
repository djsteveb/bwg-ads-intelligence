<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Admin {

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	public function register_menu() {
		add_menu_page(
			__( 'Ads Intelligence', 'bwg-ads-intel' ),
			__( 'Ads Intelligence', 'bwg-ads-intel' ),
			'manage_options',
			'bwg-ai',
			[ $this, 'render_sessions_page' ],
			'dashicons-search',
			30
		);

		add_submenu_page(
			'bwg-ai',
			__( 'Sessions', 'bwg-ads-intel' ),
			__( 'Sessions', 'bwg-ads-intel' ),
			'manage_options',
			'bwg-ai',
			[ $this, 'render_sessions_page' ]
		);

		add_submenu_page(
			'bwg-ai',
			__( 'Settings', 'bwg-ads-intel' ),
			__( 'Settings', 'bwg-ads-intel' ),
			'manage_options',
			'bwg-ai-settings',
			[ $this, 'render_settings_page' ]
		);

		add_submenu_page(
			'bwg-ai',
			__( 'Storage', 'bwg-ads-intel' ),
			__( 'Storage', 'bwg-ads-intel' ),
			'manage_options',
			'bwg-ai-storage',
			[ $this, 'render_storage_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public function register_settings() {
		// General.
		register_setting( 'bwg_ai_general', 'bwg_ai_booking_url', [ 'sanitize_callback' => 'sanitize_url' ] );

		// Email.
		register_setting( 'bwg_ai_email', 'bwg_ai_email_provider',    [ 'sanitize_callback' => [ $this, 'sanitize_email_provider' ] ] );
		register_setting( 'bwg_ai_email', 'bwg_ai_from_name',         [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'bwg_ai_email', 'bwg_ai_from_email',        [ 'sanitize_callback' => 'sanitize_email' ] );
		register_setting( 'bwg_ai_email', 'bwg_ai_sendgrid_api_key',  [ 'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ] ] );
		register_setting( 'bwg_ai_email', 'bwg_ai_postmark_api_key',  [ 'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ] ] );

		// API Keys.
		register_setting( 'bwg_ai_api', 'bwg_ai_google_places_key',   [ 'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ] ] );
		register_setting( 'bwg_ai_api', 'bwg_ai_captcha_site_key',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'bwg_ai_api', 'bwg_ai_captcha_secret_key',  [ 'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ] ] );
		register_setting( 'bwg_ai_api', 'bwg_ai_meta_ad_library_token', [ 'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ] ] );
		register_setting( 'bwg_ai_api', 'bwg_ai_screenshot_api_url',   [ 'sanitize_callback' => 'sanitize_url' ] );
		register_setting( 'bwg_ai_api', 'bwg_ai_screenshot_api_key',   [ 'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ] ] );
		register_setting( 'bwg_ai_api', 'bwg_ai_claude_api_key',       [ 'sanitize_callback' => [ $this, 'sanitize_and_encrypt_secret' ] ] );

		// Storage / Maintenance.
		register_setting( 'bwg_ai_storage_settings', 'bwg_ai_storage_warning_gb',        [ 'sanitize_callback' => 'absint' ] );
		register_setting( 'bwg_ai_storage_settings', 'bwg_ai_audit_log_retention_days',  [ 'sanitize_callback' => 'absint' ] );
		register_setting( 'bwg_ai_storage_settings', 'bwg_ai_screenshot_retention_days', [ 'sanitize_callback' => 'absint' ] );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		$our_hooks = [
			'toplevel_page_bwg-ai',
			'ads-intelligence_page_bwg-ai-settings',
			'ads-intelligence_page_bwg-ai-storage',
		];
		if ( ! in_array( $hook, $our_hooks, true ) ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', $this->inline_css() );
	}

	private function inline_css() {
		return '
			.bwg-ai-wrap .bwg-ai-card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:16px;}
			.bwg-ai-wrap .bwg-ai-card h3{margin-top:0;font-size:14px;font-weight:600;color:#1d2327;}
			.bwg-ai-flag-high{color:#c0392b;font-weight:600;}
			.bwg-ai-flag-medium{color:#d97706;font-weight:600;}
			.bwg-ai-flag-low{color:#2d6a4f;font-weight:600;}
			.bwg-ai-status-active{color:#2d6a4f;}
			.bwg-ai-status-complete{color:#0d6e6e;}
			.bwg-ai-status-archived{color:#6b7080;}
			.bwg-ai-usage-bar{background:#e0e0e0;border-radius:4px;height:16px;overflow:hidden;margin:8px 0;}
			.bwg-ai-usage-bar .fill{height:100%;border-radius:4px;background:#0d6e6e;}
			.bwg-ai-usage-bar .fill.warning{background:#d97706;}
			.bwg-ai-usage-bar .fill.critical{background:#c0392b;}
			.bwg-ai-bar-chart{display:flex;align-items:flex-end;gap:6px;height:100px;margin:8px 0;}
			.bwg-ai-bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;}
			.bwg-ai-bar-wrap .bar{width:100%;background:#0d6e6e;border-radius:3px 3px 0 0;min-height:2px;}
			.bwg-ai-bar-wrap .bar-lbl{font-size:10px;color:#666;margin-top:4px;}
			.bwg-ai-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}
			.bwg-ai-modal-bg.open{display:flex;}
			.bwg-ai-modal{background:#fff;border-radius:8px;padding:24px;max-width:400px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.2);}
			.bwg-ai-modal h3{margin-top:0;}
		';
	}

	// -------------------------------------------------------------------------
	// Plugin action links
	// -------------------------------------------------------------------------

	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=bwg-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'bwg-ads-intel' ) . '</a>'
		);
		return $links;
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	public function render_sessions_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bwg-ads-intel' ) );
		}

		$action     = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$session_id = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;

		if ( 'detail' === $action && $session_id > 0 ) {
			require_once BWG_AI_DIR . 'admin/partials/admin-detail.php';
			bwg_ai_render_session_detail( $session_id );
		} else {
			require_once BWG_AI_DIR . 'admin/partials/admin-list.php';
			bwg_ai_render_sessions_list();
		}
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bwg-ads-intel' ) );
		}
		require_once BWG_AI_DIR . 'admin/partials/admin-settings.php';
		bwg_ai_render_settings_page();
	}

	public function render_storage_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bwg-ads-intel' ) );
		}

		$notice = '';

		if ( isset( $_POST['bwg_ai_storage_action'] ) && check_admin_referer( 'bwg_ai_storage_action' ) ) {
			$sa   = sanitize_text_field( wp_unslash( $_POST['bwg_ai_storage_action'] ) );
			$from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
			$to   = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );

			if ( 'delete' === $sa ) {
				$result = BWG_AI_Screenshot_Store::prune_range( $from ?: null, $to ?: null );
				$notice = '<div class="notice notice-success"><p>' . (int) $result['deleted'] . ' screenshot(s) deleted, freeing ' . esc_html( size_format( $result['bytes_freed'] ) ) . '.</p></div>';
				BWG_AI_Session::log( null, 'storage_manual_delete', "Deleted {$result['deleted']} screenshots in range {$from}..{$to}." );
			} elseif ( 'delete_older_than' === $sa ) {
				$days   = absint( $_POST['days'] ?? 0 );
				$result = $days > 0 ? BWG_AI_Screenshot_Store::prune_older_than( $days ) : [ 'deleted' => 0, 'bytes_freed' => 0 ];
				$notice = '<div class="notice notice-success"><p>' . (int) $result['deleted'] . ' screenshot(s) older than ' . (int) $days . ' days deleted, freeing ' . esc_html( size_format( $result['bytes_freed'] ) ) . '.</p></div>';
				BWG_AI_Session::log( null, 'storage_manual_delete', "Deleted {$result['deleted']} screenshots older than {$days} days." );
			}
		}

		// Export download is a GET so it can trigger a file download directly (see handle_storage_export()).
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=bwg_ai_storage_export' ), 'bwg_ai_storage_export' );

		$stats      = BWG_AI_Screenshot_Store::stats();
		$total_gb   = round( $stats['total_bytes'] / 1e9, 3 );
		$weekly_gb  = round( $stats['weekly_bytes'] / 1e9, 3 );
		$threshold  = absint( get_option( 'bwg_ai_storage_warning_gb', 10 ) );
		$pct        = $threshold > 0 ? min( 100, round( ( $total_gb / $threshold ) * 100 ) ) : 0;
		$fill_class = $pct >= 90 ? 'critical' : ( $pct >= 70 ? 'warning' : '' );
		$retention  = absint( get_option( 'bwg_ai_screenshot_retention_days', 0 ) );
		?>
		<div class="wrap bwg-ai-wrap">
			<h1>Ads Intelligence — Storage</h1>
			<?php echo wp_kses_post( $notice ); ?>

			<p style="max-width:700px;color:#50575e;">
				Screenshots are stored locally on this server (<code>wp-content/uploads/bwg-ai-screenshots/</code>,
				access-blocked from direct web requests) and served only through short-lived signed links.
				Meta ads link to Meta's own hosted snapshot instead and don't count toward this total.
				<?php if ( $retention > 0 ) : ?>
					Auto-delete is <strong>on</strong>: screenshots older than <?php echo esc_html( $retention ); ?> days are removed by daily maintenance.
				<?php else : ?>
					Auto-delete is <strong>off</strong> — screenshots are kept indefinitely unless deleted below. Set a retention window in <a href="<?php echo esc_url( admin_url( 'admin.php?page=bwg-ai-settings&tab=storage' ) ); ?>">Settings &rarr; Storage</a>.
				<?php endif; ?>
			</p>

			<div class="bwg-ai-card" style="max-width:700px;">
				<h3>Disk Usage</h3>
				<p>
					<strong><?php echo esc_html( $total_gb ); ?> GB</strong> total
					&mdash; <strong><?php echo esc_html( $weekly_gb ); ?> GB</strong> in the last 7 days
					<?php if ( $threshold ) : ?>(warning threshold: <?php echo esc_html( $threshold ); ?> GB)<?php endif; ?>
				</p>
				<div class="bwg-ai-usage-bar">
					<div class="fill <?php echo esc_attr( $fill_class ); ?>" style="width:<?php echo esc_attr( $pct ); ?>%;"></div>
				</div>
				<p style="font-size:12px;color:#666;margin:0;"><?php echo esc_html( $pct ); ?>% of warning threshold</p>

				<?php
				$breakdown = $stats['weekly_breakdown'];
				$max_bytes = max( array_column( $breakdown, 'bytes' ) ) ?: 1;
				?>
				<h3 style="margin-top:20px;">Last 7 Days</h3>
				<div class="bwg-ai-bar-chart">
					<?php foreach ( $breakdown as $day ) :
						$h   = min( 100, round( ( $day['bytes'] / $max_bytes ) * 100 ) );
						$lbl = gmdate( 'D', strtotime( $day['date'] ) );
						$mb  = round( $day['bytes'] / 1e6, 1 );
					?>
					<div class="bwg-ai-bar-wrap" title="<?php echo esc_attr( $day['date'] . ': ' . $mb . ' MB' ); ?>">
						<div class="bar" style="height:<?php echo esc_attr( $h ); ?>%;"></div>
						<div class="bar-lbl"><?php echo esc_html( $lbl ); ?></div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="bwg-ai-card" style="max-width:700px;">
				<h3>Backup / Export Screenshots</h3>
				<p style="font-size:12px;color:#666;margin-top:0;">Downloads a ZIP of the screenshot files in the selected range plus a CSV manifest (session, platform, ad, path, size, date). Leave both blank to export everything.</p>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bwg_ai_storage_export">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'bwg_ai_storage_export' ) ); ?>">
					<table class="form-table" style="margin:0;">
						<tr><th style="padding:6px 0;"><label for="ef">From</label></th><td><input type="date" id="ef" name="date_from" class="regular-text"></td></tr>
						<tr><th style="padding:6px 0;"><label for="et">To</label></th><td><input type="date" id="et" name="date_to" class="regular-text" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></td></tr>
					</table>
					<p style="margin-top:12px;"><button type="submit" class="button button-primary">Download ZIP Backup</button></p>
				</form>
			</div>

			<div class="bwg-ai-card" style="max-width:700px;">
				<h3 style="color:#c0392b;">Delete Screenshots by Date Range</h3>
				<form method="post" id="bwg-ai-del-form">
					<?php wp_nonce_field( 'bwg_ai_storage_action' ); ?>
					<input type="hidden" name="bwg_ai_storage_action" value="delete">
					<table class="form-table" style="margin:0;">
						<tr><th style="padding:6px 0;"><label for="df">From</label></th><td><input type="date" id="df" name="date_from" class="regular-text"></td></tr>
						<tr><th style="padding:6px 0;"><label for="dt">To</label></th><td><input type="date" id="dt" name="date_to" class="regular-text" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></td></tr>
					</table>
					<p style="margin-top:12px;"><button type="button" class="button" onclick="document.getElementById('bwg-ai-del-modal').classList.add('open')">Delete Range&hellip;</button></p>
				</form>
			</div>

			<div class="bwg-ai-card" style="max-width:700px;">
				<h3 style="color:#c0392b;">Delete Screenshots Older Than N Days</h3>
				<form method="post" id="bwg-ai-del-older-form">
					<?php wp_nonce_field( 'bwg_ai_storage_action' ); ?>
					<input type="hidden" name="bwg_ai_storage_action" value="delete_older_than">
					<label for="days">Older than</label>
					<input type="number" id="days" name="days" min="1" value="90" class="small-text">
					days
					<p style="margin-top:12px;"><button type="button" class="button" onclick="document.getElementById('bwg-ai-del-older-modal').classList.add('open')">Delete&hellip;</button></p>
				</form>
			</div>

			<div class="bwg-ai-modal-bg" id="bwg-ai-del-modal">
				<div class="bwg-ai-modal">
					<h3 style="color:#c0392b;">Confirm Delete</h3>
					<p>This permanently removes all screenshots in the selected date range and cannot be undone. Consider downloading a backup first.</p>
					<p>
						<button type="button" class="button button-primary" style="background:#c0392b;border-color:#9b2a1e;"
							onclick="document.getElementById('bwg-ai-del-form').submit()">Yes, Delete</button>
						&nbsp;
						<button type="button" class="button" onclick="document.getElementById('bwg-ai-del-modal').classList.remove('open')">Cancel</button>
					</p>
				</div>
			</div>
			<div class="bwg-ai-modal-bg" id="bwg-ai-del-older-modal">
				<div class="bwg-ai-modal">
					<h3 style="color:#c0392b;">Confirm Delete</h3>
					<p>This permanently removes every screenshot older than the number of days entered and cannot be undone. Consider downloading a backup first.</p>
					<p>
						<button type="button" class="button button-primary" style="background:#c0392b;border-color:#9b2a1e;"
							onclick="document.getElementById('bwg-ai-del-older-form').submit()">Yes, Delete</button>
						&nbsp;
						<button type="button" class="button" onclick="document.getElementById('bwg-ai-del-older-modal').classList.remove('open')">Cancel</button>
					</p>
				</div>
			</div>
			<script>
			[ 'bwg-ai-del-modal', 'bwg-ai-del-older-modal' ].forEach( function ( id ) {
				document.getElementById( id ).addEventListener( 'click', function ( e ) {
					if ( e.target === this ) this.classList.remove( 'open' );
				} );
			} );
			</script>
		</div>
		<?php
	}

	/**
	 * admin-post.php handler for the ZIP backup download — streams the file
	 * directly rather than routing through render_storage_page() so it can
	 * send Content-Disposition: attachment headers.
	 */
	public function handle_storage_export() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'bwg_ai_storage_export' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bwg-ads-intel' ) );
		}

		$from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';

		$result = BWG_AI_Screenshot_Store::export_zip( $from ?: null, $to ?: null );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		BWG_AI_Session::log( null, 'storage_export', "Exported {$result['file_count']} screenshots ({$from}..{$to})." );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'Content-Length: ' . filesize( $result['path'] ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_readfile -- streaming a freshly built, path-validated zip.
		readfile( $result['path'] );
		wp_delete_file( $result['path'] ); // One-time download artifact — remove after streaming.
		exit;
	}

	// -------------------------------------------------------------------------
	// Cron: daily maintenance
	// -------------------------------------------------------------------------

	public function daily_maintenance() {
		if ( ! wp_doing_cron() ) {
			return;
		}

		global $wpdb;
		$p = $wpdb->prefix . 'bwg_ai_';

		// Expire resume tokens whose window has passed.
		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$p}sessions`
			 SET resume_token = '', resume_token_expires = NULL
			 WHERE resume_token_expires < %s AND resume_token <> ''",
			gmdate( 'Y-m-d H:i:s' )
		) );

		// Remove expired rate-limit bucket rows.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$p}ratelimits` WHERE expires_at < %s",
			gmdate( 'Y-m-d H:i:s' )
		) );

		// Prune audit log past retention window.
		$days = absint( get_option( 'bwg_ai_audit_log_retention_days', 90 ) );
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM `{$p}audit_log` WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
			) );
		}

		// Auto-delete screenshots past the configured retention window.
		$retention_days = absint( get_option( 'bwg_ai_screenshot_retention_days', 0 ) );
		if ( $retention_days > 0 ) {
			$result = BWG_AI_Screenshot_Store::prune_older_than( $retention_days );
			if ( $result['deleted'] > 0 ) {
				BWG_AI_Session::log( null, 'storage_auto_prune', "Daily maintenance deleted {$result['deleted']} screenshots older than {$retention_days} days, freeing " . size_format( $result['bytes_freed'] ) . '.' );
			}
		}

		// Email admin if storage warning threshold exceeded.
		$stats     = BWG_AI_Screenshot_Store::stats();
		$total_gb  = $stats['total_bytes'] / 1e9;
		$threshold = absint( get_option( 'bwg_ai_storage_warning_gb', 10 ) );
		if ( $threshold > 0 && $total_gb >= $threshold ) {
			wp_mail(
				get_option( 'admin_email' ),
				'[BWG Ads Intelligence] Storage Warning',
				sprintf(
					"Screenshot storage has reached %.2f GB (threshold: %d GB).\n\nManage storage: %s",
					$total_gb,
					$threshold,
					admin_url( 'admin.php?page=bwg-ai-storage' )
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: test email
	// -------------------------------------------------------------------------

	public function handle_test_email_ajax() {
		check_ajax_referer( 'bwg_ai_test_email' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$sent = wp_mail(
			get_option( 'admin_email' ),
			'[BWG Ads Intelligence] Test Email',
			'Your BWG Ads Intelligence email settings are working correctly.'
		);

		if ( $sent ) {
			wp_send_json_success( 'Sent to ' . get_option( 'admin_email' ) );
		} else {
			wp_send_json_error( 'wp_mail() returned false. Check SMTP settings.' );
		}
	}

	// -------------------------------------------------------------------------
	// Sanitize callbacks
	// -------------------------------------------------------------------------

	public function sanitize_email_provider( $value ) {
		$allowed = [ 'wp_mail', 'sendgrid', 'postmark' ];
		return in_array( $value, $allowed, true ) ? $value : 'wp_mail';
	}

	/**
	 * Sanitize + encrypt a secret/API key field before it's stored in wp_options.
	 * Used as the sanitize_callback for every secret option so it's never
	 * persisted in plaintext.
	 */
	public function sanitize_and_encrypt_secret( $value ) {
		return bwg_ai_encrypt_secret( sanitize_text_field( $value ) );
	}
}
