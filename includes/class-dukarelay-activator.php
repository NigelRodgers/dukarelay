<?php
/**
 * Creates the ledger tables via dbDelta (which also upgrades them on later
 * schema changes). See ADR-0002 for the single-ledger data model.
 *
 * Some columns are intentional rough-ins: context_message_id is for 1.0
 * swipe-reply routing; order_id is written by the WooCommerce module;
 * category (marketing/utility/service) feeds the 250/24h cap meter + pricing.
 *
 * @package DukaRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates/updates our tables and stores the schema version.
 */
class DukaRelay_Activator {

	/**
	 * Bump when the schema below changes; compared against the stored option to
	 * decide when to re-run dbDelta on upgrade.
	 */
	const DB_VERSION = '1';

	/**
	 * Create/upgrade both custom tables.
	 */
	public static function activate() {
		global $wpdb;

		// dbDelta() is not loaded by default.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$messages_table      = $wpdb->prefix . 'dukarelay_messages';
		$conversations_table = $wpdb->prefix . 'dukarelay_conversations';

		// Conversations: one row per peer (a customer, or the Primary Number).
		$sql_conversations = "CREATE TABLE {$conversations_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			peer_number VARCHAR(20) NOT NULL,
			is_special_peer TINYINT(1) NOT NULL DEFAULT 0,
			last_message_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY peer_number (peer_number)
		) {$charset_collate};";

		// Messages: the ledger — every message, both directions, one table.
		$sql_messages = "CREATE TABLE {$messages_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NULL,
			direction VARCHAR(3) NOT NULL,
			kind VARCHAR(20) NOT NULL,
			store_number VARCHAR(20) NULL,
			peer_number VARCHAR(20) NULL,
			wa_message_id VARCHAR(128) NULL,
			context_message_id VARCHAR(128) NULL,
			order_id BIGINT UNSIGNED NULL,
			template_id BIGINT UNSIGNED NULL,
			category VARCHAR(20) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			error TEXT NULL,
			body LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY peer_number (peer_number),
			KEY wa_message_id (wa_message_id),
			KEY context_message_id (context_message_id),
			KEY order_id (order_id)
		) {$charset_collate};";

		dbDelta( $sql_conversations );
		dbDelta( $sql_messages );

		update_option( 'dukarelay_db_version', self::DB_VERSION );
	}
}
