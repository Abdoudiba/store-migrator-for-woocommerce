<?php
/**
 * Remove everything the plugin stored: the two custom tables, its options, and
 * the per-run upload directory. Runs only on "Delete" from the Plugins screen.
 *
 * @package Store_Migrator_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off teardown of the plugin's own custom tables on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'stwm_map' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'stwm_log' ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'stwm_db_version' );
delete_option( 'stwm_runs' );
delete_option( 'stwm_current_run' );
delete_option( 'stwm_test_csv' );
delete_option( 'stwm_test_last_run' );

// Uploaded CSVs and per-run index files under uploads/stwm/.
$stwm_uploads = wp_upload_dir();
$stwm_dir     = trailingslashit( $stwm_uploads['basedir'] ) . 'stwm';
if ( is_dir( $stwm_dir ) ) {
	$stwm_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $stwm_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $stwm_items as $stwm_item ) {
		if ( $stwm_item->isDir() ) {
			rmdir( $stwm_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- plugin-created dir under uploads/.
		} else {
			wp_delete_file( $stwm_item->getPathname() );
		}
	}
	rmdir( $stwm_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- plugin-created dir under uploads/.
}
