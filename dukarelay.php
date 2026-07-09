<?php
/**
 * Plugin Name:       DukaRelay
 * Plugin URI:        https://dukarelay.com
 * Description:       Reliable WhatsApp order notifications for WooCommerce — self-hosted, using your own official WhatsApp Cloud API connection. No monthly SaaS, no middleman.
 * Version:           0.1.0-alpha
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Nigel Rodgers
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dukarelay
 * Domain Path:       /languages
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE IS (plain English, for a non-coder reader):
 * This is the single entry point WordPress loads. It does four small things:
 *   1. Refuses to run directly (security — files must be loaded *by* WordPress).
 *   2. Defines a few constants (our version number and where our files live).
 *   3. Loads the one class that actually boots the plugin.
 *   4. Registers what happens on activate / deactivate / uninstall.
 * It deliberately contains almost NO logic itself. All real work lives in
 * includes/. Think of this file as the light switch, not the wiring.
 * See docs/ (and the engineering docs in the venture folder) for the model.
 * ---------------------------------------------------------------------------
 */

// Refuse to run unless loaded by WordPress. A direct hit on this URL gets nothing.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Constants. Every global name we introduce is prefixed DUKARELAY_ / dukarelay_ ---
define( 'DUKARELAY_VERSION', '0.1.0-alpha' );
define( 'DUKARELAY_PLUGIN_FILE', __FILE__ );
define( 'DUKARELAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ); // filesystem path, trailing slash
define( 'DUKARELAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );  // web URL, trailing slash

// Load the orchestrator class (the thing that wires Core + modules together).
require_once DUKARELAY_PLUGIN_DIR . 'includes/class-dukarelay-plugin.php';

// Activation: create our database tables. Runs once, when the user clicks "Activate".
register_activation_hook( __FILE__, array( 'DukaRelay_Plugin', 'on_activate' ) );

// Deactivation: stop scheduled tasks. Does NOT delete data (that's uninstall's job).
register_deactivation_hook( __FILE__, array( 'DukaRelay_Plugin', 'on_deactivate' ) );

/**
 * Boot the plugin once WordPress has finished loading plugins.
 * We hook 'plugins_loaded' so that, e.g., we can safely check whether
 * WooCommerce is present before deciding to load the WooCommerce module.
 */
add_action(
	'plugins_loaded',
	static function () {
		DukaRelay_Plugin::instance()->boot();
	}
);
