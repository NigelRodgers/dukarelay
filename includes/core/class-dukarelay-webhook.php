<?php
/**
 * Webhook: the plugin's inbound door. Registers a public REST route that Meta
 * calls, verifies every POST with the X-Hub-Signature-256 signature (the single
 * most important security check in the plugin), records inbound messages and
 * status updates via the Ledger, and fires events so other parts of the plugin
 * can react. See docs/dev/webhook.md.
 *
 * Record-then-announce: this class does NOT forward or auto-reply — it emits
 * 'dukarelay_inbound_message' / 'dukarelay_message_status' and handlers do the
 * rest. Shop-blind.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WhatsApp Cloud API webhook receiver.
 */
class DukaRelay_Webhook {

	const NAMESPACE = 'dukarelay/v1';
	const ROUTE     = '/webhook';

	/**
	 * Credential provider (app secret + verify token).
	 *
	 * @var DukaRelay_Connection
	 */
	private $connection;

	/**
	 * Message ledger.
	 *
	 * @var DukaRelay_Ledger
	 */
	private $ledger;

	/**
	 * Constructor. Hooks route registration onto rest_api_init.
	 *
	 * @param DukaRelay_Connection $connection Credential service.
	 * @param DukaRelay_Ledger     $ledger     Ledger service.
	 */
	public function __construct( DukaRelay_Connection $connection, DukaRelay_Ledger $ledger ) {
		$this->connection = $connection;
		$this->ledger     = $ledger;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the public webhook route (GET verify handshake + POST events).
	 * The route is public because Meta must reach it; POSTs are authenticated by
	 * signature inside the handler, not by a permission callback.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_verification' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_event' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Handle Meta's GET verification handshake. Echoes the challenge only when
	 * the presented verify token matches ours.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_verification( $request ) {
		$mode      = $request->get_param( 'hub_mode' );
		$token     = $request->get_param( 'hub_verify_token' );
		$challenge = $request->get_param( 'hub_challenge' );

		$expected = $this->connection->get( 'verify_token' );

		if ( 'subscribe' === $mode && '' !== $expected && hash_equals( (string) $expected, (string) $token ) ) {
			// Meta expects the raw challenge echoed back as the body.
			return new WP_REST_Response( $challenge, 200 );
		}

		return new WP_Error( 'dukarelay_verify_failed', 'Verification failed.', array( 'status' => 403 ) );
	}

	/**
	 * Handle a POST event: verify signature, then process statuses + messages.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_event( $request ) {
		$raw       = $request->get_body();
		$signature = $request->get_header( 'x_hub_signature_256' );

		if ( ! $this->verify_signature( $raw, (string) $signature ) ) {
			return new WP_Error( 'dukarelay_bad_signature', 'Invalid signature.', array( 'status' => 403 ) );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) || empty( $payload['entry'] ) || ! is_array( $payload['entry'] ) ) {
			// Acknowledge so Meta doesn't retry a payload we simply don't act on.
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		foreach ( $payload['entry'] as $entry ) {
			if ( empty( $entry['changes'] ) || ! is_array( $entry['changes'] ) ) {
				continue;
			}
			foreach ( $entry['changes'] as $change ) {
				$value = isset( $change['value'] ) && is_array( $change['value'] ) ? $change['value'] : array();
				$this->process_statuses( $value );
				$this->process_messages( $value );
			}
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Verify Meta's X-Hub-Signature-256 header against the raw body using the
	 * app secret. Constant-time comparison; rejects if either is missing.
	 *
	 * @param string $raw       Raw request body.
	 * @param string $signature Header value, e.g. "sha256=abc...".
	 * @return bool
	 */
	private function verify_signature( $raw, $signature ) {
		$app_secret = $this->connection->get( 'app_secret' );
		if ( '' === $app_secret || '' === $signature ) {
			return false;
		}
		if ( 0 !== strpos( $signature, 'sha256=' ) ) {
			return false;
		}
		$provided = substr( $signature, 7 );
		$expected = hash_hmac( 'sha256', $raw, $app_secret );
		return hash_equals( $expected, $provided );
	}

	/**
	 * Update the ledger from any delivery-status entries in the payload value.
	 *
	 * @param array $value Webhook change value.
	 * @return void
	 */
	private function process_statuses( array $value ) {
		if ( empty( $value['statuses'] ) || ! is_array( $value['statuses'] ) ) {
			return;
		}
		foreach ( $value['statuses'] as $status ) {
			$wa_id = isset( $status['id'] ) ? (string) $status['id'] : '';
			$state = isset( $status['status'] ) ? (string) $status['status'] : '';
			if ( '' === $wa_id || '' === $state ) {
				continue;
			}
			$error = '';
			if ( ! empty( $status['errors'][0]['title'] ) ) {
				$error = (string) $status['errors'][0]['title'];
				if ( ! empty( $status['errors'][0]['message'] ) ) {
					$error = (string) $status['errors'][0]['message'];
				}
			}
			$this->ledger->update_status_by_wa_id( $wa_id, $state, $error );

			/**
			 * Fires after a delivery status is recorded.
			 *
			 * @param string $wa_id Message id. @param string $state Status. @param string $error Reason.
			 */
			do_action( 'dukarelay_message_status', $wa_id, $state, $error );
		}
	}

	/**
	 * Record inbound messages and announce them for handlers (forward/auto-reply).
	 *
	 * @param array $value Webhook change value.
	 * @return void
	 */
	private function process_messages( array $value ) {
		if ( empty( $value['messages'] ) || ! is_array( $value['messages'] ) ) {
			return;
		}

		$store_number = '';
		if ( ! empty( $value['metadata']['display_phone_number'] ) ) {
			$store_number = (string) $value['metadata']['display_phone_number'];
		}

		foreach ( $value['messages'] as $message ) {
			$from = isset( $message['from'] ) ? (string) $message['from'] : '';
			if ( '' === $from ) {
				continue;
			}

			$body    = $this->extract_body( $message );
			$context = isset( $message['context']['id'] ) ? (string) $message['context']['id'] : '';

			$ledger_id = $this->ledger->record(
				array(
					'direction'          => 'in',
					'kind'               => 'general',
					'peer_number'        => $from,
					'store_number'       => $store_number,
					'wa_message_id'      => isset( $message['id'] ) ? (string) $message['id'] : '',
					'context_message_id' => $context,
					'category'           => 'service',
					'status'             => 'received',
					'body'               => $body,
				)
			);

			/**
			 * Fires after an inbound message is recorded. Handlers forward it to
			 * the Primary Number and/or send an auto-reply.
			 *
			 * @param array $message   The raw inbound message from Meta.
			 * @param int   $ledger_id The ledger row id just created.
			 */
			do_action( 'dukarelay_inbound_message', $message, $ledger_id );
		}
	}

	/**
	 * Extract a human-readable body from an inbound message of any type.
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
		// Non-text (image/audio/location/etc.) — store a placeholder label.
		return '' !== $type ? '[' . $type . ']' : '';
	}
}
