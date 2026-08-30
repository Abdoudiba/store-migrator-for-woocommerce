<?php
/**
 * A "migration run" — one attempt at bringing a Shopify store (or part of it)
 * into WooCommerce. Runs are stored in a single option for now; if the history
 * grows this moves to its own table, but the public API here stays the same.
 *
 * Status lifecycle: draft -> analyzing -> running -> (paused) -> done | failed.
 *
 * @package Store_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class STWM_Run {

	const OPTION_RUNS    = 'stwm_runs';
	const OPTION_CURRENT = 'stwm_current_run';

	/**
	 * @param array $args Partial run data; sensible defaults fill the rest.
	 * @return string The new run id (32 hex chars).
	 */
	public static function create( array $args = array() ) {
		$run_id = substr( md5( uniqid( 'stwm', true ) ), 0, 32 );
		$now    = current_time( 'mysql', true );

		$run       = wp_parse_args(
			$args,
			array(
				'id'         => $run_id,
				'source'     => 'csv', // csv | api
				'status'     => 'draft',
				'entities'   => array(), // selected entity types, e.g. array( 'product', 'category' )
				'options'    => array(), // per-run toggles (download images, status mapping, ...)
				'stats'      => array(), // entity_type => array( total, done, skipped, error )
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$run['id'] = $run_id;

		$runs            = get_option( self::OPTION_RUNS, array() );
		$runs[ $run_id ] = $run;
		update_option( self::OPTION_RUNS, $runs, false );
		self::set_current( $run_id );

		return $run_id;
	}

	/**
	 * @return array|null The run, or null if unknown.
	 */
	public static function get( $run_id ) {
		$runs = get_option( self::OPTION_RUNS, array() );
		return isset( $runs[ $run_id ] ) ? $runs[ $run_id ] : null;
	}

	/**
	 * Shallow-merge changes into a run and bump updated_at.
	 *
	 * @return bool
	 */
	public static function update( $run_id, array $changes ) {
		$runs = get_option( self::OPTION_RUNS, array() );
		if ( ! isset( $runs[ $run_id ] ) ) {
			return false;
		}
		$runs[ $run_id ]               = array_merge( $runs[ $run_id ], $changes );
		$runs[ $run_id ]['id']         = $run_id;
		$runs[ $run_id ]['updated_at'] = current_time( 'mysql', true );
		return update_option( self::OPTION_RUNS, $runs, false );
	}

	/**
	 * @return array<string,array> All runs keyed by id, newest first.
	 */
	public static function all() {
		$runs = get_option( self::OPTION_RUNS, array() );
		uasort(
			$runs,
			static function ( $a, $b ) {
				return strcmp( $b['created_at'], $a['created_at'] );
			}
		);
		return $runs;
	}

	/**
	 * @return string Current run id, or '' if none.
	 */
	public static function current() {
		return (string) get_option( self::OPTION_CURRENT, '' );
	}

	public static function set_current( $run_id ) {
		update_option( self::OPTION_CURRENT, (string) $run_id, false );
	}
}
