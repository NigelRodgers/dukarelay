<?php
/**
 * The Sender contract.
 *
 * A "sender" is anything that can attempt to deliver a WhatsApp message and
 * report, in a decoded form, what happened. The v1 sender is the official Cloud
 * API; post-1.0 fallback senders implement this same interface so the send path
 * can try them in priority order (apply_filters 'dukarelay_senders') without a
 * rewrite.
 *
 * Enforces "never fail silently": send() must return a structured result with a
 * reason on failure — never a bare boolean.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every message sender must honour.
 */
interface DukaRelay_Sender {

	/**
	 * Stable identifier for this sender, e.g. 'cloud_api'. Used in the delivery
	 * log and to order senders by priority.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Whether this sender is currently able to send (configured + healthy). The
	 * send path skips senders that are not ready and moves to the next.
	 *
	 * @return bool
	 */
	public function is_ready();

	/**
	 * Attempt to deliver a message.
	 *
	 * The $message array is provider-agnostic; a sender maps it to its own API.
	 * Expected keys (not all required by every message type):
	 * - 'to'       (string) recipient in E.164.
	 * - 'type'     (string) e.g. 'template' | 'text'.
	 * - 'template' (array)  template name/language/variables, when type=template.
	 * - 'body'     (string) plain text, when type=text.
	 *
	 * @param array $message Provider-agnostic message payload.
	 * @return array{ok:bool,wa_message_id:string,status:string,error:string}
	 *               Always structured — 'error' carries a decoded reason on failure.
	 */
	public function send( array $message );
}
