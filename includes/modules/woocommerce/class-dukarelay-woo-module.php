<?php
/**
 * WooCommerce module: sends order notifications by reacting to Woo order events
 * and dispatching template messages via Core's Dispatcher. The first module —
 * loaded conditionally, depends on Core only (ADR-0003). See docs/dev/woo-module.md.
 *
 * Customer order notifications are business-initiated and therefore require an
 * approved template, so every status defaults to disabled/no-template until the
 * Templates subsystem configures it (nothing sends by accident).
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-notification module.
 */
class DukaRelay_Woo_Module {

	const SETTINGS_KEY = 'dukarelay_woo_settings';

	/**
	 * Core dispatcher.
	 *
	 * @var DukaRelay_Dispatcher
	 */
	private $dispatcher;

	/**
	 * Core settings (for the Primary Number).
	 *
	 * @var DukaRelay_Settings
	 */
	private $settings;

	/**
	 * Constructor. Hooks Woo order events.
	 *
	 * @param DukaRelay_Dispatcher $dispatcher Core dispatcher.
	 * @param DukaRelay_Settings   $settings   Core settings.
	 */
	public function __construct( DukaRelay_Dispatcher $dispatcher, DukaRelay_Settings $settings ) {
		$this->dispatcher = $dispatcher;
		$this->settings   = $settings;

		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 10, 1 );
	}

	/**
	 * Module settings, with defaults (everything off until configured).
	 *
	 * @return array
	 */
	private function config() {
		$defaults = array(
			'language'          => 'en_US',
			'customer_statuses' => array(), // Each status maps to an enabled flag plus a template name.
			'admin_new_order'   => array(
				'enabled'  => false,
				'template' => '',
			),
		);
		$stored   = get_option( self::SETTINGS_KEY, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Notify the customer when an order reaches a status they've enabled.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param object $order    The order object.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		unset( $from );

		$config   = $this->config();
		$statuses = isset( $config['customer_statuses'] ) ? $config['customer_statuses'] : array();
		$rule     = isset( $statuses[ $to ] ) ? $statuses[ $to ] : array();

		if ( empty( $rule['enabled'] ) || empty( $rule['template'] ) ) {
			return;
		}
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$phone = $this->normalise_number( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			return;
		}

		// No double-send: mark the order per status.
		$flag = '_dukarelay_notified_' . sanitize_key( $to );
		if ( $order->get_meta( $flag ) ) {
			return;
		}

		$vars = $this->customer_vars( $order, $to );
		$this->dispatcher->dispatch(
			array(
				'to'       => $phone,
				'type'     => 'template',
				'template' => $this->build_template( $rule['template'], $config['language'], $vars ),
				'kind'     => 'notification',
				'category' => 'utility',
				'order_id' => (int) $order->get_id(),
				'body'     => $this->summary( $vars ),
			)
		);

		$order->update_meta_data( $flag, current_time( 'mysql' ) );
		$order->save();
	}

	/**
	 * Notify the owner (Primary Number) of a new order, if enabled.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_new_order( $order_id ) {
		$config = $this->config();
		$rule   = isset( $config['admin_new_order'] ) ? $config['admin_new_order'] : array();
		if ( empty( $rule['enabled'] ) || empty( $rule['template'] ) ) {
			return;
		}

		$primary = $this->settings->get_primary_number();
		if ( '' === $primary ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$flag = '_dukarelay_admin_notified';
		if ( $order->get_meta( $flag ) ) {
			return;
		}

		$vars = $this->customer_vars( $order, $order->get_status() );
		$this->dispatcher->dispatch(
			array(
				'to'       => $primary,
				'type'     => 'template',
				'template' => $this->build_template( $rule['template'], $config['language'], $vars ),
				'kind'     => 'notification',
				'category' => 'utility',
				'order_id' => (int) $order->get_id(),
				'body'     => $this->summary( $vars ),
			)
		);

		$order->update_meta_data( $flag, current_time( 'mysql' ) );
		$order->save();
	}

	/**
	 * Extract notification variables from an order. Includes the item-name and
	 * tracking variables competitors miss (09).
	 *
	 * @param object $order  The order.
	 * @param string $status Status label.
	 * @return array<string,string>
	 */
	private function customer_vars( $order, $status ) {
		$items = array();
		if ( method_exists( $order, 'get_items' ) ) {
			foreach ( (array) $order->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_name' ) ) {
					$items[] = $item->get_name();
				}
			}
		}

		return array(
			'customer_name'   => (string) $order->get_billing_first_name(),
			'order_number'    => (string) $order->get_order_number(),
			'order_total'     => (string) $order->get_total(),
			'order_status'    => (string) $status,
			'item_names'      => implode( ', ', $items ),
			'tracking_number' => (string) $order->get_meta( '_dukarelay_tracking_number' ),
			'tracking_url'    => (string) $order->get_meta( '_dukarelay_tracking_url' ),
		);
	}

	/**
	 * Build a template payload for the sender: name + language + body parameters
	 * in a documented order. (The Templates subsystem will make this mapping
	 * configurable; for now the template must expect this parameter order.)
	 *
	 * @param string $template_name Approved template name.
	 * @param string $language      Language code.
	 * @param array  $vars          Variables from customer_vars().
	 * @return array
	 */
	private function build_template( $template_name, $language, array $vars ) {
		$ordered = array(
			$vars['customer_name'],
			$vars['order_number'],
			$vars['item_names'],
			$vars['order_total'],
			$vars['order_status'],
		);

		$parameters = array();
		foreach ( $ordered as $value ) {
			$parameters[] = array(
				'type' => 'text',
				'text' => '' !== $value ? $value : '-',
			);
		}

		return array(
			'name'       => $template_name,
			'language'   => $language,
			'components' => array(
				array(
					'type'       => 'body',
					'parameters' => $parameters,
				),
			),
		);
	}

	/**
	 * A short human-readable summary stored in the ledger body.
	 *
	 * @param array $vars Variables.
	 * @return string
	 */
	private function summary( array $vars ) {
		return sprintf(
			'Order %1$s (%2$s) -> %3$s',
			$vars['order_number'],
			$vars['order_total'],
			$vars['order_status']
		);
	}

	/**
	 * Normalise a phone number toward E.164 without ever mutating the order.
	 *
	 * @param string $number Raw billing phone.
	 * @return string
	 */
	private function normalise_number( $number ) {
		$number = trim( $number );
		if ( '' === $number ) {
			return '';
		}
		$has_plus = ( 0 === strpos( $number, '+' ) );
		$digits   = preg_replace( '/\D+/', '', $number );
		if ( '' === $digits ) {
			return '';
		}
		return $has_plus ? '+' . $digits : $digits;
	}
}
