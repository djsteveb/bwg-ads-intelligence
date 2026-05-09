<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Shortcode {
	public function register() {
		add_shortcode( 'bwg_ads_intel', [ $this, 'render' ] );
	}

	public function render( $atts ) {
		return '<!-- BWG Ads Intel shortcode — implemented in M3 -->';
	}
}
