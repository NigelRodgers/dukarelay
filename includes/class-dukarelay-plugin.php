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
	 * The service container ("reception desk"): shared Core components, built
	 * once and looked up by id. See docs/dev/architecture.md.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

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
	 * Register (or replace) a shared service. Modules use this via the
	 * 'dukarelay_register_services' hook to add or swap components.
	 *
	 * @param string $id      Service id, e.g. 'connection'.
	 * @param object $service The service instance.
	 * @return void
	 */
	public function set_service( $id, $service ) {
		$this->services[ $id ] = $service;
	}

	/**
	 * Look up a shared service by id.
	 *
	 * @param string $id Service id, e.g. 'connection'.
	 * @return object|null Null if no service is registered under that id.
	 */
	public function service( $id ) {
		return isset( $this->services[ $id ] ) ? $this->services[ $id ] : null;
	}

	/**
	 * Boot the plugin. Core first, then the WooCommerce module if applicable.
	 */
	public function boot() {
		$this->load_core();
		$this->maybe_load_woocommerce_module();
	}

	/**
	 * Load Core subsystems (run on any WordPress site), register them as
	 * services, then fire the extension point so modules/add-ons can register
	 * or swap services. Core classes here must stay shop-blind (ADR-0003).
	 *
	 * require_once lines are enabled as each class is built in 0.1; the list
	 * documents the intended Core surface.
	 */
	private function load_core() {
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/interface-dukarelay-sender.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-settings.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-connection.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-ledger.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-cloud-api-sender.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-dispatcher.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-webhook.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-inbound-relay.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-token-health.php';
		require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-templates.php';
		// require_once DUKARELAY_PLUGIN_DIR . 'includes/core/class-dukarelay-relay.php';

		$connection = new DukaRelay_Connection();
		$ledger     = new DukaRelay_Ledger();
		$sender     = new DukaRelay_Cloud_Api_Sender( $connection );

		$settings   = new DukaRelay_Settings();
		$dispatcher = new DukaRelay_Dispatcher( $ledger );

		$this->set_service( 'settings', $settings );
		$this->set_service( 'connection', $connection );
		$this->set_service( 'ledger', $ledger );
		$this->set_service( 'sender', $sender );
		$this->set_service( 'dispatcher', $dispatcher );
		$this->set_service( 'webhook', new DukaRelay_Webhook( $connection, $ledger ) );
		$this->set_service( 'inbound_relay', new DukaRelay_Inbound_Relay( $settings, $dispatcher ) );
		$this->set_service( 'token_health', new DukaRelay_Token_Health( $connection, $settings, $dispatcher ) );
		$this->set_service( 'templates', new DukaRelay_Templates( $connection ) );

		// Register the primary sender on the resolver filter (priority order =
		// array order). Fallback senders add themselves at a lower priority.
		add_filter(
			'dukarelay_senders',
			static function ( $senders ) use ( $sender ) {
				$senders[] = $sender;
				return $senders;
			},
			10
		);

		/**
		 * Fires after Core services are registered. Modules and add-ons register
		 * or replace services here (e.g. add a fallback sender).
		 *
		 * @param DukaRelay_Plugin $plugin The plugin/container instance.
		 */
		do_action( 'dukarelay_register_services', $this );
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

		$dispatcher = $this->service( 'dispatcher' );
		$settings   = $this->service( 'settings' );
		if ( ! $dispatcher instanceof DukaRelay_Dispatcher || ! $settings instanceof DukaRelay_Settings ) {
			return;
		}

		require_once DUKARELAY_PLUGIN_DIR . 'includes/modules/woocommerce/class-dukarelay-woo-module.php';
		$this->set_service( 'woo_module', new DukaRelay_Woo_Module( $dispatcher, $settings ) );
	}

	/**
	 * Whether a module is enabled, per the Settings store. WooCommerce defaults
	 * on so a fresh Woo install works out of the box.
	 *
	 * @param string $module Module slug, e.g. 'woocommerce'.
	 * @return bool
	 */
	private function is_module_enabled( $module ) {
		$settings = $this->service( 'settings' );
		return $settings instanceof DukaRelay_Settings ? $settings->is_module_enabled( $module ) : false;
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
