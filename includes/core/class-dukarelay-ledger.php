<?php
/**
 * Ledger: the single read/write layer over the message + conversation tables.
 * Everything else (Sender, Webhook, delivery log, cap meter) goes through this
 * class — nobody else runs SQL against these tables. See ADR-0002 and
 * docs/dev/ledger.md.
 *
 * Shop-blind (ADR-0003): stores an optional order_id but knows nothing about
 * orders. Enforces "never fail silently": a failed message always carries a
 * decoded reason.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read/write access to the message ledger.
 */
class DukaRelay_Ledger {

	/**
	 * Message categories that consume WhatsApp's business-initiated cap.
	 *
	 * @var string[]
	 */
	private $cap_categories = array( 'marketing', 'utility' );

	/**
	 * Fully-qualified messages table name.
	 *
	 * @return string
	 */
	private function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'dukarelay_messages';
	}

	/**
	 * Fully-qualified conversations table name.
	 *
	 * @return string
	 */
	private function conversations_table() {
		global $wpdb;
		return $wpdb->prefix . 'dukarelay_conversations';
	}

	/**
	 * Find the conversation for a phone number, creating it if absent.
	 *
	 * @param string $peer_number     Peer in E.164.
	 * @param bool   $is_special_peer  True for the Primary Number (operator).
	 * @return int Conversation id, or 0 on failure.
	 */
	public function upsert_conversation( $peer_number, $is_special_peer = false ) {
		global $wpdb;

		$peer_number = $this->normalise_number( $peer_number );
		if ( '' === $peer_number ) {
			return 0;
		}

		$table = $this->conversations_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, real-time lookup.
		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE peer_number = %s', $table, $peer_number )
		);

		if ( $existing ) {
			return (int) $existing;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table insert.
		$wpdb->insert(
			$table,
			array(
				'peer_number'     => $peer_number,
				'is_special_peer' => $is_special_peer ? 1 : 0,
				'created_at'      => $this->now(),
			),
			array( '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Record a message. Finds/creates its conversation, applies defaults and
	 * timestamps, and returns the new row id.
	 *
	 * @param array $data {
	 *     Message fields. Unknown keys are ignored.
	 *     @type string $direction          'in' | 'out' (required).
	 *     @type string $kind               'notification'|'forward'|'auto_reply'|'general'.
	 *     @type string $peer_number        Customer/operator number, E.164 (required).
	 *     @type string $store_number       The Store Number, E.164.
	 *     @type string $wa_message_id      Meta message id.
	 *     @type string $context_message_id Replied-to message id (1.0 routing).
	 *     @type int    $order_id           Optional Woo order id (module-set).
	 *     @type int    $template_id         Optional template id.
	 *     @type string $category           'marketing'|'utility'|'service'.
	 *     @type string $status             Delivery status; defaults 'queued'.
	 *     @type string $error              Decoded failure reason.
	 *     @type string $body               Message content.
	 * }
	 * @return int New message id, or 0 on failure.
	 */
	public function record( array $data ) {
		global $wpdb;

		$peer_number = $this->normalise_number( isset( $data['peer_number'] ) ? $data['peer_number'] : '' );
		$direction   = isset( $data['direction'] ) && 'in' === $data['direction'] ? 'in' : 'out';
		if ( '' === $peer_number ) {
			return 0;
		}

		$is_special      = ! empty( $data['is_special_peer'] );
		$conversation_id = $this->upsert_conversation( $peer_number, $is_special );
		$now             = $this->now();

		$row = array(
			'conversation_id'    => $conversation_id ? $conversation_id : null,
			'direction'          => $direction,
			'kind'               => isset( $data['kind'] ) ? sanitize_key( $data['kind'] ) : 'general',
			'store_number'       => $this->normalise_number( isset( $data['store_number'] ) ? $data['store_number'] : '' ),
			'peer_number'        => $peer_number,
			'wa_message_id'      => isset( $data['wa_message_id'] ) ? sanitize_text_field( $data['wa_message_id'] ) : null,
			'context_message_id' => isset( $data['context_message_id'] ) ? sanitize_text_field( $data['context_message_id'] ) : null,
			'order_id'           => isset( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
			'template_id'        => isset( $data['template_id'] ) ? absint( $data['template_id'] ) : null,
			'category'           => isset( $data['category'] ) ? sanitize_key( $data['category'] ) : null,
			'status'             => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'queued',
			'error'              => isset( $data['error'] ) ? sanitize_textarea_field( $data['error'] ) : null,
			'body'               => isset( $data['body'] ) ? wp_kses_post( $data['body'] ) : null,
			'created_at'         => $now,
			'updated_at'         => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table insert.
		$wpdb->insert( $this->messages_table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a message's delivery status, located by its WhatsApp message id
	 * (used when Meta's webhook reports delivered/read/failed).
	 *
	 * @param string $wa_message_id Meta message id.
	 * @param string $status        New status.
	 * @param string $error         Decoded reason (required when status is 'failed').
	 * @return bool True if a row was updated.
	 */
	public function update_status_by_wa_id( $wa_message_id, $status, $error = '' ) {
		global $wpdb;

		$wa_message_id = sanitize_text_field( $wa_message_id );
		$status        = sanitize_key( $status );
		if ( '' === $wa_message_id ) {
			return false;
		}

		$data = array(
			'status'     => $status,
			'error'      => ( '' !== $error ) ? sanitize_textarea_field( $error ) : null,
			'updated_at' => $this->now(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update.
		$updated = $wpdb->update( $this->messages_table(), $data, array( 'wa_message_id' => $wa_message_id ) );

		return (bool) $updated;
	}

	/**
	 * Update a message row by its own id with a send outcome. Used by the
	 * dispatcher to write the result onto the row it created as 'queued'.
	 *
	 * @param int    $message_id    Ledger row id.
	 * @param string $status        New status ('sent'|'failed'|...).
	 * @param string $wa_message_id WhatsApp message id (set on success).
	 * @param string $error         Decoded reason (set on failure).
	 * @return bool True if a row was updated.
	 */
	public function update_result( $message_id, $status, $wa_message_id = '', $error = '' ) {
		global $wpdb;

		$message_id = absint( $message_id );
		if ( ! $message_id ) {
			return false;
		}

		$data = array(
			'status'     => sanitize_key( $status ),
			'error'      => ( '' !== $error ) ? sanitize_textarea_field( $error ) : null,
			'updated_at' => $this->now(),
		);
		if ( '' !== $wa_message_id ) {
			$data['wa_message_id'] = sanitize_text_field( $wa_message_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update.
		$updated = $wpdb->update( $this->messages_table(), $data, array( 'id' => $message_id ) );

		return (bool) $updated;
	}

	/**
	 * Fetch a single message row by id.
	 *
	 * @param int $id Message id.
	 * @return array|null Associative row, or null.
	 */
	public function get_message( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table lookup.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->messages_table(), $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Query messages for the delivery log. Always bounded by a limit.
	 *
	 * @param array $args {
	 *     @type string $direction Filter by 'in'|'out'.
	 *     @type string $status    Filter by status.
	 *     @type int    $limit     Max rows (default 50, hard cap 500).
	 *     @type int    $offset    Offset for paging.
	 * }
	 * @return array[] List of associative rows (newest first).
	 */
	public function query_messages( array $args = array() ) {
		global $wpdb;

		$limit  = min( max( (int) ( isset( $args['limit'] ) ? $args['limit'] : 50 ), 1 ), 500 );
		$offset = max( (int) ( isset( $args['offset'] ) ? $args['offset'] : 0 ), 0 );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['direction'] ) ) {
			$where[]  = 'direction = %s';
			$params[] = 'in' === $args['direction'] ? 'in' : 'out';
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		$where_sql = implode( ' AND ', $where );

		// Table name via %i; all filter values are parameterised below.
		$sql    = "SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params = array_merge( array( $this->messages_table() ), $params, array( $limit, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where_sql is built only from fixed fragments; all values are parameterised.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * Count business-initiated (cap-consuming) messages sent in the last 24h.
	 * Powers the 250/24h transparency meter. Service-category and inbound
	 * messages are excluded because they don't consume the cap.
	 *
	 * @return int
	 */
	public function count_business_initiated_last_24h() {
		global $wpdb;

		$since        = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$placeholders = implode( ', ', array_fill( 0, count( $this->cap_categories ), '%s' ) );

		$sql    = "SELECT COUNT(*) FROM %i WHERE direction = 'out' AND category IN ({$placeholders}) AND created_at >= %s";
		$params = array_merge( array( $this->messages_table() ), $this->cap_categories, array( $since ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is a fixed list of %s; all values are parameterised.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Current UTC timestamp in MySQL format.
	 *
	 * @return string
	 */
	private function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Light normalisation of a phone number toward E.164 (strip spaces and
	 * common separators; keep a leading +). Full validation lives with the
	 * sender/connection; this just keeps storage tidy.
	 *
	 * @param string $number Raw number.
	 * @return string
	 */
	private function normalise_number( $number ) {
		$number = trim( (string) $number );
		if ( '' === $number ) {
			return '';
		}
		$has_plus = ( 0 === strpos( $number, '+' ) );
		$digits   = preg_replace( '/\D+/', '', $number );
		return $has_plus ? '+' . $digits : $digits;
	}
}
