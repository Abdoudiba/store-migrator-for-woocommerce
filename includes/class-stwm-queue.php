<?php
/**
 * Batch engine, built on Action Scheduler (bundled with WooCommerce).
 *
 * Every unit of migration work is one async action carrying a small payload
 * (run id, entity type, offset, batch size). Action Scheduler runs them a few
 * at a time on WP-Cron / loopback requests, retries failures, and survives PHP
 * timeouts — which is what lets a 20k-product store migrate without a
 * long-running request. A processor that isn't finished simply enqueues the
 * next offset before returning.
 *
 * Milestone 1 registers the pipeline and logs batches; the per-entity
 * processors (product / category / customer / order / coupon) arrive in
 * milestone 2 and are dispatched from handle_batch().
 *
 * @package Store_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class STWM_Queue {

	const HOOK_BATCH = 'stwm_process_batch';
	const GROUP      = 'stwm';

	public static function init() {
		add_action( self::HOOK_BATCH, array( __CLASS__, 'handle_batch' ), 10, 1 );
	}

	/**
	 * Queue a batch to run as soon as Action Scheduler gets to it.
	 *
	 * @param array $payload {
	 *     @type string $run_id
	 *     @type string $entity_type
	 *     @type int    $offset
	 *     @type int    $batch_size
	 * }
	 * @return int|false Action id, or false if Action Scheduler is unavailable.
	 */
	public static function enqueue_batch( array $payload ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			STWM_Logger::error( 'Action Scheduler unavailable — cannot enqueue batch.' );
			return false;
		}
		return as_enqueue_async_action( self::HOOK_BATCH, array( $payload ), self::GROUP );
	}

	/**
	 * Queue a batch to run after a delay (used to back off from API rate limits).
	 *
	 * @param int $delay Seconds from now.
	 * @return int|false
	 */
	public static function schedule_batch( array $payload, $delay = 0 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			STWM_Logger::error( 'Action Scheduler unavailable — cannot schedule batch.' );
			return false;
		}
		return as_schedule_single_action( time() + max( 0, (int) $delay ), self::HOOK_BATCH, array( $payload ), self::GROUP );
	}

	/**
	 * Drop every pending batch (used to pause / cancel a run).
	 */
	public static function cancel_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BATCH, array(), self::GROUP );
		}
	}

	/**
	 * @return bool Whether any batch is still queued.
	 */
	public static function is_running() {
		return function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::HOOK_BATCH, null, self::GROUP );
	}

	/**
	 * Action Scheduler callback for one batch. Dispatches on entity type; each
	 * processor re-enqueues its next offset until its source is exhausted.
	 *
	 * @param array $payload Passed through from enqueue_batch()/schedule_batch().
	 */
	public static function handle_batch( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$type    = isset( $payload['entity_type'] ) ? (string) $payload['entity_type'] : '';
		$run_id  = isset( $payload['run_id'] ) ? (string) $payload['run_id'] : '';

		switch ( $type ) {
			case 'index':
				STWM_CSV::build_index( $run_id );
				return;

			case 'product':
				STWM_Product_Importer::run_batch( $payload );
				return;
		}

		STWM_Logger::warning( sprintf( 'Batch with unknown entity_type "%s" (run %s) ignored.', $type, $run_id ) );

		/**
		 * Fires for a batch this class does not itself handle, so future
		 * entity processors (customers, orders, …) can hook in.
		 *
		 * @param array $payload
		 */
		do_action( 'stwm_process_batch_dispatch', $payload );
	}
}
