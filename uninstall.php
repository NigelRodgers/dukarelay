<?php
/**
 * Uninstall cleanup: drops our tables and deletes our options. Runs only via
 * WordPress's official uninstall path (guarded below), never on deactivation.
 *
 * @package DukaRelay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop our custom tables. Table names are identifiers built from the trusted
// $wpdb->prefix, so they cannot be bound as prepared placeholders (those are
// for values). Direct query + interpolation is correct here.
$dukarelay_messages_table      = $wpdb->prefix . 'dukarelay_messages';
$dukarelay_conversations_table = $wpdb->prefix . 'dukarelay_conversations';
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-time uninstall cleanup of our own tables; identifiers cannot be parameterized.
$wpdb->query( "DROP TABLE IF EXISTS {$dukarelay_messages_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$dukarelay_conversations_table}" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete our options.
delete_option( 'dukarelay_db_version' );
delete_option( 'dukarelay_settings' );
delete_option( 'dukarelay_credentials' );
delete_option( 'dukarelay_enabled_modules' ); // legacy key (pre-Settings); harmless if absent.
