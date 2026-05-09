<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bwg_ai_render_settings_page() {
	$tab      = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
	$base_url = admin_url( 'admin.php?page=bwg-ai-settings' );
	$tabs     = [
		'general' => 'General',
		'email'   => 'Email',
		'api'     => 'API Keys',
		'storage' => 'Storage',
	];
	?>
	<div class="wrap bwg-ai-wrap">
		<h1>Ads Intelligence — Settings</h1>

		<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base_url ) ); ?>"
				   class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $tab ) {
			case 'email':
				bwg_ai_settings_email();
				break;
			case 'api':
				bwg_ai_settings_api();
				break;
			case 'storage':
				bwg_ai_settings_storage();
				break;
			default:
				bwg_ai_settings_general();
		}
		?>
	</div>
	<?php
}

function bwg_ai_settings_general() {
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'bwg_ai_general' ); ?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="bwg_ai_entityiq_url">EntityIQ URL</label></th>
				<td>
					<input type="url" id="bwg_ai_entityiq_url" name="bwg_ai_entityiq_url"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_entityiq_url', '' ) ); ?>"
					       class="regular-text" placeholder="https://entityiq.example.com">
					<p class="description">Base URL of the EntityIQ Node.js service (no trailing slash).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bwg_ai_entityiq_secret">EntityIQ Shared Secret</label></th>
				<td>
					<input type="password" id="bwg_ai_entityiq_secret" name="bwg_ai_entityiq_secret"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_entityiq_secret', '' ) ); ?>"
					       class="regular-text" autocomplete="new-password">
					<p class="description">HMAC-SHA256 secret. Must match <code>BWG_WEBHOOK_SECRET</code> in the EntityIQ <code>.env</code>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bwg_ai_booking_url">Booking / Schedule URL</label></th>
				<td>
					<input type="url" id="bwg_ai_booking_url" name="bwg_ai_booking_url"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_booking_url', '' ) ); ?>"
					       class="regular-text" placeholder="https://calendly.com/…">
					<p class="description">Used in report CTAs and drip email footers.</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

function bwg_ai_settings_email() {
	$provider = get_option( 'bwg_ai_email_provider', 'wp_mail' );
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'bwg_ai_email' ); ?>
		<table class="form-table">
			<tr>
				<th scope="row">Email Provider</th>
				<td>
					<?php foreach ( [ 'wp_mail' => 'WordPress (wp_mail)', 'sendgrid' => 'SendGrid', 'postmark' => 'Postmark' ] as $val => $lbl ) : ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="bwg_ai_email_provider" value="<?php echo esc_attr( $val ); ?>" <?php checked( $provider, $val ); ?>>
							<?php echo esc_html( $lbl ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description">wp_mail honours any SMTP plugin already installed.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bwg_ai_from_name">From Name</label></th>
				<td>
					<input type="text" id="bwg_ai_from_name" name="bwg_ai_from_name"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_from_name', get_bloginfo( 'name' ) ) ); ?>"
					       class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bwg_ai_from_email">From Email</label></th>
				<td>
					<input type="email" id="bwg_ai_from_email" name="bwg_ai_from_email"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_from_email', get_option( 'admin_email' ) ) ); ?>"
					       class="regular-text">
				</td>
			</tr>
			<tr id="bwg-row-sendgrid" style="<?php echo 'sendgrid' !== $provider ? 'display:none;' : ''; ?>">
				<th scope="row"><label for="bwg_ai_sendgrid_api_key">SendGrid API Key</label></th>
				<td>
					<input type="password" id="bwg_ai_sendgrid_api_key" name="bwg_ai_sendgrid_api_key"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_sendgrid_api_key', '' ) ); ?>"
					       class="regular-text" autocomplete="new-password">
				</td>
			</tr>
			<tr id="bwg-row-postmark" style="<?php echo 'postmark' !== $provider ? 'display:none;' : ''; ?>">
				<th scope="row"><label for="bwg_ai_postmark_api_key">Postmark API Key</label></th>
				<td>
					<input type="password" id="bwg_ai_postmark_api_key" name="bwg_ai_postmark_api_key"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_postmark_api_key', '' ) ); ?>"
					       class="regular-text" autocomplete="new-password">
				</td>
			</tr>
			<tr>
				<th scope="row">Test Email</th>
				<td>
					<button type="button" class="button" id="bwg-test-email-btn">Send Test Email</button>
					<span id="bwg-test-email-msg" style="margin-left:10px;font-size:13px;"></span>
					<p class="description">Sends a test to <strong><?php echo esc_html( get_option( 'admin_email' ) ); ?></strong>.</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<script>
	(function () {
		var radios = document.querySelectorAll('input[name="bwg_ai_email_provider"]');
		radios.forEach(function (r) {
			r.addEventListener('change', function () {
				document.getElementById('bwg-row-sendgrid').style.display = this.value === 'sendgrid' ? '' : 'none';
				document.getElementById('bwg-row-postmark').style.display = this.value === 'postmark' ? '' : 'none';
			});
		});

		document.getElementById('bwg-test-email-btn').addEventListener('click', function () {
			var msg = document.getElementById('bwg-test-email-msg');
			msg.textContent = 'Sending…';
			msg.style.color = '';
			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=bwg_ai_test_email&_wpnonce=<?php echo esc_js( wp_create_nonce( 'bwg_ai_test_email' ) ); ?>'
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				msg.textContent = data.success ? '✓ ' + data.data : '✗ ' + (data.data || 'Unknown error');
				msg.style.color = data.success ? '#2d6a4f' : '#c0392b';
			})
			.catch(function () {
				msg.textContent = 'Request failed.';
				msg.style.color = '#c0392b';
			});
		});
	})();
	</script>
	<?php
}

function bwg_ai_settings_api() {
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'bwg_ai_api' ); ?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="bwg_ai_google_places_key">Google Places API Key</label></th>
				<td>
					<input type="password" id="bwg_ai_google_places_key" name="bwg_ai_google_places_key"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_google_places_key', '' ) ); ?>"
					       class="regular-text" autocomplete="new-password">
					<p class="description">Used for GBP lookup in Phase 1 Discovery. Restrict to Places API only.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bwg_ai_captcha_site_key">Cloudflare Turnstile Site Key</label></th>
				<td>
					<input type="text" id="bwg_ai_captcha_site_key" name="bwg_ai_captcha_site_key"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_captcha_site_key', '' ) ); ?>"
					       class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bwg_ai_captcha_secret_key">Cloudflare Turnstile Secret Key</label></th>
				<td>
					<input type="password" id="bwg_ai_captcha_secret_key" name="bwg_ai_captcha_secret_key"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_captcha_secret_key', '' ) ); ?>"
					       class="regular-text" autocomplete="new-password">
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

function bwg_ai_settings_storage() {
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'bwg_ai_storage_settings' ); ?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="bwg_ai_storage_warning_gb">Storage Warning Threshold (GB)</label></th>
				<td>
					<input type="number" id="bwg_ai_storage_warning_gb" name="bwg_ai_storage_warning_gb"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_storage_warning_gb', 10 ) ); ?>"
					       min="1" class="small-text">
					<p class="description">Daily maintenance emails the admin when screenshot storage exceeds this threshold.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bwg_ai_audit_log_retention_days">Audit Log Retention (days)</label></th>
				<td>
					<input type="number" id="bwg_ai_audit_log_retention_days" name="bwg_ai_audit_log_retention_days"
					       value="<?php echo esc_attr( get_option( 'bwg_ai_audit_log_retention_days', 90 ) ); ?>"
					       min="7" class="small-text">
					<p class="description">Audit log rows older than this are pruned by daily maintenance. Minimum 7 days.</p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}
