<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bwg_ai_render_session_detail( $session_id ) {
	global $wpdb;
	$p          = $wpdb->prefix . 'bwg_ai_';
	$session_id = absint( $session_id );

	$session = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM `{$p}sessions` WHERE id = %d",
		$session_id
	), ARRAY_A );

	if ( ! $session ) {
		echo '<div class="wrap"><p>' . esc_html__( 'Session not found.', 'bwg-ads-intel' ) . '</p></div>';
		return;
	}

	// Handle manual status override.
	if (
		isset( $_POST['bwg_ai_override_status'] ) &&
		check_admin_referer( 'bwg_ai_session_override_' . $session_id )
	) {
		$new = sanitize_text_field( wp_unslash( $_POST['bwg_ai_override_status'] ) );
		if ( in_array( $new, [ 'active', 'complete', 'archived' ], true ) ) {
			$wpdb->update( "{$p}sessions", [ 'status' => $new ], [ 'id' => $session_id ], [ '%s' ], [ '%d' ] );
			$session['status'] = $new;
			echo '<div class="notice notice-success is-dismissible"><p>Status updated to <strong>' . esc_html( $new ) . '</strong>.</p></div>';
		}
	}

	$discovered  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$p}discovered` WHERE session_id = %d LIMIT 1", $session_id ), ARRAY_A );
	$ads         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$p}ads` WHERE session_id = %d ORDER BY id DESC", $session_id ), ARRAY_A );
	$access_rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$p}access` WHERE session_id = %d", $session_id ), ARRAY_A );
	$reports     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$p}reports` WHERE session_id = %d ORDER BY generated_at DESC", $session_id ), ARRAY_A );
	$audit_log   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$p}audit_log` WHERE session_id = %d ORDER BY created_at DESC LIMIT 50", $session_id ), ARRAY_A );
	?>
	<div class="wrap bwg-ai-wrap">
		<h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bwg-ai' ) ); ?>">&larr; Sessions</a>
			&nbsp;&mdash;&nbsp; Session #<?php echo absint( $session_id ); ?>
		</h1>
		<hr class="wp-header-end">

		<!-- Session Header -->
		<div class="bwg-ai-card" style="max-width:900px;">
			<table class="form-table" style="margin:0;">
				<tr>
					<th>Email</th><td><?php echo esc_html( $session['email'] ); ?></td>
					<th>URL</th><td><a href="<?php echo esc_url( $session['website_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $session['website_url'] ); ?></a></td>
				</tr>
				<tr>
					<th>Status</th>
					<td><span class="bwg-ai-status-<?php echo sanitize_html_class( $session['status'] ); ?>"><?php echo esc_html( $session['status'] ); ?></span></td>
					<th>Step</th><td><?php echo absint( $session['step_completed'] ); ?> / 5</td>
				</tr>
				<tr>
					<th>Access Code</th><td><code><?php echo esc_html( $session['access_code'] ); ?></code></td>
					<th>Meta Ad Library</th><td><?php echo esc_html( BWG_AI_Meta_Ad_Library::is_configured() ? 'API configured' : 'Manual entry (no token)' ); ?></td>
				</tr>
				<tr>
					<th>Created</th><td><?php echo esc_html( $session['created_at'] ); ?></td>
					<th>Updated</th><td><?php echo esc_html( $session['updated_at'] ); ?></td>
				</tr>
			</table>

			<form method="post" style="margin-top:12px;padding-top:12px;border-top:1px solid #eee;">
				<?php wp_nonce_field( 'bwg_ai_session_override_' . $session_id ); ?>
				<label style="font-weight:600;margin-right:8px;">Override Status:</label>
				<select name="bwg_ai_override_status">
					<?php foreach ( [ 'active', 'complete', 'archived' ] as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $session['status'], $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button" style="margin-left:8px;">Update</button>
			</form>
		</div>

		<?php if ( $discovered ) : ?>
		<!-- Discovery Data -->
		<h2>Phase 1 Discovery</h2>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:900px;margin-bottom:16px;">

			<div class="bwg-ai-card">
				<h3>Business Info</h3>
				<table style="width:100%;font-size:13px;border-collapse:collapse;">
					<?php
					$biz = [
						'Name'        => $discovered['business_name'],
						'Address'     => $discovered['business_address'],
						'Phone'       => $discovered['business_phone'],
						'GBP Place ID'=> $discovered['gbp_place_id'],
						'Rating'      => $discovered['gbp_rating'] ? $discovered['gbp_rating'] . ' (' . $discovered['gbp_review_count'] . ' reviews)' : '',
						'LegitScript' => $discovered['legitscript_status'],
					];
					foreach ( $biz as $lbl => $val ) :
						if ( ! $val ) continue; ?>
						<tr>
							<th style="text-align:left;padding:3px 8px 3px 0;color:#666;font-weight:normal;white-space:nowrap;"><?php echo esc_html( $lbl ); ?></th>
							<td style="padding:3px 0;"><?php echo esc_html( $val ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>

			<div class="bwg-ai-card">
				<h3>Social &amp; Pixels</h3>
				<table style="width:100%;font-size:13px;border-collapse:collapse;">
					<?php
					$socials = [
						'Facebook'  => $discovered['social_facebook_url'],
						'Instagram' => $discovered['social_instagram_url'],
						'LinkedIn'  => $discovered['social_linkedin_url'],
						'TikTok'    => $discovered['social_tiktok_url'],
						'YouTube'   => $discovered['social_youtube_url'],
					];
					foreach ( $socials as $lbl => $url ) :
						if ( ! $url ) continue; ?>
						<tr>
							<th style="text-align:left;padding:3px 8px 3px 0;color:#666;font-weight:normal;white-space:nowrap;"><?php echo esc_html( $lbl ); ?></th>
							<td style="padding:3px 0;"><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" style="word-break:break-all;"><?php echo esc_html( $url ); ?></a></td>
						</tr>
					<?php endforeach;

					$pixels = [
						'Meta Pixel'     => $discovered['pixel_meta_id'],
						'GTM'            => $discovered['pixel_gtm_id'],
						'GA4'            => $discovered['pixel_ga4_id'],
						'TikTok Pixel'   => $discovered['pixel_tiktok_id'],
						'LinkedIn Pixel' => $discovered['pixel_linkedin_id'],
					];
					foreach ( $pixels as $lbl => $pid ) :
						if ( ! $pid ) continue; ?>
						<tr>
							<th style="text-align:left;padding:3px 8px 3px 0;color:#666;font-weight:normal;white-space:nowrap;"><?php echo esc_html( $lbl ); ?></th>
							<td style="padding:3px 0;"><code><?php echo esc_html( $pid ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>

			<div class="bwg-ai-card">
				<h3>Domain / WHOIS</h3>
				<table style="width:100%;font-size:13px;border-collapse:collapse;">
					<?php
					$whois = [
						'Registrar' => $discovered['whois_registrar'],
						'Created'   => $discovered['whois_created_at'],
						'Expires'   => $discovered['whois_expires_at'],
					];
					foreach ( $whois as $lbl => $val ) : ?>
						<tr>
							<th style="text-align:left;padding:3px 8px 3px 0;color:#666;font-weight:normal;"><?php echo esc_html( $lbl ); ?></th>
							<td style="padding:3px 0;"><?php echo esc_html( $val ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>

			<div class="bwg-ai-card">
				<h3>Discovery Flags</h3>
				<?php
				$flags = json_decode( $discovered['discovery_flags'] ?? '[]', true );
				if ( ! empty( $flags ) ) :
					echo '<ul style="margin:0;padding-left:18px;font-size:13px;">';
					foreach ( $flags as $f ) {
						$msg = is_array( $f ) ? ( $f['message'] ?? wp_json_encode( $f ) ) : (string) $f;
						echo '<li>' . esc_html( $msg ) . '</li>';
					}
					echo '</ul>';
				else : ?>
					<p style="color:#2d6a4f;font-size:13px;margin:0;">No flags.</p>
				<?php endif; ?>
			</div>

		</div>
		<?php endif; ?>

		<!-- Ads Gallery -->
		<?php if ( $ads ) : ?>
		<h2>Ads (<?php echo count( $ads ); ?>)</h2>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;max-width:900px;margin-bottom:24px;">
			<?php foreach ( $ads as $ad ) :
				$flags      = json_decode( $ad['compliance_flags'] ?? '[]', true );
				$flag_count = is_array( $flags ) ? count( $flags ) : 0;
				$high_count = is_array( $flags ) ? count( array_filter( $flags, fn( $f ) => ( $f['severity'] ?? '' ) === 'high' ) ) : 0;
			?>
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;overflow:hidden;font-size:13px;">
				<?php if ( $ad['screenshot_path'] || $ad['ad_image_url'] ) : ?>
					<img src="<?php echo esc_url( $ad['screenshot_path'] ?: $ad['ad_image_url'] ); ?>"
					     alt="" style="width:100%;height:130px;object-fit:cover;display:block;">
				<?php elseif ( ! empty( $ad['ad_snapshot_url'] ) ) : ?>
					<div style="height:60px;background:#f5f4f0;display:flex;align-items:center;justify-content:center;font-size:12px;">
						<a href="<?php echo esc_url( $ad['ad_snapshot_url'] ); ?>" target="_blank" rel="noopener">View Ad Snapshot &#8599;</a>
					</div>
				<?php else : ?>
					<div style="height:60px;background:#f5f4f0;display:flex;align-items:center;justify-content:center;color:#999;font-size:12px;">No screenshot</div>
				<?php endif; ?>
				<div style="padding:10px;">
					<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
						<span style="font-size:11px;font-weight:600;background:#0d6e6e;color:#fff;padding:2px 7px;border-radius:3px;"><?php echo esc_html( $ad['platform'] ); ?></span>
						<span style="font-size:11px;color:<?php echo $ad['user_confirmed'] ? '#2d6a4f' : '#d97706'; ?>;">
							<?php echo $ad['user_confirmed'] ? 'Confirmed' : 'Unconfirmed'; ?>
						</span>
					</div>
					<?php if ( $ad['ad_copy'] ) : ?>
						<p style="margin:0 0 6px;line-height:1.4;color:#3a3d4a;"><?php echo esc_html( mb_substr( $ad['ad_copy'], 0, 110 ) . ( mb_strlen( $ad['ad_copy'] ) > 110 ? '…' : '' ) ); ?></p>
					<?php endif; ?>
					<?php if ( $flag_count > 0 ) : ?>
						<p style="margin:4px 0 0;color:<?php echo $high_count > 0 ? '#c0392b' : '#d97706'; ?>;">
							&#9888; <?php echo esc_html( $flag_count . ' flag' . ( $flag_count !== 1 ? 's' : '' ) . ( $high_count > 0 ? ', ' . $high_count . ' high' : '' ) ); ?>
						</p>
					<?php endif; ?>
					<?php if ( $ad['spend_range'] ) : ?>
						<p style="margin:3px 0 0;font-size:11px;color:#666;">Spend: <?php echo esc_html( $ad['spend_range'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<!-- Access Status -->
		<?php if ( $access_rows ) : ?>
		<h2>Platform Access</h2>
		<table class="wp-list-table widefat fixed striped" style="max-width:800px;margin-bottom:20px;">
			<thead><tr>
				<th>Platform</th><th>Status</th><th>Email Sent</th><th>Access Granted</th><th>Export Uploaded</th>
			</tr></thead>
			<tbody>
				<?php foreach ( $access_rows as $ar ) : ?>
				<tr>
					<td><?php echo esc_html( $ar['platform'] ); ?></td>
					<td><?php echo esc_html( $ar['access_status'] ); ?></td>
					<td><?php echo esc_html( $ar['grant_email_sent_at'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $ar['access_granted_at'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $ar['export_uploaded_at'] ?: '—' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- Reports -->
		<?php if ( $reports ) : ?>
		<h2>Reports</h2>
		<table class="wp-list-table widefat fixed striped" style="max-width:800px;margin-bottom:20px;">
			<thead><tr><th>Type</th><th>Token</th><th>Generated</th><th>Emailed</th><th>Link</th></tr></thead>
			<tbody>
				<?php foreach ( $reports as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['audience_type'] ); ?></td>
					<td><code style="font-size:11px;"><?php echo esc_html( $r['report_token'] ); ?></code></td>
					<td><?php echo esc_html( $r['generated_at'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $r['emailed_at'] ?: '—' ); ?></td>
					<td><a href="<?php echo esc_url( rest_url( 'bwg/v1/ai/report/' . $r['report_token'] ) ); ?>" target="_blank" rel="noopener">View</a></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!-- Audit Trail -->
		<h2>Audit Trail</h2>
		<table class="wp-list-table widefat fixed striped" style="max-width:900px;margin-bottom:20px;">
			<thead><tr><th style="width:160px;">Time</th><th style="width:180px;">Action</th><th>Message</th></tr></thead>
			<tbody>
				<?php if ( $audit_log ) :
					foreach ( $audit_log as $entry ) : ?>
					<tr>
						<td style="font-size:12px;white-space:nowrap;"><?php echo esc_html( $entry['created_at'] ); ?></td>
						<td style="font-size:12px;"><code><?php echo esc_html( $entry['action'] ); ?></code></td>
						<td style="font-size:12px;"><?php echo esc_html( $entry['message'] ); ?></td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="3" style="color:#666;font-size:12px;">No audit log entries.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>

	</div>
	<?php
}
