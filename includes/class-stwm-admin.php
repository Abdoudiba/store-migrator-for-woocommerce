<?php
/**
 * The migration wizard: WooCommerce -> Shopify Import.
 *
 * Milestone 1 is the navigable shell — five steps, a stepper, nonce-protected
 * navigation, capability check. Each step's real logic (CSV upload + parse, API
 * connect, entity selection, analyze counts, run dispatch, report) is filled in
 * over the following milestones. The step order and the POST handler contract
 * are meant to stay stable.
 *
 * @package Shopify_To_WooCommerce_Migrator
 */

defined( 'ABSPATH' ) || exit;

class STWM_Admin {

	const PAGE = 'stwm';
	const CAP  = 'manage_woocommerce';

	/**
	 * Ordered wizard steps: slug => label.
	 */
	private static function steps() {
		return array(
			'connect' => __( 'Connect', 'shopify-to-woocommerce-migrator' ),
			'select'  => __( 'Choose data', 'shopify-to-woocommerce-migrator' ),
			'analyze' => __( 'Analyze', 'shopify-to-woocommerce-migrator' ),
			'run'     => __( 'Migrate', 'shopify-to-woocommerce-migrator' ),
			'report'  => __( 'Report', 'shopify-to-woocommerce-migrator' ),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_stwm_wizard', array( __CLASS__, 'handle_post' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Shopify Import', 'shopify-to-woocommerce-migrator' ),
			__( 'Shopify Import', 'shopify-to-woocommerce-migrator' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @return string A valid step slug, defaulting to the first.
	 */
	private static function current_step() {
		$steps = self::steps();
		$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		return array_key_exists( $step, $steps ) ? $step : 'connect';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to run migrations.', 'shopify-to-woocommerce-migrator' ) );
		}

		$step = self::current_step();

		echo '<div class="wrap stwm-wizard">';
		echo '<h1>' . esc_html__( 'Shopify → WooCommerce Migration', 'shopify-to-woocommerce-migrator' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Development build — the wizard is navigable but does not move data yet.', 'shopify-to-woocommerce-migrator' ) . '</p>';

		self::render_steps_nav( $step );

		echo '<div class="stwm-step-body" style="max-width:820px;background:#fff;border:1px solid #dcdcde;padding:1em 1.5em;margin-top:1em;">';
		$method = 'step_' . $step;
		if ( method_exists( __CLASS__, $method ) ) {
			call_user_func( array( __CLASS__, $method ) );
		}
		echo '</div>';
		echo '</div>';
	}

	private static function render_steps_nav( $current ) {
		echo '<ol class="stwm-steps" style="display:flex;gap:1.75em;list-style:none;margin:1.25em 0 0;padding:0;font-size:13px;">';
		$i = 1;
		foreach ( self::steps() as $slug => $label ) {
			$style = ( $slug === $current ) ? 'font-weight:600;color:#1d2327;' : 'color:#787c82;';
			echo '<li style="' . esc_attr( $style ) . '">' . absint( $i ) . '. ' . esc_html( $label ) . '</li>';
			++$i;
		}
		echo '</ol>';
	}

	/**
	 * Open a wizard form that posts to admin-post.php and comes back to the
	 * next step. $step is the step being submitted.
	 */
	private static function form_open( $step ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'stwm_wizard_' . $step );
		echo '<input type="hidden" name="action" value="stwm_wizard" />';
		echo '<input type="hidden" name="stwm_step" value="' . esc_attr( $step ) . '" />';
	}

	private static function form_close( $submit_label ) {
		submit_button( $submit_label );
		echo '</form>';
	}

	/* --- Steps ---------------------------------------------------------- */

	private static function step_connect() {
		self::form_open( 'connect' );
		echo '<h2>' . esc_html__( 'Connect your Shopify store', 'shopify-to-woocommerce-migrator' ) . '</h2>';
		echo '<p>' . esc_html__( 'Free: upload the CSV files Shopify exports (Products, Customers, Orders). Premium: connect the Admin API to also bring over collections, customer addresses, orders, coupons and 301 redirects.', 'shopify-to-woocommerce-migrator' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="stwm_source">' . esc_html__( 'Data source', 'shopify-to-woocommerce-migrator' ) . '</label></th><td>';
		echo '<select name="stwm_source" id="stwm_source">';
		echo '<option value="csv">' . esc_html__( 'Shopify CSV export (free)', 'shopify-to-woocommerce-migrator' ) . '</option>';
		echo '<option value="api" disabled>' . esc_html__( 'Shopify Admin API — premium', 'shopify-to-woocommerce-migrator' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'CSV upload and parsing land in the next milestone.', 'shopify-to-woocommerce-migrator' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		self::form_close( __( 'Continue', 'shopify-to-woocommerce-migrator' ) );
	}

	private static function step_select() {
		self::form_open( 'select' );
		echo '<h2>' . esc_html__( 'Choose what to migrate', 'shopify-to-woocommerce-migrator' ) . '</h2>';

		$entities = array(
			'product'  => array( __( 'Products, variants & images', 'shopify-to-woocommerce-migrator' ), true ),
			'category' => array( __( 'Collections → product categories (premium)', 'shopify-to-woocommerce-migrator' ), false ),
			'customer' => array( __( 'Customers & addresses (premium)', 'shopify-to-woocommerce-migrator' ), false ),
			'order'    => array( __( 'Orders (premium)', 'shopify-to-woocommerce-migrator' ), false ),
			'coupon'   => array( __( 'Discount codes → coupons (premium)', 'shopify-to-woocommerce-migrator' ), false ),
			'redirect' => array( __( 'Generate 301 redirects (premium)', 'shopify-to-woocommerce-migrator' ), false ),
		);

		echo '<fieldset>';
		foreach ( $entities as $slug => $meta ) {
			list( $label, $available ) = $meta;
			printf(
				'<label style="display:block;margin:.4em 0;"><input type="checkbox" name="stwm_entities[]" value="%s" %s %s> %s</label>',
				esc_attr( $slug ),
				checked( $available, true, false ),
				disabled( $available, false, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Shopify never exports password hashes, so migrated customers always set a new password on first login.', 'shopify-to-woocommerce-migrator' ) . '</p>';

		self::form_close( __( 'Continue', 'shopify-to-woocommerce-migrator' ) );
	}

	private static function step_analyze() {
		self::form_open( 'analyze' );
		echo '<h2>' . esc_html__( 'Analyze', 'shopify-to-woocommerce-migrator' ) . '</h2>';
		echo '<p>' . esc_html__( 'This step will read the source and show how many products, variants, images and so on will be created, plus a dry-run of any problems, before anything is written.', 'shopify-to-woocommerce-migrator' ) . '</p>';
		self::form_close( __( 'Looks good — continue', 'shopify-to-woocommerce-migrator' ) );
	}

	private static function step_run() {
		self::form_open( 'run' );
		echo '<h2>' . esc_html__( 'Migrate', 'shopify-to-woocommerce-migrator' ) . '</h2>';
		echo '<p>' . esc_html__( 'Starting a run will queue batches through Action Scheduler. You can close this page; progress continues in the background and is resumable. A rollback option removes everything a run created.', 'shopify-to-woocommerce-migrator' ) . '</p>';
		self::form_close( __( 'Start migration', 'shopify-to-woocommerce-migrator' ) );
	}

	private static function step_report() {
		echo '<h2>' . esc_html__( 'Report', 'shopify-to-woocommerce-migrator' ) . '</h2>';

		$run_id = STWM_Run::current();
		$run    = $run_id ? STWM_Run::get( $run_id ) : null;

		if ( ! $run ) {
			echo '<p>' . esc_html__( 'No migration run yet.', 'shopify-to-woocommerce-migrator' ) . '</p>';
		} else {
			echo '<p>' . sprintf(
				/* translators: 1: run id, 2: status */
				esc_html__( 'Run %1$s — status: %2$s', 'shopify-to-woocommerce-migrator' ),
				'<code>' . esc_html( $run['id'] ) . '</code>',
				esc_html( $run['status'] )
			) . '</p>';
			echo '<p>' . sprintf(
				/* translators: %d: number of mapped objects */
				esc_html__( 'Mapped objects recorded: %d', 'shopify-to-woocommerce-migrator' ),
				absint( STWM_Migration_Map::count( $run['id'] ) )
			) . '</p>';
		}

		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=connect' ) ) . '">' .
			esc_html__( 'Start another migration', 'shopify-to-woocommerce-migrator' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Detailed per-row logs live under WooCommerce → Status → Logs (source: stwm).', 'shopify-to-woocommerce-migrator' ) . '</p>';
	}

	/* --- POST handler ------------------------------------------------------ */

	/**
	 * Validate the submitted step, do that step's work (nothing substantive in
	 * milestone 1 beyond bootstrapping a run), then redirect to the next step.
	 */
	public static function handle_post() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'shopify-to-woocommerce-migrator' ) );
		}

		$step  = isset( $_POST['stwm_step'] ) ? sanitize_key( wp_unslash( $_POST['stwm_step'] ) ) : '';
		$steps = array_keys( self::steps() );

		if ( ! in_array( $step, $steps, true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}

		check_admin_referer( 'stwm_wizard_' . $step );

		switch ( $step ) {
			case 'connect':
				$source = isset( $_POST['stwm_source'] ) ? sanitize_key( wp_unslash( $_POST['stwm_source'] ) ) : 'csv';
				$source = in_array( $source, array( 'csv', 'api' ), true ) ? $source : 'csv';
				STWM_Run::create( array( 'source' => $source ) );
				break;

			case 'select':
				$entities = isset( $_POST['stwm_entities'] ) ? (array) wp_unslash( $_POST['stwm_entities'] ) : array();
				$entities = array_values( array_filter( array_map( 'sanitize_key', $entities ) ) );
				$run_id   = STWM_Run::current();
				if ( $run_id ) {
					STWM_Run::update( $run_id, array( 'entities' => $entities ) );
				}
				break;

			case 'run':
				// Milestone 2 will enqueue the first batch per selected entity here.
				$run_id = STWM_Run::current();
				if ( $run_id ) {
					STWM_Run::update( $run_id, array( 'status' => 'running' ) );
				}
				STWM_Logger::info( 'Wizard "run" submitted (milestone 1: no batches enqueued).' );
				break;
		}

		$idx  = array_search( $step, $steps, true );
		$next = ( false !== $idx && isset( $steps[ $idx + 1 ] ) ) ? $steps[ $idx + 1 ] : 'report';

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=' . $next ) );
		exit;
	}
}
