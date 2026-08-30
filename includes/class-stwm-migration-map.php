<?php
/**
 * CRUD for the Shopify-source -> WooCommerce-target ID map.
 *
 * Every object the migrator creates gets a row here keyed by
 * (entity_type, source_id). That gives us:
 *   - idempotent runs: re-importing a product updates it instead of duplicating;
 *   - relational stitching: an order's line items resolve their product IDs, and
 *     the order resolves its customer ID, by looking here;
 *   - rollback: delete every WooCommerce object created by a given run_id;
 *   - incremental mode (later): skip source IDs already present.
 *
 * entity_type is one of: product, variation, category, customer, order, coupon,
 * redirect, page, post.
 *
 * @package Shopify_To_WooCommerce_Migrator
 */

defined( 'ABSPATH' ) || exit;

class STWM_Migration_Map {

	/**
	 * @return string Prefixed table name.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'stwm_map';
	}

	/**
	 * Resolve the WooCommerce/WordPress ID a Shopify entity was mapped to.
	 *
	 * @param string     $entity_type e.g. "product".
	 * @param string|int $source_id   Shopify id (numeric or GID string).
	 * @return int WordPress ID, or 0 if not mapped.
	 */
	public static function get_target( $entity_type, $source_id ) {
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT target_id FROM %i WHERE entity_type = %s AND source_id = %s',
				self::table(),
				$entity_type,
				(string) $source_id
			)
		);
		return $val ? (int) $val : 0;
	}

	/**
	 * Reverse lookup: the Shopify source id behind a WooCommerce object.
	 *
	 * @return string Empty string if not mapped.
	 */
	public static function get_source( $entity_type, $target_id ) {
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT source_id FROM %i WHERE entity_type = %s AND target_id = %d',
				self::table(),
				$entity_type,
				(int) $target_id
			)
		);
		return $val ? (string) $val : '';
	}

	/**
	 * Insert or update a mapping row.
	 *
	 * @param array $args {
	 *     @type string     $run_id
	 *     @type string     $entity_type Required.
	 *     @type string|int $source_id   Required.
	 *     @type string     $source_ref  Handle / email / order name, for fallback matching.
	 *     @type int        $target_id
	 *     @type string     $status      "ok" | "skipped" | "error".
	 *     @type string     $message     Short reason, shown in the run report.
	 * }
	 * @return bool
	 */
	public static function set( array $args ) {
		global $wpdb;

		$data = wp_parse_args(
			$args,
			array(
				'run_id'      => '',
				'entity_type' => '',
				'source_id'   => '',
				'source_ref'  => '',
				'target_id'   => 0,
				'status'      => 'ok',
				'message'     => '',
			)
		);

		if ( '' === $data['entity_type'] || '' === (string) $data['source_id'] ) {
			return false;
		}

		$now    = current_time( 'mysql', true );
		$row_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE entity_type = %s AND source_id = %s',
				self::table(),
				$data['entity_type'],
				(string) $data['source_id']
			)
		);

		if ( $row_id ) {
			return false !== $wpdb->update(
				self::table(),
				array(
					'run_id'     => $data['run_id'],
					'source_ref' => $data['source_ref'],
					'target_id'  => (int) $data['target_id'],
					'status'     => $data['status'],
					'message'    => mb_substr( (string) $data['message'], 0, 255 ),
					'updated_at' => $now,
				),
				array( 'id' => $row_id )
			);
		}

		return false !== $wpdb->insert(
			self::table(),
			array(
				'run_id'      => $data['run_id'],
				'entity_type' => $data['entity_type'],
				'source_id'   => (string) $data['source_id'],
				'source_ref'  => $data['source_ref'],
				'target_id'   => (int) $data['target_id'],
				'status'      => $data['status'],
				'message'     => mb_substr( (string) $data['message'], 0, 255 ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
	}

	/**
	 * Count mapped rows for a run, optionally filtered by entity type / status.
	 *
	 * @return int
	 */
	public static function count( $run_id, $entity_type = '', $status = '' ) {
		global $wpdb;
		$where  = array( 'run_id = %s' );
		$params = array( $run_id );
		if ( '' !== $entity_type ) {
			$where[]  = 'entity_type = %s';
			$params[] = $entity_type;
		}
		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		// $where is assembled only from the literal fragments above; every value
		// is bound through $wpdb->prepare().
		$sql = 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $where );
		array_unshift( $params, self::table() );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see comment above; fragments are fixed, values are placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * All rows for a run, newest first. Used by the report screen and rollback.
	 *
	 * @return array<int,object>
	 */
	public static function rows_for_run( $run_id, $entity_type = '' ) {
		global $wpdb;
		if ( '' !== $entity_type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE run_id = %s AND entity_type = %s ORDER BY id DESC',
					self::table(),
					$run_id,
					$entity_type
				)
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE run_id = %s ORDER BY id DESC',
				self::table(),
				$run_id
			)
		);
	}

	/**
	 * Forget every mapping row for a run. The caller is responsible for having
	 * already deleted the WooCommerce objects those rows point at.
	 *
	 * @return int Rows removed.
	 */
	public static function delete_run( $run_id ) {
		global $wpdb;
		return (int) $wpdb->delete( self::table(), array( 'run_id' => $run_id ) );
	}
}
