<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Activator {

	public static function activate() {
		self::create_tables();
		self::set_defaults();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		// Unschedule all plugin crons on deactivation.
		$hooks = [
			'bwg_ai_run_discovery',
			'bwg_ai_run_ad_surface',
			'bwg_ai_send_access_followup',
			'bwg_ai_daily_maintenance',
		];
		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	private static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tables = self::table_definitions( $charset );
		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		update_option( 'bwg_ai_db_version', BWG_AI_DB_VERSION );
	}

	private static function table_definitions( $charset ) {
		global $wpdb;
		$p = $wpdb->prefix . 'bwg_ai_';

		return [
			// One row per audit session.
			"CREATE TABLE {$p}sessions (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				access_code   VARCHAR(10)    NOT NULL DEFAULT '',
				resume_token  VARCHAR(128)   NOT NULL DEFAULT '',
				resume_token_expires DATETIME,
				email         VARCHAR(255)   NOT NULL DEFAULT '',
				website_url   VARCHAR(2083)  NOT NULL DEFAULT '',
				step_completed TINYINT       NOT NULL DEFAULT 0,
				status        VARCHAR(32)    NOT NULL DEFAULT 'active',
				entityiq_job_id VARCHAR(128) NOT NULL DEFAULT '',
				created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY access_code (access_code),
				KEY resume_token (resume_token(32)),
				KEY status (status),
				KEY created_at (created_at)
			) $charset;",

			// Phase 1 discovery results.
			"CREATE TABLE {$p}discovered (
				id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id            BIGINT UNSIGNED NOT NULL,
				business_name         VARCHAR(255)   DEFAULT '',
				business_address      VARCHAR(512)   DEFAULT '',
				business_phone        VARCHAR(64)    DEFAULT '',
				gbp_place_id          VARCHAR(255)   DEFAULT '',
				gbp_rating            DECIMAL(3,1)   DEFAULT NULL,
				gbp_review_count      INT            DEFAULT NULL,
				gbp_category          VARCHAR(255)   DEFAULT '',
				social_facebook_url   VARCHAR(2083)  DEFAULT '',
				social_instagram_url  VARCHAR(2083)  DEFAULT '',
				social_linkedin_url   VARCHAR(2083)  DEFAULT '',
				social_tiktok_url     VARCHAR(2083)  DEFAULT '',
				social_youtube_url    VARCHAR(2083)  DEFAULT '',
				pixel_meta_id         VARCHAR(64)    DEFAULT '',
				pixel_gtm_id          VARCHAR(64)    DEFAULT '',
				pixel_ga4_id          VARCHAR(64)    DEFAULT '',
				pixel_tiktok_id       VARCHAR(64)    DEFAULT '',
				pixel_linkedin_id     VARCHAR(64)    DEFAULT '',
				whois_registrar       VARCHAR(255)   DEFAULT '',
				whois_created_at      DATE           DEFAULT NULL,
				whois_expires_at      DATE           DEFAULT NULL,
				whois_nameservers     TEXT,
				tech_stack            LONGTEXT,
				legitscript_status    VARCHAR(64)    DEFAULT '',
				legitscript_raw       TEXT,
				licensure_signals     TEXT,
				discovery_confidence  LONGTEXT,
				discovery_flags       LONGTEXT,
				discovered_at         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY session_id (session_id)
			) $charset;",

			// One row per ad found across all platforms.
			"CREATE TABLE {$p}ads (
				id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id       BIGINT UNSIGNED NOT NULL,
				platform         VARCHAR(32)    NOT NULL DEFAULT '',
				advertiser_id    VARCHAR(255)   DEFAULT '',
				ad_id            VARCHAR(255)   DEFAULT '',
				ad_copy          LONGTEXT,
				ad_image_url     VARCHAR(2083)  DEFAULT '',
				ad_snapshot_url  VARCHAR(2083)  DEFAULT '',
				screenshot_path  VARCHAR(1024)  DEFAULT '',
				source           VARCHAR(16)    NOT NULL DEFAULT 'api',
				run_dates        VARCHAR(255)   DEFAULT '',
				spend_range      VARCHAR(128)   DEFAULT '',
				user_confirmed   TINYINT        NOT NULL DEFAULT 0,
				compliance_flags LONGTEXT,
				vision_analysis  LONGTEXT,
				created_at       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY platform (platform),
				KEY ad_id (ad_id)
			) $charset;",

			// Per-platform access grant status.
			"CREATE TABLE {$p}access (
				id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id          BIGINT UNSIGNED NOT NULL,
				platform            VARCHAR(32)    NOT NULL DEFAULT '',
				access_status       VARCHAR(32)    NOT NULL DEFAULT 'pending',
				grant_email_sent_at DATETIME       DEFAULT NULL,
				access_granted_at   DATETIME       DEFAULT NULL,
				export_uploaded_at  DATETIME       DEFAULT NULL,
				created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY session_platform (session_id, platform)
			) $charset;",

			// Phase 6 landing page spider results (deferred — table created now, populated later).
			"CREATE TABLE {$p}pages (
				id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id          BIGINT UNSIGNED NOT NULL,
				url                 VARCHAR(2083)  NOT NULL DEFAULT '',
				screenshot_path     VARCHAR(1024)  DEFAULT '',
				cwv_scores          LONGTEXT,
				pixel_flags         LONGTEXT,
				compliance_flags    LONGTEXT,
				message_match_score TINYINT        DEFAULT NULL,
				crawled_at          DATETIME       DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id)
			) $charset;",

			// Generated reports, one per session/audience.
			"CREATE TABLE {$p}reports (
				id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id     BIGINT UNSIGNED NOT NULL,
				audience_type  VARCHAR(64)    NOT NULL DEFAULT 'executive',
				report_token   VARCHAR(64)    NOT NULL DEFAULT '',
				report_data    LONGTEXT,
				pdf_path       VARCHAR(1024)  DEFAULT '',
				generated_at   DATETIME       DEFAULT NULL,
				emailed_at     DATETIME       DEFAULT NULL,
				expires_at     DATETIME       DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY report_token (report_token),
				KEY session_id (session_id)
			) $charset;",

			// Token bucket for rate limiting.
			"CREATE TABLE {$p}ratelimits (
				bucket_key   VARCHAR(255)   NOT NULL DEFAULT '',
				count        INT UNSIGNED   NOT NULL DEFAULT 0,
				expires_at   DATETIME       NOT NULL,
				PRIMARY KEY  (bucket_key)
			) $charset;",

			// Audit log: all actions, emails, errors.
			"CREATE TABLE {$p}audit_log (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				session_id BIGINT UNSIGNED DEFAULT NULL,
				action     VARCHAR(128)   NOT NULL DEFAULT '',
				message    TEXT,
				context    LONGTEXT,
				created_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY action (action),
				KEY created_at (created_at)
			) $charset;",
		];
	}

	private static function set_defaults() {
		// Only set if not already configured.
		add_option( 'bwg_ai_email_provider', 'wp_mail' );
		add_option( 'bwg_ai_from_name', get_bloginfo( 'name' ) );
		add_option( 'bwg_ai_from_email', get_option( 'admin_email' ) );
		add_option( 'bwg_ai_entityiq_url', '' );
		add_option( 'bwg_ai_entityiq_secret', '' );
		add_option( 'bwg_ai_google_places_key', '' );
		add_option( 'bwg_ai_meta_ad_library_token', '' );
		add_option( 'bwg_ai_captcha_site_key', '' );
		add_option( 'bwg_ai_captcha_secret_key', '' );
		add_option( 'bwg_ai_schedule_url', '' );
		add_option( 'bwg_ai_storage_warning_gb', 10 );
		add_option( 'bwg_ai_audit_log_retention_days', 90 );
	}

	/**
	 * Run any DB migrations needed when the plugin updates.
	 * Called on plugins_loaded when the stored version is older than BWG_AI_DB_VERSION.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'bwg_ai_db_version' ) !== BWG_AI_DB_VERSION ) {
			self::create_tables();
		}
	}
}
