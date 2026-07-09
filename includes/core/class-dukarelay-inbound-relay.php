<?php
/**
 * Inbound Relay: reacts to an inbound customer message by (1) forwarding it to
 * the owner's Primary Number and (2) sending the customer one auto-reply. Hooks
 * the dukarelay_inbound_message event fired by the Webhook. See
 * docs/dev/inbound-relay.md.
 *
 * Respects the Settings toggles, throttles auto-reply to once per 24h per
 * customer, and never treats the Primary (owner) as a customer.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forward-to-Primary and auto-reply handler.
 */
class DukaRelay_Inbound_Relay {

	/**
	 * Settings store.
	 *
	 * @var DukaRelay_Settings
	 */
	private $settings;

	/**
	 * Send dispatcher.
	 *
	 * @var DukaRelay_Dispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor. Subscribes to the inbound-message event.
	 *
	 * @param DukaRelay_Settings   $settings   Settings service.
	 * @param DukaRelay_Dispatcher $dispatcher Dispatcher service.
	 */
	public function __construct( DukaRelay_Settings $settings, DukaRelay_Dispatcher $dispatcher ) {
		$this->settings   = $settings;
		$this->dispatcher = $dispatcher;
		add_action( 'dukarelay_inbound_message', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * React to an inbound message: forward + auto-reply, unless it's from the
	 * Primary (owner) itself.
	 *
	 * @param array $message   Raw inbound message from Meta.
	 * @param int   $ledger_id The ledger row id (unused here; available to handlers).
	 * @return void
	 */
	public function handle( $message, $ledger_id ) {
		unset( $ledger_id );

		$from = isset( $message['from'] ) ? $this->digits( (string) $message['from'] ) : '';
		if ( '' === $from ) {
			return;
		}

		$primary = $this->digits( $this->settings->get_primary_number() );

		// Special-Peer rule: a message from the Primary is the owner, not a customer.
		if ( '' !== $primary && $from === $primary ) {
			return;
		}

		$this->maybe_forward( $message, $from );
		$this->maybe_auto_reply( $from );
	}

	/**
	 * Forward the inbound message to the Primary Number, if enabled and set.
	 *
	 * @param array  $message Raw inbound message.
	 * @param string $from    Customer number (digits).
	 * @return void
	 */
	private function maybe_forward( array $message, $from ) {
		if ( ! $this->settings->get( 'forward_enabled' ) ) {
			return;
		}
		$primary = $this->settings->get_primary_number();
		if ( '' === $primary ) {
			return;
		}

		$body = $this->extract_body( $message );
		/* translators: 1: customer phone number, 2: their message. */
		$text = sprintf( __( 'New WhatsApp message from %1$s: %2$s', 'dukarelay' ), $from, $body );

		$this->dispatcher->dispatch(
			array(
				'to'       => $primary,
				'type'     => 'text',
				'body'     => $text,
				'kind'     => 'forward',
				'category' => 'utility',
			)
		);
	}

	/**
	 * Send the customer one auto-reply per 24h window, if enabled.
	 *
	 * @param string $from Customer number (digits).
	 * @return void
	 */
	private function maybe_auto_reply( $from ) {
		if ( ! $this->settings->get( 'auto_reply_enabled' ) ) {
			return;
		}

		$throttle_key = 'dukarelay_ar_' . md5( $from );
		if ( get_transient( $throttle_key ) ) {
			return;
		}

		$text = (string) $this->settings->get( 'auto_reply_text' );
		if ( '' === $text ) {
			$text = __( 'Thanks for your message! We have received it and will get back to you shortly.', 'dukarelay' );
		}

		$this->dispatcher->dispatch(
			array(
				'to'       => $from,
				'type'     => 'text',
				'body'     => $text,
				'kind'     => 'auto_reply',
				'category' => 'service',
			)
		);

		// Remember we replied, so repeat messages in the same window don't loop.
		set_transient( $throttle_key, 1, DAY_IN_SECONDS );
	}

	/**
	 * Extract a readable body from an inbound message of any type.
	 *
	 * @param array $message Inbound message.
	 * @return string
	 */
	private function extract_body( array $message ) {
		$type = isset( $message['type'] ) ? (string) $message['type'] : '';
		if ( 'text' === $type && isset( $message['text']['body'] ) ) {
			return (string) $message['text']['body'];
		}
		if ( isset( $message[ $type ]['caption'] ) ) {
			return (string) $message[ $type ]['caption'];
		}
		return '' !== $type ? '[' . $type . ']' : '';
	}

	/**
	 * Digits-only form of a phone number, for reliable comparison.
	 *
	 * @param string $number Raw number.
	 * @return string
	 */
	private function digits( $number ) {
		return preg_replace( '/\D+/', '', (string) $number );
	}
}
