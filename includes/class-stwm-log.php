<?php
/**
 * Per-run log, persisted in the plugin's own table.
 *
 * WooCommerce's logger is unreliable as the single source of truth: on a store
 * with logging disabled (the default on many hosts) nothing is written at all,
 * and its file handler does not always emit for custom sources. A migration is
 * exactly when you need the record afterwards, so every row also lands in
 * wp_stwm_log here. Entries are still mirrored to wc_get_logger() so stores that
 * do have logging on keep a copy in WooCommerce → Status → Logs.
 *
 * @package Shopify_To_WooCommerce_Migrator
 */

defined( 'ABSPATH' ) || exit;

class STWM_Log {

	const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'stwm_log';
	}

	/**
	 * @param string $run_id
	 * @param string $level       One of self::LEVELS; anything else becomes "info".
	 * @param string $message
	 * @param string $entity_type product|variation|image|category|... (optional)
	 * @param string $source_id   Handle / SKU / URL the line is about (optional)
	 */
	public static function add( $run_id, $level, $message, $entity_type = '', $source_id = '' ) {
		global $wpdb;
		$level = in_array( $level, self::LEVELS, true ) ? $level : 'info';

		$wpdb->insert(
			self::table(),
			array(
				'run_id'      => (string) $run_id,
				'level'       => $level,
				'entity_type' => (string) $entity_type,
				'source_id'   => mb_substr( (string) $source_id, 0, 191 ),
				'message'     => (string) $message,
				'created_at'  => current_time( 'mysql', true ),
			)
		);

		// Mirror to WooCommerce's logger for stores that have logging enabled.
		$wc_level = 'error' === $level ? 'error' : ( 'warning' === $level ? 'warning' : 'info' );
		STWM_Logger::log( $wc_level, $message );
	}

	public static function info( $run_id, $message, $entity_type = '', $source_id = '' ) {
		self::add( $run_id, 'info', $message, $entity_type, $source_id );
	}

	public static function warning( $run_id, $message, $entity_type = '', $source_id = '' ) {
		self::add( $run_id, 'warning', $message, $entity_type, $source_id );
	}

	public static function error( $run_id, $message, $entity_type = '', $source_id = '' ) {
		self::add( $run_id, 'error', $message, $entity_type, $source_id );
	}

	/**
	 * @return int Count of rows for a run, optionally filtered by level.
	 */
	public static function count( $run_id, $level = '' ) {
		global $wpdb;
		if ( '' !== $level ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE run_id = %s AND level = %s', $run_id, $level )
			);
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE run_id = %s', $run_id )
		);
	}

	/**
	 * Newest rows first, for the report screen.
	 *
	 * @return array<int,object>
	 */
	public static function rows( $run_id, $limit = 100, $level = '' ) {
		global $wpdb;
		$limit = max( 1, (int) $limit );
		if ( '' !== $level ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' WHERE run_id = %s AND level = %s ORDER BY id DESC LIMIT %d',
					$run_id,
					$level,
					$limit
				)
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE run_id = %s ORDER BY id DESC LIMIT %d',
				$run_id,
				$limit
			)
		);
	}

	/**
	 * All rows oldest-first, for the CSV export.
	 *
	 * @return array<int,object>
	 */
	public static function all_for_csv( $run_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT created_at, level, entity_type, source_id, message FROM ' . self::table() . ' WHERE run_id = %s ORDER BY id ASC',
				$run_id
			)
		);
	}

	/**
	 * @return int Rows removed.
	 */
	public static function delete_run( $run_id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'run_id' => $run_id ) );
	}
}
