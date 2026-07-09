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
 * @package DukaRelay
 *
 * Entry point. Defines constants, loads the orchestrator, and registers the
 * activation/deactivation/uninstall hooks. No business logic lives here.
 */

// Never expose the file to direct requests.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DUKARELAY_VERSION', '0.1.0-alpha' );
define( 'DUKARELAY_PLUGIN_FILE', __FILE__ );
define( 'DUKARELAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DUKARELAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DUKARELAY_PLUGIN_DIR . 'includes/class-dukarelay-plugin.php';

register_activation_hook( __FILE__, array( 'DukaRelay_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'DukaRelay_Plugin', 'on_deactivate' ) );

/**
 * Boot on 'plugins_loaded' so WooCommerce presence can be detected before the
 * module load decision.
 */
add_action(
	'plugins_loaded',
	static function () {
		DukaRelay_Plugin::instance()->boot();
	}
);
