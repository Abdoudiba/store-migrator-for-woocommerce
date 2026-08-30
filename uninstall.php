<?php
/**
 * Remove everything the plugin stored: the ID-map table, its options, and the
 * per-run upload directory. Runs only on "Delete" from the Plugins screen.
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

// Uploaded CSVs and index files.
$upload = wp_upload_dir();
$dir    = trailingslashit( $upload['basedir'] ) . 'stwm';
if ( is_dir( $dir ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
