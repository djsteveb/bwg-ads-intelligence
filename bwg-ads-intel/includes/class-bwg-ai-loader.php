<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects and registers all plugin hooks, then wires class instances together.
 * Nothing outside this class should call add_action / add_filter directly —
 * route everything through here so the hook map stays auditable in one place.
 */
class BWG_AI_Loader {

	/** @var array[] Queued actions [ hook, callback, priority, accepted_args ] */
	private $actions = [];

	/** @var array[] Queued filters */
	private $filters = [];

	public function run() {
		BWG_AI_Activator::maybe_migrate();

		$this->register_hooks();

		foreach ( $this->actions as $a ) {
			add_action( $a['hook'], $a['callback'], $a['priority'], $a['args'] );
		}
		foreach ( $this->filters as $f ) {
			add_filter( $f['hook'], $f['callback'], $f['priority'], $f['args'] );
		}
	}

	private function register_hooks() {
		// REST API.
		$rest = new BWG_AI_Rest();
		$this->add_action( 'rest_api_init', [ $rest, 'register_routes' ] );

		// Shortcode (front-end form).
		$shortcode = new BWG_AI_Shortcode();
		$this->add_action( 'init', [ $shortcode, 'register' ] );

		// Admin panel.
		if ( is_admin() ) {
			$admin = new BWG_AI_Admin();
			$this->add_action( 'admin_menu', [ $admin, 'register_menu' ] );
			$this->add_action( 'admin_init', [ $admin, 'register_settings' ] );
			$this->add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
			$this->add_filter( 'plugin_action_links_' . BWG_AI_BASENAME, [ $admin, 'plugin_action_links' ] );
		}

		// Cron handlers.
		$discovery = new BWG_AI_Discovery();
		$this->add_action( 'bwg_ai_run_discovery', [ $discovery, 'run' ] );

		$ad_surface = new BWG_AI_Ad_Surface();
		$this->add_action( 'bwg_ai_poll_entityiq', [ $ad_surface, 'poll' ] );

		$email = new BWG_AI_Email();
		$this->add_action( 'bwg_ai_send_access_followup', [ $email, 'send_followups' ] );

		// Daily maintenance registered inside Admin to keep it co-located with its handler.
		// The cron is scheduled in Activator and handled by BWG_AI_Admin::daily_maintenance().

		// Schedule recurring crons if not already set.
		$this->schedule_recurring_crons();
	}

	private function schedule_recurring_crons() {
		if ( ! wp_next_scheduled( 'bwg_ai_send_access_followup' ) ) {
			wp_schedule_event( time(), 'hourly', 'bwg_ai_send_access_followup' );
		}
		if ( ! wp_next_scheduled( 'bwg_ai_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'bwg_ai_daily_maintenance' );
		}
	}

	private function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$this->actions[] = compact( 'hook', 'callback', 'priority', 'args' );
	}

	private function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$this->filters[] = compact( 'hook', 'callback', 'priority', 'args' );
	}
}
