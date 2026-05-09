<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Admin {
	public function register_menu() {
		// Admin menu registered in M10.
	}

	public function register_settings() {
		// Settings registered in M10.
	}

	public function enqueue_assets( $hook ) {
		// Assets enqueued in M10.
	}

	public function plugin_action_links( $links ) {
		return $links;
	}

	public function daily_maintenance() {
		// Maintenance tasks implemented in M10.
	}
}
