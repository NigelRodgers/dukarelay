<?php
/**
 * Dispatcher: the single front door for sending. Records one ledger row per
 * message, tries the available senders in priority order, and updates that row
 * with the outcome. Owns the bookkeeping + fallback logic so senders can stay
 * pure delivery. See docs/dev/dispatcher.md.
 *
 * Exactly one row per message; never fails silently; shop-blind; never throws.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound send orchestrator.
 */
class DukaRelay_Dispatcher {

	/**
	 * The message ledger.
	 *
	 * @var DukaRelay_Ledger
	 */
	private $ledger;

	/**
	 * Constructor.
	 *
	 * @param DukaRelay_Ledger $ledger The ledger service.
	 */
	public function __construct( DukaRelay_Ledger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * Send a message: record it, try senders in order, update the row.
	 *
	 * @param array $message {
	 *     Provider-agnostic message + ledger metadata.
	 *     @type string $to           Recipient E.164 (required).
	 *     @type string $type         'template' | 'text'.
	 *     @type array  $template     Template name/language/components (type=template).
	 *     @type string $body         Text body (type=text).
	 *     @type string $kind         Ledger kind: 'notification'|'forward'|'auto_reply'|'general'.
	 *     @type string $category     'marketing'|'utility'|'service' (cap accounting).
	 *     @type int    $order_id      Optional Woo order id (module-set).
	 *     @type int    $template_id   Optional template id.
	 *     @type string $store_number Store Number E.164.
	 * }
	 * @return array{ok:bool,message_id:int,wa_message_id:string,status:string,error:string}
	 */
	public function dispatch( array $message ) {
		// 1. Record first, as 'queued', so the message exists before we try to send.
		$message_id = $this->ledger->record(
			array(
				'direction'    => 'out',
				'kind'         => isset( $message['kind'] ) ? $message['kind'] : 'general',
				'peer_number'  => isset( $message['to'] ) ? $message['to'] : '',
				'store_number' => isset( $message['store_number'] ) ? $message['store_number'] : '',
				'category'     => isset( $message['category'] ) ? $message['category'] : '',
				'order_id'     => isset( $message['order_id'] ) ? $message['order_id'] : 0,
				'template_id'  => isset( $message['template_id'] ) ? $message['template_id'] : 0,
				'body'         => isset( $message['body'] ) ? $message['body'] : '',
				'status'       => 'queued',
			)
		);

		if ( ! $message_id ) {
			return $this->result( false, 0, '', 'failed', __( 'Could not record the message (invalid recipient?).', 'dukarelay' ) );
		}

		// 2. Resolve senders in priority order (Cloud API today; fallbacks add themselves).
		$senders    = apply_filters( 'dukarelay_senders', array() );
		$last_error = __( 'No sender was available to deliver the message.', 'dukarelay' );

		// 3. Try each ready sender until one succeeds.
		foreach ( $senders as $sender ) {
			if ( ! $sender instanceof DukaRelay_Sender || ! $sender->is_ready() ) {
				continue;
			}

			$outcome = $sender->send( $message );

			if ( ! empty( $outcome['ok'] ) ) {
				$wa_id = isset( $outcome['wa_message_id'] ) ? $outcome['wa_message_id'] : '';
				$this->ledger->update_result( $message_id, 'sent', $wa_id );
				return $this->result( true, $message_id, $wa_id, 'sent', '' );
			}

			if ( ! empty( $outcome['error'] ) ) {
				$last_error = $outcome['error'];
			}
		}

		// 4. Nothing delivered — record the failure with the last decoded reason.
		$this->ledger->update_result( $message_id, 'failed', '', $last_error );
		return $this->result( false, $message_id, '', 'failed', $last_error );
	}

	/**
	 * Shape a dispatch result consistently.
	 *
	 * @param bool   $ok            Whether delivery succeeded.
	 * @param int    $message_id    Ledger row id (0 if not recorded).
	 * @param string $wa_message_id WhatsApp message id on success.
	 * @param string $status        Final status.
	 * @param string $error         Decoded reason on failure.
	 * @return array{ok:bool,message_id:int,wa_message_id:string,status:string,error:string}
	 */
	private function result( $ok, $message_id, $wa_message_id, $status, $error ) {
		return array(
			'ok'            => (bool) $ok,
			'message_id'    => (int) $message_id,
			'wa_message_id' => (string) $wa_message_id,
			'status'        => $status,
			'error'         => $error,
		);
	}
}
