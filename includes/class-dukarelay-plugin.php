<?php
/**
 * The plugin orchestrator.
 *
 * WHAT THIS FILE IS (plain English):
 * One object that represents "the whole plugin." It is a singleton — there is
 * only ever one of it. Its job is to wire things together at boot:
 *   - load the Core (works on any WordPress site),
 *   - THEN, only if WooCommerce is installed AND the user enabled it, load the
 *     WooCommerce module (conditional loading = the plugin stays lightweight).
 *
 * It also holds the activate/deactivate hooks (creating DB tables, clearing
 * scheduled tasks). Right now most of this is scaffolding: the methods exist and
 * are called in the right order, but the Core/module classes they will load are
 * not written yet (release 0.1 fills them in). This file establishes the SHAPE.
 *
 * Layer rule (ADR-0002 / coding-standards §0.3): this file may look at whether
 * WooCommerce exists, but Core code it loads must NOT. Module depends on Core,
 * never the reverse.
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
	 * Private constructor — use instance() instead. Prevents accidental duplicates.
	 */
	private function __construct() {}

	/**
	 * Boot the plugin. Called on 'plugins_loaded'.
	 *
	 * Order matters: Core first, then conditionally the WooCommerce module.
	 */
	public function boot() {
		$this->load_core();
		$this->maybe_load_woocommerce_module();
	}

	/**
	 * Load the Core subsystems (connection, ledger, templates, webhook,
	 * token-health, relay). These run on ANY WordPress site.
	 *
	 * SCAFFOLD: the require_once lines are intentionally commented out until the
	 * classes exist. Uncomment each as it is built in release 0.1. Keeping them
	 * listed here documents the intended Core surface.
	 */
	private function load_core() {
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-connection.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-ledger.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-templates.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-webhook.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-token-health.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-relay.php';
	}

	/**
	 * Load the WooCommerce module — but only if BOTH are true:
	 *   1. WooCommerce is active (class_exists), and
	 *   2. the user has enabled the module in settings.
	 * If either is false, none of the module's files/hooks/assets load at all.
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
	 * Is a given module switched on in settings? Defaults to enabled for
	 * WooCommerce so a fresh Woo install works out of the box.
	 *
	 * @param string $module Module slug, e.g. 'woocommerce'.
	 * @return bool
	 */
	private function is_module_enabled( $module ) {
		$enabled = get_option( 'dukarelay_enabled_modules', array( 'woocommerce' => true ) );
		return ! empty( $enabled[ $module ] );
	}

	/**
	 * Activation callback. Creates our database tables (the message ledger and
	 * conversations table — ADR-0002). Delegated to the activator class.
	 */
	public static function on_activate() {
		require_once DUKARELAY_PLUGIN_DIR . 'includes/class-dukarelay-activator.php';
		DukaRelay_Activator::activate();
	}

	/**
	 * Deactivation callback. Clears scheduled tasks (the token-health cron
	 * heartbeat). Does NOT delete data — that only happens on uninstall.
	 */
	public static function on_deactivate() {
		$timestamp = wp_next_scheduled( 'dukarelay_token_health_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'dukarelay_token_health_check' );
		}
	}
}
