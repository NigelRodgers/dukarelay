<?php
/**
 * Uninstall cleanup.
 *
 * WHAT THIS FILE IS (plain English):
 * WordPress runs this ONLY when the user deletes the plugin (not on deactivate).
 * It removes everything we created: our two tables and our options. This keeps a
 * clean slate and satisfies WordPress.org's rule that plugins clean up after
 * themselves. It is deliberately destructive — that is the whole point of
 * uninstall — so it runs only via the official uninstall path guarded below.
 *
 * @package DukaRelay
 */

// Only run when WordPress itself is uninstalling this plugin. Never directly.
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
