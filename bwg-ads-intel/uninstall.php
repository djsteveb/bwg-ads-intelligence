<?php
/**
 * Runs when the plugin is deleted via WP Admin → Plugins → Delete.
 * Drops all plugin tables and removes all plugin options.
 * NOT called on deactivation — only on full deletion.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = [
	'bwg_ai_sessions',
	'bwg_ai_discovered',
	'bwg_ai_ads',
	'bwg_ai_access',
	'bwg_ai_pages',
	'bwg_ai_reports',
	'bwg_ai_ratelimits',
	'bwg_ai_audit_log',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$options = [
	'bwg_ai_db_version',
	'bwg_ai_email_provider',
	'bwg_ai_from_name',
	'bwg_ai_from_email',
	'bwg_ai_sendgrid_api_key',
	'bwg_ai_postmark_api_key',
	'bwg_ai_entityiq_url',
	'bwg_ai_entityiq_secret',
	'bwg_ai_google_places_key',
	'bwg_ai_captcha_site_key',
	'bwg_ai_captcha_secret_key',
	'bwg_ai_booking_url',
	'bwg_ai_schedule_url',
	'bwg_ai_shortcode_page_url',
	'bwg_ai_storage_warning_gb',
	'bwg_ai_audit_log_retention_days',
];

foreach ( $options as $option ) {
	delete_option( $option );
}
