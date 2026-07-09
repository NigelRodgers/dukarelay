<?php
/**
 * Activation: create the database tables.
 *
 * WHAT THIS FILE IS (plain English):
 * When the user clicks "Activate", WordPress runs this once. It creates the two
 * custom tables that hold every WhatsApp message (the "Message Ledger", ADR-0002)
 * and the conversations they belong to. We use WordPress's dbDelta() helper,
 * which is smart: run it again later with a changed schema and it *upgrades* the
 * table instead of erroring — so this same file handles future schema changes.
 *
 * These tables are the platform rough-in: release 0.1 barely uses some columns
 * (context_message_id is for 1.0 swipe-reply routing; order_id is filled by the
 * WooCommerce module), but shaping them correctly now avoids a painful migration
 * later. See docs/ for the full data-model rationale.
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
	 * Bump this whenever the table structure below changes. Compared against the
	 * stored option so we know when to re-run dbDelta on upgrade.
	 */
	const DB_VERSION = '1';

	/**
	 * Run on activation. Creates both custom tables.
	 */
	public static function activate() {
		global $wpdb;

		// dbDelta lives in this file; it is not loaded by default.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// WordPress tells us the correct character set / collation to use.
		$charset_collate = $wpdb->get_charset_collate();

		$messages_table      = $wpdb->prefix . 'dukarelay_messages';
		$conversations_table = $wpdb->prefix . 'dukarelay_conversations';

		// --- Conversations: one row per person we talk to (a customer, or the Primary) ---
		$sql_conversations = "CREATE TABLE {$conversations_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			peer_number VARCHAR(20) NOT NULL,
			is_special_peer TINYINT(1) NOT NULL DEFAULT 0,
			last_message_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY peer_number (peer_number)
		) {$charset_collate};";

		// --- Messages: the ledger. Every message in/out, every kind, one table ---
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
