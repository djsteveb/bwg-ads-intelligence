<?php
/**
 * Plugin Name: BWG Ads Intelligence
 * Plugin URI:  https://betterwebgroup.com
 * Description: Multi-platform ads audit, compliance analysis, and lead conversion for treatment center advertisers.
 * Version:     1.0.0
 * Author:      Better Web Group
 * License:     GPL-2.0-or-later
 * Text Domain: bwg-ads-intel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BWG_AI_VERSION', '1.0.0' );
define( 'BWG_AI_DB_VERSION', '1.1.0' );
define( 'BWG_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWG_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'BWG_AI_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader — maps class name prefix BWG_AI_ to includes/ and ads-intel/ directories.
 */
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'BWG_AI_' ) !== 0 ) {
		return;
	}

	$slug = strtolower( str_replace( [ 'BWG_AI_', '_' ], [ '', '-' ], $class ) );
	$filename = 'class-bwg-ai-' . $slug . '.php';

	$locations = [
		BWG_AI_DIR . 'includes/',
		BWG_AI_DIR . 'ads-intel/',
		BWG_AI_DIR . 'admin/',
		BWG_AI_DIR . 'includes/fallbacks/',
	];

	foreach ( $locations as $dir ) {
		$path = $dir . $filename;
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
} );

// Load unconditionally (not just via the class autoloader) since it also
// defines the bwg_ai_encrypt_secret() / bwg_ai_decrypt_secret() helpers used
// by plain function calls (e.g. settings sanitize callbacks) that may run
// before the BWG_AI_Security class is otherwise referenced.
require_once BWG_AI_DIR . 'includes/class-bwg-ai-security.php';
require_once BWG_AI_DIR . 'includes/bwg-suite-bridge.php';

register_activation_hook( __FILE__, [ 'BWG_AI_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BWG_AI_Activator', 'deactivate' ] );

/**
 * Bootstrap the plugin after all plugins are loaded so sibling plugin classes
 * (bwg-speed-sitescout) are available for the class_exists() soft-dependency checks.
 */
add_action( 'plugins_loaded', function () {
	$loader = new BWG_AI_Loader();
	$loader->run();
} );
