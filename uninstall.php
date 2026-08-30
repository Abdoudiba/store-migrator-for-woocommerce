<?php
/**
 * Remove everything the plugin stored: the ID-map table and its options.
 * Runs only on "Delete" from the Plugins screen, not on deactivate.
 *
 * @package Shopify_To_WooCommerce_Migrator
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off teardown of a custom table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}stwm_map" );

delete_option( 'stwm_db_version' );
delete_option( 'stwm_runs' );
delete_option( 'stwm_current_run' );
