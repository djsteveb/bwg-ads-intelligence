<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BWG_AI_Shortcode {

	public function register() {
		add_shortcode( 'bwg_ads_intel', [ $this, 'render' ] );
	}

	public function render( $atts ) {
		// Enqueue assets only when the shortcode is actually rendered.
		wp_enqueue_style(
			'bwg-ai-form',
			BWG_AI_URL . 'ads-intel/assets/ai-form.css',
			[],
			BWG_AI_VERSION
		);

		wp_enqueue_script(
			'bwg-ai-form',
			BWG_AI_URL . 'ads-intel/assets/ai-form.js',
			[ 'jquery' ],
			BWG_AI_VERSION,
			true
		);

		// Resolve resume token from URL parameter.
		$resume_token = '';
		$access_code  = '';
		if ( isset( $_GET['resume'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$resume_token = sanitize_text_field( wp_unslash( $_GET['resume'] ) );
		}
		if ( isset( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$access_code = strtoupper( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
		}

		wp_localize_script( 'bwg-ai-form', 'bwgAI', [
			'restUrl'        => esc_url_raw( rest_url( 'bwg/v1/ai' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'resumeToken'    => $resume_token,
			'accessCode'     => $access_code,
			'captchaSiteKey' => get_option( 'bwg_ai_captcha_site_key', '' ),
			'scheduleUrl'    => esc_url( get_option( 'bwg_ai_schedule_url', '' ) ),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
		] );

		// If Cloudflare Turnstile key is set, load their script.
		$captcha_key = get_option( 'bwg_ai_captcha_site_key', '' );
		if ( $captcha_key ) {
			wp_enqueue_script(
				'cf-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js',
				[],
				null,
				true
			);
		}

		ob_start();
		?>
		<div id="bwg-ai-app" class="bwg-ai-app" data-resume="<?php echo esc_attr( $resume_token ); ?>" data-code="<?php echo esc_attr( $access_code ); ?>">
			<div class="bwg-ai-inner">
				<!-- Steps rendered by ai-form.js -->
				<div id="bwg-ai-steps"></div>
				<!-- Global error/notice bar -->
				<div id="bwg-ai-notice" class="bwg-ai-notice" style="display:none;"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
