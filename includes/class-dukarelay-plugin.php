<?php
/**
 * Plugin orchestrator (singleton). Loads Core, then conditionally the
 * WooCommerce module, and owns the activation/deactivation callbacks.
 *
 * Layer rule (ADR-0003): this class may detect WooCommerce, but Core code it
 * loads must not — modules depend on Core, never the reverse.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class DukaRelay_Plugin {

	/**
	 * The single shared instance.
	 *
	 * @var DukaRelay_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (or create) the one shared instance.
	 *
	 * @return DukaRelay_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Boot the plugin. Core first, then the WooCommerce module if applicable.
	 */
	public function boot() {
		$this->load_core();
		$this->maybe_load_woocommerce_module();
	}

	/**
	 * Load Core subsystems (run on any WordPress site).
	 *
	 * Stub: require_once lines are enabled as each class is built in 0.1; the
	 * list documents the intended Core surface.
	 */
	private function load_core() {
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-connection.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-ledger.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-templates.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-webhook.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-token-health.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-relay.php';
	}

	/**
	 * Load the WooCommerce module only if WooCommerce is active and the user
	 * enabled it. Otherwise none of its files/hooks/assets load (keeps runtime
	 * light).
	 */
	private function maybe_load_woocommerce_module() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( ! $this->is_module_enabled( 'woocommerce' ) ) {
			return;
		}
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/modules/woocommerce/class-dukarelay-woo-module.php';
	}

	/**
	 * Whether a module is enabled in settings. WooCommerce defaults on so a
	 * fresh Woo install works out of the box.
	 *
	 * @param string $module Module slug, e.g. 'woocommerce'.
	 * @return bool
	 */
	private function is_module_enabled( $module ) {
		$enabled = get_option( 'dukarelay_enabled_modules', array( 'woocommerce' => true ) );
		return ! empty( $enabled[ $module ] );
	}

	/**
	 * Activation: create the ledger tables (ADR-0002).
	 */
	public static function on_activate() {
		require_once DUKARELAY_PLUGIN_DIR . 'includes/class-dukarelay-activator.php';
		DukaRelay_Activator::activate();
	}

	/**
	 * Deactivation: clear scheduled tasks. Data is preserved (only uninstall
	 * deletes it).
	 */
	public static function on_deactivate() {
		$timestamp = wp_next_scheduled( 'dukarelay_token_health_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'dukarelay_token_health_check' );
		}
	}
}
