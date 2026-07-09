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

// Drop our custom tables.
$messages_table      = $wpdb->prefix . 'dukarelay_messages';
$conversations_table = $wpdb->prefix . 'dukarelay_conversations';
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time uninstall cleanup, no caching relevant.
$wpdb->query( "DROP TABLE IF EXISTS {$messages_table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$conversations_table}" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery

// Delete our options.
delete_option( 'dukarelay_db_version' );
delete_option( 'dukarelay_enabled_modules' );
delete_option( 'dukarelay_credentials' );
