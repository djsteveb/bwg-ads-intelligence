<?php
/**
 * Audience Report Template (5 audience views — see BWG_AI_Report::AUDIENCES)
 * Rendered by GET /report/{token} when Accept header includes text/html.
 * $report_data array is extracted from the reports row before include.
 *
 * Available variables (extracted from $report_data by class-bwg-ai-rest.php):
 *   $business_name, $website_url, $risk_score, $wasted_spend,
 *   $top_actions, $platform_snapshot, $whats_working, $flag_counts,
 *   $total_ads, $audience, $audience_label, $audience_data,
 *   $generated_at, $report_token, $sibling_reports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$schedule_url = esc_url( get_option( 'bwg_ai_booking_url', '' ) );
$risk_label   = $risk_score >= 70 ? 'Critical' : ( $risk_score >= 40 ? 'Elevated' : 'Moderate' );
$risk_color   = $risk_score >= 70 ? '#c0392b'   : ( $risk_score >= 40 ? '#d97706'   : '#2d6a4f'   );
$audience     = $audience ?? 'executive';
$audience_label = $audience_label ?? 'Executive / Owner — What Does It Mean';
$audience_data  = $audience_data ?? [];
$sibling_reports = $sibling_reports ?? [];

// Gauge arc: stroke-dasharray trick on a 188-unit circle (r=30, circumference ≈ 188).
$circumference = 188;
$dash_offset   = $circumference - ( $circumference * ( $risk_score / 100 ) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ad Intelligence Report<?php echo $business_name ? ' — ' . esc_html( $business_name ) : ''; ?> (<?php echo esc_html( ucfirst( $audience ) ); ?>)</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* =====================================================================
   TOKENS
   ===================================================================== */
:root {
	--ink:          #0f1117;
	--ink2:         #3a3d4a;
	--ink3:         #6b7080;
	--surface:      #f5f4f0;
	--surface2:     #ebe9e3;
	--border:       #d8d5cc;
	--gold:         #b8860b;
	--gold-light:   #f5e9c0;
	--gold-mid:     #d4a017;
	--teal:         #0d6e6e;
	--teal-light:   #d1ede8;
	--coral:        #c0392b;
	--coral-light:  #fde8e6;
	--green:        #2d6a4f;
	--green-light:  #d8f3dc;
	--amber:        #d97706;
	--amber-light:  #fef3c7;
	--radius:       10px;
	--shadow:       0 2px 16px rgba(15,17,23,0.09);
}

/* =====================================================================
   BASE
   ===================================================================== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
	font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
	font-size: 15px;
	line-height: 1.6;
	color: var(--ink);
	background: var(--surface);
}

a { color: var(--teal); text-decoration: none; }
a:hover { text-decoration: underline; }

/* =====================================================================
   LAYOUT
   ===================================================================== */
.rpt-wrap {
	max-width: 780px;
	margin: 0 auto;
	padding: 24px 16px 64px;
}

/* =====================================================================
   HERO HEADER
   ===================================================================== */
.rpt-hero {
	background: var(--ink);
	color: #f0ede6;
	border-radius: var(--radius);
	padding: 40px 44px 36px;
	margin-bottom: 28px;
	position: relative;
	overflow: hidden;
}

.rpt-hero::after {
	content: '';
	position: absolute;
	top: 0; right: 0; bottom: 0;
	width: 40%;
	background: repeating-linear-gradient(
		-45deg, transparent, transparent 8px,
		rgba(255,255,255,0.02) 8px, rgba(255,255,255,0.02) 9px
	);
	pointer-events: none;
}

.rpt-hero-label {
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 10px;
	letter-spacing: 0.15em;
	text-transform: uppercase;
	color: var(--gold-mid);
	margin-bottom: 12px;
}

.rpt-hero h1 {
	font-family: 'DM Serif Display', Georgia, serif;
	font-size: 32px;
	font-weight: 400;
	color: #f5f2eb;
	line-height: 1.2;
	margin-bottom: 8px;
}

.rpt-hero-sub {
	font-size: 14px;
	color: #a8a49c;
	margin-bottom: 0;
}

.rpt-hero-meta {
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 11px;
	color: #6b7080;
	margin-top: 20px;
}

/* =====================================================================
   SECTION CARD
   ===================================================================== */
.rpt-card {
	background: #fff;
	border: 1px solid var(--border);
	border-radius: var(--radius);
	box-shadow: var(--shadow);
	padding: 32px 36px;
	margin-bottom: 20px;
}

.rpt-card-label {
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 10px;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: var(--gold-mid);
	margin-bottom: 18px;
}

.rpt-card h2 {
	font-family: 'DM Serif Display', Georgia, serif;
	font-size: 22px;
	font-weight: 400;
	color: var(--ink);
	margin-bottom: 18px;
}

/* =====================================================================
   RISK GAUGE
   ===================================================================== */
.rpt-gauge-wrap {
	display: flex;
	align-items: center;
	gap: 36px;
	flex-wrap: wrap;
}

.rpt-gauge-svg {
	flex-shrink: 0;
}

.rpt-gauge-info {
	flex: 1;
	min-width: 180px;
}

.rpt-risk-score {
	font-family: 'DM Serif Display', Georgia, serif;
	font-size: 56px;
	font-weight: 400;
	line-height: 1;
	color: <?php echo esc_attr( $risk_color ); ?>;
}

.rpt-risk-label {
	font-size: 18px;
	font-weight: 600;
	color: <?php echo esc_attr( $risk_color ); ?>;
	margin-top: 4px;
	margin-bottom: 12px;
}

.rpt-flag-pills {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.rpt-pill {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 4px 10px;
	border-radius: 20px;
	font-size: 12px;
	font-weight: 500;
}

.rpt-pill.high   { background: var(--coral-light); color: var(--coral); }
.rpt-pill.medium { background: var(--amber-light); color: var(--amber); }
.rpt-pill.low    { background: var(--surface2);    color: var(--ink3);  }

.rpt-pill-dot {
	width: 6px; height: 6px;
	border-radius: 50%;
	background: currentColor;
	flex-shrink: 0;
}

/* =====================================================================
   WASTED SPEND
   ===================================================================== */
.rpt-spend-box {
	background: var(--coral-light);
	border: 1px solid #f5b0aa;
	border-radius: var(--radius);
	padding: 20px 24px;
	display: flex;
	align-items: center;
	gap: 18px;
}

.rpt-spend-icon {
	font-size: 28px;
	flex-shrink: 0;
}

.rpt-spend-amount {
	font-family: 'DM Serif Display', Georgia, serif;
	font-size: 26px;
	color: var(--coral);
	line-height: 1.1;
}

.rpt-spend-desc {
	font-size: 13px;
	color: var(--ink2);
	margin-top: 4px;
}

/* =====================================================================
   TOP 3 ACTIONS
   ===================================================================== */
.rpt-actions-list {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.rpt-action-item {
	display: flex;
	gap: 14px;
	align-items: flex-start;
	padding: 16px 18px;
	border-radius: 8px;
	border: 1px solid var(--border);
	background: var(--surface);
}

.rpt-action-item.high   { border-left: 4px solid var(--coral);  background: var(--coral-light); }
.rpt-action-item.medium { border-left: 4px solid var(--amber);  background: var(--amber-light); }
.rpt-action-item.low    { border-left: 4px solid var(--border); }

.rpt-action-num {
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 13px;
	font-weight: 500;
	color: var(--ink3);
	flex-shrink: 0;
	padding-top: 1px;
}

.rpt-action-text {
	font-size: 14px;
	line-height: 1.5;
	color: var(--ink);
}

.rpt-sev-badge {
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 10px;
	letter-spacing: 0.1em;
	text-transform: uppercase;
	padding: 2px 7px;
	border-radius: 4px;
	margin-left: 6px;
	vertical-align: middle;
}

.rpt-sev-badge.high   { background: var(--coral);  color: #fff; }
.rpt-sev-badge.medium { background: var(--amber);  color: #fff; }
.rpt-sev-badge.low    { background: var(--ink3);   color: #fff; }

/* =====================================================================
   PLATFORM SNAPSHOT TABLE
   ===================================================================== */
.rpt-table-wrap { overflow-x: auto; }

.rpt-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.rpt-table th {
	text-align: left;
	padding: 10px 12px;
	border-bottom: 2px solid var(--border);
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 10px;
	letter-spacing: 0.1em;
	text-transform: uppercase;
	color: var(--ink3);
	white-space: nowrap;
}

.rpt-table td {
	padding: 12px 12px;
	border-bottom: 1px solid var(--border);
	color: var(--ink2);
	vertical-align: middle;
}

.rpt-table tr:last-child td { border-bottom: none; }

.rpt-platform-name {
	font-weight: 600;
	color: var(--ink);
	text-transform: capitalize;
}

.rpt-access-badge {
	display: inline-block;
	padding: 3px 9px;
	border-radius: 20px;
	font-size: 11px;
	font-weight: 500;
}

.rpt-access-badge.none    { background: var(--surface2); color: var(--ink3); }
.rpt-access-badge.pending { background: var(--amber-light); color: var(--amber); }
.rpt-access-badge.granted { background: var(--green-light); color: var(--green); }
.rpt-access-badge.export  { background: var(--teal-light); color: var(--teal); }

.rpt-flag-num {
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 13px;
}

.rpt-flag-num.has-flags { color: var(--coral); font-weight: 600; }

/* =====================================================================
   WHAT'S WORKING
   ===================================================================== */
.rpt-working-list {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.rpt-working-item {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	font-size: 14px;
	line-height: 1.5;
}

.rpt-working-check {
	color: var(--green);
	font-size: 15px;
	flex-shrink: 0;
	margin-top: 1px;
}

/* =====================================================================
   CTA
   ===================================================================== */
.rpt-cta {
	background: var(--ink);
	border-radius: var(--radius);
	padding: 40px 44px;
	text-align: center;
	color: #f0ede6;
	margin-bottom: 20px;
}

.rpt-cta h2 {
	font-family: 'DM Serif Display', Georgia, serif;
	font-size: 26px;
	font-weight: 400;
	color: #f5f2eb;
	margin-bottom: 10px;
}

.rpt-cta p {
	font-size: 14px;
	color: #a8a49c;
	max-width: 480px;
	margin: 0 auto 24px;
}

.rpt-btn-gold {
	display: inline-block;
	background: var(--gold-mid);
	color: #fff;
	padding: 14px 32px;
	border-radius: 8px;
	font-size: 15px;
	font-weight: 600;
	text-decoration: none;
	letter-spacing: 0.01em;
	transition: background 0.15s;
}

.rpt-btn-gold:hover {
	background: var(--gold);
	text-decoration: none;
	color: #fff;
}

/* =====================================================================
   FOOTER
   ===================================================================== */
.rpt-footer {
	text-align: center;
	font-size: 12px;
	color: var(--ink3);
	padding: 16px 0 0;
}

/* =====================================================================
   RESPONSIVE
   ===================================================================== */
@media (max-width: 600px) {
	.rpt-card { padding: 22px 18px; }
	.rpt-hero { padding: 28px 22px 24px; }
	.rpt-hero h1 { font-size: 24px; }
	.rpt-gauge-wrap { flex-direction: column; gap: 20px; }
	.rpt-cta { padding: 28px 20px; }
}

/* =====================================================================
   TOOLBAR — audience switcher + PDF download (screen only)
   ===================================================================== */
.rpt-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 16px;
}

.rpt-switcher {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.rpt-switcher a {
	font-size: 12px;
	font-weight: 500;
	padding: 6px 12px;
	border-radius: 20px;
	background: #fff;
	border: 1px solid var(--border);
	color: var(--ink2);
}

.rpt-switcher a.active {
	background: var(--ink);
	border-color: var(--ink);
	color: #f5f2eb;
}

.rpt-switcher a:hover { text-decoration: none; background: var(--surface2); }
.rpt-switcher a.active:hover { background: var(--ink); }

.rpt-print-btn {
	font-size: 12px;
	font-weight: 600;
	padding: 7px 16px;
	border-radius: 20px;
	background: var(--teal);
	color: #fff;
	border: none;
	cursor: pointer;
	flex-shrink: 0;
}

.rpt-print-btn:hover { background: #095555; }

/* =====================================================================
   AUDIENCE FOCUS SECTIONS
   ===================================================================== */
.rpt-checklist {
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.rpt-checklist li {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	font-size: 14px;
	line-height: 1.5;
}

.rpt-checklist li::before {
	content: '\2610';
	color: var(--gold-mid);
	flex-shrink: 0;
}

.rpt-roadmap-phase {
	padding: 14px 16px;
	border-radius: 8px;
	background: var(--surface);
	border: 1px solid var(--border);
	margin-bottom: 10px;
}

.rpt-roadmap-phase strong {
	display: block;
	font-family: 'IBM Plex Mono', 'Courier New', monospace;
	font-size: 11px;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--gold-mid);
	margin-bottom: 4px;
}

.rpt-note-box {
	background: var(--teal-light);
	border: 1px solid #9fd4cc;
	border-radius: var(--radius);
	padding: 16px 20px;
	font-size: 13px;
	color: var(--ink2);
	line-height: 1.5;
}

/* =====================================================================
   PRINT (PDF export via browser print-to-PDF)
   ===================================================================== */
@media print {
	.rpt-toolbar, .rpt-cta { display: none !important; }
	body { background: #fff; }
	.rpt-wrap { max-width: 100%; padding: 0; }
	.rpt-hero { background: #fff; color: var(--ink); border: 1px solid var(--border); }
	.rpt-hero h1, .rpt-hero-sub { color: var(--ink); }
	.rpt-hero-meta { color: var(--ink3); }
	.rpt-card { box-shadow: none; break-inside: avoid; }
	a[href]::after { content: ""; }
}
</style>
</head>
<body>

<div class="rpt-wrap">

	<!-- ── TOOLBAR ──────────────────────────────────────────────── -->
	<div class="rpt-toolbar">
		<?php if ( count( $sibling_reports ) > 1 ) : ?>
		<nav class="rpt-switcher" aria-label="Switch report audience">
			<?php foreach ( $sibling_reports as $sibling ) : ?>
				<a href="<?php echo esc_url( $sibling['url'] ); ?>"
				   class="<?php echo $sibling['audience'] === $audience ? 'active' : ''; ?>">
					<?php echo esc_html( ucfirst( $sibling['audience'] ) ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php else : ?>
		<div></div>
		<?php endif; ?>
		<button type="button" class="rpt-print-btn" onclick="window.print()">Download PDF</button>
	</div>

	<!-- ── HERO ─────────────────────────────────────────────────── -->
	<div class="rpt-hero">
		<div class="rpt-hero-label">BWG Ads Intelligence — <?php echo esc_html( $audience_label ); ?></div>
		<h1>
			<?php if ( $business_name ) : ?>
				<?php echo esc_html( $business_name ); ?>
			<?php else : ?>
				Ad Intelligence Audit
			<?php endif; ?>
		</h1>
		<?php if ( $website_url ) : ?>
			<p class="rpt-hero-sub"><?php echo esc_html( $website_url ); ?></p>
		<?php endif; ?>
		<div class="rpt-hero-meta">
			Generated <?php echo esc_html( date_i18n( 'F j, Y', strtotime( $generated_at ) ) ); ?>
			&nbsp;·&nbsp;
			<?php echo esc_html( $total_ads ); ?> ad<?php echo $total_ads === 1 ? '' : 's'; ?> analysed
		</div>
	</div>

	<!-- ── RISK SCORE ────────────────────────────────────────────── -->
	<div class="rpt-card">
		<div class="rpt-card-label">Compliance Risk Score</div>
		<div class="rpt-gauge-wrap">
			<!-- SVG gauge -->
			<svg class="rpt-gauge-svg" width="130" height="130" viewBox="0 0 80 80" aria-label="Risk score <?php echo esc_attr( $risk_score ); ?> out of 100">
				<circle cx="40" cy="40" r="30" fill="none" stroke="#e8e5df" stroke-width="9"/>
				<circle cx="40" cy="40" r="30" fill="none"
					stroke="<?php echo esc_attr( $risk_color ); ?>"
					stroke-width="9"
					stroke-dasharray="<?php echo esc_attr( $circumference ); ?>"
					stroke-dashoffset="<?php echo esc_attr( $dash_offset ); ?>"
					stroke-linecap="round"
					transform="rotate(-90 40 40)"/>
				<text x="40" y="37" text-anchor="middle"
					font-family="'DM Serif Display', Georgia, serif"
					font-size="16" fill="<?php echo esc_attr( $risk_color ); ?>"><?php echo esc_html( $risk_score ); ?></text>
				<text x="40" y="50" text-anchor="middle"
					font-family="'Inter', sans-serif"
					font-size="5.5" fill="#6b7080">/ 100</text>
			</svg>

			<div class="rpt-gauge-info">
				<div class="rpt-risk-score"><?php echo esc_html( $risk_score ); ?></div>
				<div class="rpt-risk-label"><?php echo esc_html( $risk_label ); ?> Risk</div>
				<div class="rpt-flag-pills">
					<?php if ( $flag_counts['high'] > 0 ) : ?>
						<span class="rpt-pill high">
							<span class="rpt-pill-dot"></span>
							<?php echo esc_html( $flag_counts['high'] ); ?> critical
						</span>
					<?php endif; ?>
					<?php if ( $flag_counts['medium'] > 0 ) : ?>
						<span class="rpt-pill medium">
							<span class="rpt-pill-dot"></span>
							<?php echo esc_html( $flag_counts['medium'] ); ?> elevated
						</span>
					<?php endif; ?>
					<?php if ( $flag_counts['low'] > 0 ) : ?>
						<span class="rpt-pill low">
							<span class="rpt-pill-dot"></span>
							<?php echo esc_html( $flag_counts['low'] ); ?> advisory
						</span>
					<?php endif; ?>
					<?php if ( array_sum( $flag_counts ) === 0 ) : ?>
						<span class="rpt-pill low">No compliance flags detected</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- ── WASTED SPEND ──────────────────────────────────────────── -->
	<?php if ( $wasted_spend ) : ?>
	<div class="rpt-card">
		<div class="rpt-card-label">Estimated Wasted Ad Spend</div>
		<div class="rpt-spend-box">
			<div class="rpt-spend-icon">&#9888;</div>
			<div>
				<div class="rpt-spend-amount"><?php echo esc_html( $wasted_spend ); ?></div>
				<div class="rpt-spend-desc">
					Estimated monthly spend on ads carrying compliance violations. Fixing these flags typically reduces wasted spend and improves Quality Score within one billing cycle.
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<!-- ── TOP 3 ACTIONS ─────────────────────────────────────────── -->
	<div class="rpt-card">
		<div class="rpt-card-label">Top 3 Urgent Actions</div>
		<h2>Fix These First</h2>
		<ul class="rpt-actions-list">
			<?php foreach ( $top_actions as $idx => $action ) : ?>
			<li class="rpt-action-item <?php echo esc_attr( $action['severity'] ); ?>">
				<span class="rpt-action-num"><?php echo esc_html( $idx + 1 ); ?>.</span>
				<span class="rpt-action-text">
					<?php echo esc_html( $action['action'] ); ?>
					<span class="rpt-sev-badge <?php echo esc_attr( $action['severity'] ); ?>"><?php echo esc_html( $action['severity'] ); ?></span>
				</span>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<!-- ── AUDIENCE FOCUS ────────────────────────────────────────── -->
	<?php if ( 'marketing' === $audience && ! empty( $audience_data ) ) : ?>
	<div class="rpt-card">
		<div class="rpt-card-label">Strategic Performance</div>
		<h2>Platform Mix &amp; Attribution Gaps</h2>
		<?php if ( ! empty( $audience_data['platform_mix'] ) ) : ?>
		<div class="rpt-table-wrap" style="margin-bottom:20px;">
			<table class="rpt-table">
				<thead><tr><th>Platform</th><th>Ads</th><th>Share of Mix</th></tr></thead>
				<tbody>
				<?php foreach ( $audience_data['platform_mix'] as $row ) : ?>
					<tr>
						<td><span class="rpt-platform-name"><?php echo esc_html( $row['platform'] ); ?></span></td>
						<td><?php echo esc_html( $row['ad_count'] ); ?></td>
						<td><?php echo esc_html( $row['pct'] ); ?>%</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $audience_data['attribution_gaps'] ) ) : ?>
		<h2 style="font-size:16px;margin-bottom:10px;">Attribution Gaps</h2>
		<ul class="rpt-working-list" style="margin-bottom:20px;">
			<?php foreach ( $audience_data['attribution_gaps'] as $gap ) : ?>
				<li class="rpt-working-item"><span class="rpt-working-check" style="color:var(--amber);">&#9888;</span><span><?php echo esc_html( $gap ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php if ( ! empty( $audience_data['roadmap_90day'] ) ) : ?>
		<h2 style="font-size:16px;margin-bottom:10px;">90-Day Roadmap</h2>
		<?php foreach ( $audience_data['roadmap_90day'] as $phase ) : ?>
			<div class="rpt-roadmap-phase">
				<strong><?php echo esc_html( $phase['phase'] ); ?></strong>
				<?php echo esc_html( $phase['focus'] ); ?>
			</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( 'compliance' === $audience && ! empty( $audience_data['hipaa_itemized'] ) ) : ?>
	<div class="rpt-card">
		<div class="rpt-card-label">Compliance Risk — Full Itemization</div>
		<h2>Every Flag Found, Cited</h2>
		<div class="rpt-table-wrap" style="margin-bottom:20px;">
			<table class="rpt-table">
				<thead><tr><th>Severity</th><th>Description</th><th>Citation</th><th>Ads Affected</th></tr></thead>
				<tbody>
				<?php foreach ( $audience_data['hipaa_itemized'] as $flag ) : ?>
					<tr>
						<td><span class="rpt-sev-badge <?php echo esc_attr( $flag['severity'] ); ?>"><?php echo esc_html( $flag['severity'] ); ?></span></td>
						<td><?php echo esc_html( $flag['description'] ); ?><?php echo 'vision' === ( $flag['source'] ?? '' ) ? ' <span style="opacity:.6;">(AI vision review)</span>' : ''; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup only */ ?></td>
						<td style="font-size:12px;color:var(--ink3);"><?php echo esc_html( $flag['citation'] ); ?></td>
						<td><?php echo esc_html( $flag['ad_count'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<h2 style="font-size:16px;margin-bottom:10px;">Remediation Checklist</h2>
		<ul class="rpt-checklist">
			<?php foreach ( $audience_data['remediation_checklist'] as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<?php if ( 'agency' === $audience && ! empty( $audience_data ) ) : ?>
	<div class="rpt-card">
		<div class="rpt-card-label">Agency Intake</div>
		<h2>Account Map &amp; Onboarding</h2>
		<?php if ( ! empty( $audience_data['upsell_flags'] ) ) : ?>
		<h2 style="font-size:16px;margin-bottom:10px;">Upsell Signals</h2>
		<ul class="rpt-working-list" style="margin-bottom:20px;">
			<?php foreach ( $audience_data['upsell_flags'] as $flag ) : ?>
				<li class="rpt-working-item"><span class="rpt-working-check" style="color:var(--gold-mid);">&#9733;</span><span><?php echo esc_html( $flag ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<h2 style="font-size:16px;margin-bottom:10px;">Onboarding Checklist</h2>
		<ul class="rpt-checklist">
			<?php foreach ( $audience_data['onboarding_checklist'] as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<?php if ( 'admissions' === $audience && ! empty( $audience_data ) ) : ?>
	<div class="rpt-card">
		<div class="rpt-card-label">Admissions Performance</div>
		<h2>Channel Volume</h2>
		<?php if ( ! empty( $audience_data['channel_breakdown'] ) ) : ?>
		<div class="rpt-table-wrap" style="margin-bottom:20px;">
			<table class="rpt-table">
				<thead><tr><th>Channel</th><th>Ads Found</th></tr></thead>
				<tbody>
				<?php foreach ( $audience_data['channel_breakdown'] as $row ) : ?>
					<tr><td><span class="rpt-platform-name"><?php echo esc_html( $row['platform'] ); ?></span></td><td><?php echo esc_html( $row['ad_count'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $audience_data['call_audit_note'] ) ) : ?>
		<div class="rpt-note-box"><?php echo esc_html( $audience_data['call_audit_note'] ); ?></div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ── PLATFORM SNAPSHOT ─────────────────────────────────────── -->
	<?php if ( ! empty( $platform_snapshot ) ) : ?>
	<div class="rpt-card">
		<div class="rpt-card-label">Platform Snapshot</div>
		<h2>Ads by Platform</h2>
		<div class="rpt-table-wrap">
			<table class="rpt-table">
				<thead>
					<tr>
						<th>Platform</th>
						<th>Ads Found</th>
						<th>Compliance Flags</th>
						<th>Access Status</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $platform_snapshot as $platform => $pdata ) : ?>
					<tr>
						<td><span class="rpt-platform-name"><?php echo esc_html( $platform ); ?></span></td>
						<td><?php echo esc_html( $pdata['ad_count'] ); ?></td>
						<td>
							<span class="rpt-flag-num <?php echo $pdata['flag_count'] > 0 ? 'has-flags' : ''; ?>">
								<?php echo esc_html( $pdata['flag_count'] ); ?>
							</span>
						</td>
						<td>
							<span class="rpt-access-badge <?php echo esc_attr( $pdata['access_status'] ); ?>">
								<?php
								$access_labels = [ 'none' => 'Not requested', 'pending' => 'Pending', 'granted' => 'Granted', 'export' => 'Export uploaded' ];
								echo esc_html( $access_labels[ $pdata['access_status'] ] ?? $pdata['access_status'] );
								?>
							</span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; ?>

	<!-- ── WHAT'S WORKING ────────────────────────────────────────── -->
	<div class="rpt-card">
		<div class="rpt-card-label">What&#8217;s Working</div>
		<h2>Strengths to Build On</h2>
		<ul class="rpt-working-list">
			<?php foreach ( $whats_working as $item ) : ?>
			<li class="rpt-working-item">
				<span class="rpt-working-check">&#10003;</span>
				<span><?php echo esc_html( $item ); ?></span>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>

	<!-- ── BOOK A CALL CTA ───────────────────────────────────────── -->
	<div class="rpt-cta">
		<h2>Turn This Audit Into a Growth Plan</h2>
		<p>
			A 30-minute strategy call with our team converts these findings into a concrete ad-spend optimisation roadmap — at no cost to you.
		</p>
		<?php if ( $schedule_url ) : ?>
			<a href="<?php echo esc_url( $schedule_url ); ?>" class="rpt-btn-gold" target="_blank" rel="noopener">
				Book Your Free Strategy Call
			</a>
		<?php else : ?>
			<p style="color:var(--gold-mid);font-weight:600;">Contact us to schedule your strategy call.</p>
		<?php endif; ?>
	</div>

	<div class="rpt-footer">
		<p>This report was generated automatically by BWG Ads Intelligence and is confidential to the recipient.</p>
		<p style="margin-top:6px;">Report token: <code><?php echo esc_html( $report_token ); ?></code></p>
	</div>

</div><!-- /.rpt-wrap -->

</body>
</html>
