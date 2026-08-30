<?php
/**
 * The migration wizard: WooCommerce -> Shopify Import.
 *
 * Four steps: Connect (upload the Shopify products CSV) -> Analyze (background
 * index pass, then options) -> Migrate (queue the batches) -> Report (live
 * progress, per-product table, rollback).
 *
 * @package Shopify_To_WooCommerce_Migrator
 */

defined( 'ABSPATH' ) || exit;

class STWM_Admin {

	const PAGE = 'stwm';
	const CAP  = 'manage_woocommerce';

	/**
	 * Run id the upload_dir filter should target while wp_handle_upload() runs.
	 *
	 * @var string
	 */
	private static $upload_target = '';

	/**
	 * Point wp_handle_upload() at uploads/stwm/<run_id>/ for the duration of one
	 * upload. Added and removed around the wp_handle_upload() call in do_connect().
	 *
	 * @param array $dirs The wp_upload_dir() array.
	 * @return array
	 */
	public static function filter_upload_dir( $dirs ) {
		if ( '' === self::$upload_target ) {
			return $dirs;
		}
		$sub            = '/stwm/' . self::$upload_target;
		$dirs['subdir'] = $sub;
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		return $dirs;
	}

	/**
	 * Let a .csv / .txt upload through even when the server's fileinfo reports a
	 * generic MIME (CSV is very often seen as text/plain), which would otherwise
	 * make wp_handle_upload() reject it. Only relaxes these two extensions, and
	 * only while the do_connect() upload is in flight.
	 *
	 * @param array  $data     ext / type / proper_filename result.
	 * @param string $file     Full path to the file.
	 * @param string $filename The name of the file.
	 * @return array
	 */
	public static function allow_csv_filetype( $data, $file, $filename ) {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'csv' === $ext ) {
			$data['ext']  = 'csv';
			$data['type'] = 'text/csv';
		} elseif ( 'txt' === $ext ) {
			$data['ext']  = 'txt';
			$data['type'] = 'text/plain';
		}
		return $data;
	}

	/**
	 * Ordered wizard steps: slug => label.
	 */
	private static function steps() {
		return array(
			'connect' => __( 'Connect', 'shopify-to-woocommerce-migrator' ),
			'analyze' => __( 'Analyze', 'shopify-to-woocommerce-migrator' ),
			'run'     => __( 'Migrate', 'shopify-to-woocommerce-migrator' ),
			'report'  => __( 'Report', 'shopify-to-woocommerce-migrator' ),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_stwm_wizard', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_stwm_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_stwm_log_csv', array( __CLASS__, 'handle_log_csv' ) );
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

	/* --- Notices (survive the post/redirect/get cycle) ------------------- */

	private static function set_notice( $message, $type = 'success' ) {
		set_transient(
			'stwm_notice_' . get_current_user_id(),
			array(
				'msg'  => $message,
				'type' => $type,
			),
			MINUTE_IN_SECONDS
		);
	}

	private static function print_notice() {
		$notice = get_transient( 'stwm_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'stwm_notice_' . get_current_user_id() );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['msg'] )
		);
	}

	/* --- Render --------------------------------------------------------- */

	private static function current_step() {
		$steps = self::steps();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only step navigation.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		return array_key_exists( $step, $steps ) ? $step : 'connect';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to run migrations.', 'shopify-to-woocommerce-migrator' ) );
		}

		$step = self::current_step();

		echo '<div class="wrap stwm-wizard">';
		echo '<h1>' . esc_html__( 'Shopify → WooCommerce Migration', 'shopify-to-woocommerce-migrator' ) . '</h1>';
		self::print_notice();
		self::render_steps_nav( $step );

		echo '<div class="stwm-step-body" style="max-width:860px;background:#fff;border:1px solid #dcdcde;padding:1em 1.5em;margin-top:1em;">';
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

	private static function form_open( $step, $multipart = false ) {
		printf(
			'<form method="post" action="%s"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$multipart ? ' enctype="multipart/form-data"' : ''
		);
		wp_nonce_field( 'stwm_wizard_' . $step );
		echo '<input type="hidden" name="action" value="stwm_wizard" />';
		echo '<input type="hidden" name="stwm_step" value="' . esc_attr( $step ) . '" />';
	}

	private static function form_close( $submit_label, $type = 'primary' ) {
		submit_button( $submit_label, $type );
		echo '</form>';
	}

	/* --- Steps -------------------------------------------------------- */

	private static function step_connect() {
		self::form_open( 'connect', true );
		echo '<h2>' . esc_html__( 'Upload your Shopify products CSV', 'shopify-to-woocommerce-migrator' ) . '</h2>';
		echo '<p>' . esc_html__( 'In your Shopify admin: Products → Export → "All products", format "Plain CSV file". Upload that file here.', 'shopify-to-woocommerce-migrator' ) . '</p>';
		echo '<p><input type="file" name="stwm_csv" accept=".csv,text/csv,text/plain" required /></p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: server upload size limit, e.g. "8 MB". */
				__( 'Server upload limit: %s. Very large catalogues can be split into several exports. The file is analysed in the background after upload.', 'shopify-to-woocommerce-migrator' ),
				size_format( wp_max_upload_size() )
			)
		) . '</p>';
		self::form_close( __( 'Upload & analyze', 'shopify-to-woocommerce-migrator' ) );
	}

	private static function step_analyze() {
		$run_id = STWM_Run::current();
		$run    = $run_id ? STWM_Run::get( $run_id ) : null;

		if ( ! $run ) {
			echo '<p>' . esc_html__( 'No migration in progress.', 'shopify-to-woocommerce-migrator' ) . ' ';
			printf(
				'<a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=connect' ) ),
				esc_html__( 'Start one', 'shopify-to-woocommerce-migrator' )
			);
			return;
		}

		if ( 'uploaded' === $run['status'] ) {
			echo '<h2>' . esc_html__( 'Analysing…', 'shopify-to-woocommerce-migrator' ) . '</h2>';
			echo '<p>' . esc_html__( 'Reading the CSV in the background. This page refreshes automatically.', 'shopify-to-woocommerce-migrator' ) . '</p>';
			echo '<meta http-equiv="refresh" content="5" />';
			return;
		}

		if ( 'failed' === $run['status'] ) {
			echo '<h2>' . esc_html__( 'Analysis failed', 'shopify-to-woocommerce-migrator' ) . '</h2>';
			echo '<p>' . esc_html__( 'The file could not be read as a Shopify products export. See WooCommerce → Status → Logs (source: stwm) for details.', 'shopify-to-woocommerce-migrator' ) . '</p>';
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=connect' ) ),
				esc_html__( 'Try another file', 'shopify-to-woocommerce-migrator' )
			);
			return;
		}

		$stats = isset( $run['stats']['product'] ) ? $run['stats']['product'] : array(
			'total'    => 0,
			'variants' => 0,
			'images'   => 0,
		);

		echo '<h2>' . esc_html__( 'Ready to migrate', 'shopify-to-woocommerce-migrator' ) . '</h2>';
		echo '<ul style="list-style:disc;margin:0 0 1em 1.5em;">';
		printf( '<li>%s</li>', esc_html( sprintf( /* translators: %s: count */ __( '%s products', 'shopify-to-woocommerce-migrator' ), number_format_i18n( $stats['total'] ) ) ) );
		printf( '<li>%s</li>', esc_html( sprintf( /* translators: %s: count */ __( '%s variant rows', 'shopify-to-woocommerce-migrator' ), number_format_i18n( $stats['variants'] ) ) ) );
		printf( '<li>%s</li>', esc_html( sprintf( /* translators: %s: count */ __( '%s image references', 'shopify-to-woocommerce-migrator' ), number_format_i18n( $stats['images'] ) ) ) );
		echo '</ul>';

		$has_errors = self::render_preflight( $run );

		$opts = isset( $run['options'] ) ? $run['options'] : array();

		if ( $has_errors ) {
			echo '<p>' . esc_html__( 'Fix the blocking issues above in your Shopify export, then upload the corrected file.', 'shopify-to-woocommerce-migrator' ) . '</p>';
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=connect' ) ),
				esc_html__( 'Upload a corrected file', 'shopify-to-woocommerce-migrator' )
			);
			return;
		}

		self::form_open( 'analyze' );
		echo '<h3>' . esc_html__( 'Options', 'shopify-to-woocommerce-migrator' ) . '</h3>';
		printf(
			'<p><label><input type="checkbox" name="stwm_download_images" value="1" %s /> %s</label></p>',
			checked( ! empty( $opts['download_images'] ), true, false ),
			esc_html__( 'Download product images into the media library', 'shopify-to-woocommerce-migrator' )
		);
		printf(
			'<p><label><input type="checkbox" name="stwm_force_draft" value="1" %s /> %s</label></p>',
			checked( ! empty( $opts['force_draft'] ), true, false ),
			esc_html__( 'Import every product as Draft (review before publishing)', 'shopify-to-woocommerce-migrator' )
		);
		printf(
			'<p><label>%s <input type="number" name="stwm_batch_size" min="1" max="100" value="%d" style="width:5em;" /></label> <span class="description">%s</span></p>',
			esc_html__( 'Products per batch', 'shopify-to-woocommerce-migrator' ),
			(int) ( ! empty( $opts['batch_size'] ) ? $opts['batch_size'] : 15 ),
			esc_html__( 'Lower this if image downloads make batches time out.', 'shopify-to-woocommerce-migrator' )
		);
		self::form_close( __( 'Continue', 'shopify-to-woocommerce-migrator' ) );
	}

	/**
	 * Render the dry-run findings from STWM_CSV::build_index().
	 *
	 * @param array $run
	 * @return bool True if there is at least one blocking (error-level) problem.
	 */
	private static function render_preflight( array $run ) {
		$problems = isset( $run['problems'] ) && is_array( $run['problems'] ) ? $run['problems'] : array();
		$counts   = isset( $run['problem_counts'] ) ? $run['problem_counts'] : array(
			'error'   => 0,
			'warning' => 0,
			'info'    => 0,
		);
		$total    = isset( $run['problem_total'] ) ? (int) $run['problem_total'] : count( $problems );

		if ( ! $problems ) {
			echo '<p><span class="dashicons dashicons-yes" style="color:#008a20;"></span> ' .
				esc_html__( 'Pre-flight checks passed — no problems found in the CSV.', 'shopify-to-woocommerce-migrator' ) . '</p>';
			return false;
		}

		$errors   = array_values(
			array_filter(
				$problems,
				static function ( $p ) {
					return 'error' === $p['level'];
				}
			)
		);
		$warnings = array_values(
			array_filter(
				$problems,
				static function ( $p ) {
					return 'warning' === $p['level'];
				}
			)
		);
		$infos    = array_values(
			array_filter(
				$problems,
				static function ( $p ) {
					return 'info' === $p['level'];
				}
			)
		);

		echo '<h3>' . esc_html__( 'Pre-flight checks', 'shopify-to-woocommerce-migrator' ) . '</h3>';

		$render_list = static function ( $items ) {
			echo '<ul style="list-style:disc;margin:.3em 0 1em 1.5em;">';
			foreach ( $items as $it ) {
				echo '<li>' . esc_html( $it['message'] ) . '</li>';
			}
			echo '</ul>';
		};

		if ( $errors ) {
			echo '<div class="notice notice-error inline"><p><strong>' .
				esc_html( sprintf( /* translators: %s: count */ _n( '%s blocking issue', '%s blocking issues', count( $errors ), 'shopify-to-woocommerce-migrator' ), number_format_i18n( count( $errors ) ) ) ) .
				'</strong></p></div>';
			$render_list( $errors );
		}
		if ( $warnings ) {
			echo '<div class="notice notice-warning inline"><p><strong>' .
				esc_html( sprintf( /* translators: %s: count */ _n( '%s warning', '%s warnings', count( $warnings ), 'shopify-to-woocommerce-migrator' ), number_format_i18n( count( $warnings ) ) ) ) .
				'</strong> — ' . esc_html__( 'the import will still run; these rows degrade gracefully.', 'shopify-to-woocommerce-migrator' ) . '</p></div>';
			$render_list( $warnings );
		}
		if ( $infos ) {
			$render_list( $infos );
		}
		if ( $total > count( $problems ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
					/* translators: 1: shown count, 2: total count */
						__( 'Showing %1$s of %2$s findings.', 'shopify-to-woocommerce-migrator' ),
						number_format_i18n( count( $problems ) ),
						number_format_i18n( $total )
					)
				)
			);
		}

		return ! empty( $errors );
	}

	private static function step_run() {
		$run_id = STWM_Run::current();
		$run    = $run_id ? STWM_Run::get( $run_id ) : null;

		if ( ! $run ) {
			echo '<p>' . esc_html__( 'No migration in progress.', 'shopify-to-woocommerce-migrator' ) . '</p>';
			return;
		}

		if ( in_array( $run['status'], array( 'running', 'done' ), true ) ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'This migration is already under way.', 'shopify-to-woocommerce-migrator' ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=report' ) ),
				esc_html__( 'View progress', 'shopify-to-woocommerce-migrator' )
			);
			return;
		}

		$total = isset( $run['stats']['product']['total'] ) ? (int) $run['stats']['product']['total'] : 0;

		self::form_open( 'run' );
		echo '<h2>' . esc_html__( 'Start the migration', 'shopify-to-woocommerce-migrator' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
				/* translators: %s: product count */
					__( 'About to create up to %s products in WooCommerce. Batches run in the background — you can leave this page. The next screen has a rollback that removes everything this run creates.', 'shopify-to-woocommerce-migrator' ),
					number_format_i18n( $total )
				)
			)
		);
		self::form_close( __( 'Start migration', 'shopify-to-woocommerce-migrator' ) );
	}

	private static function step_report() {
		$run_id = STWM_Run::current();
		$run    = $run_id ? STWM_Run::get( $run_id ) : null;

		echo '<h2>' . esc_html__( 'Migration report', 'shopify-to-woocommerce-migrator' ) . '</h2>';

		if ( ! $run ) {
			echo '<p>' . esc_html__( 'No migration run yet.', 'shopify-to-woocommerce-migrator' ) . '</p>';
			return;
		}

		$total      = isset( $run['stats']['product']['total'] ) ? (int) $run['stats']['product']['total'] : 0;
		$done       = STWM_Migration_Map::count( $run_id, 'product', 'ok' );
		$errors     = STWM_Migration_Map::count( $run_id, 'product', 'error' );
		$variations = STWM_Migration_Map::count( $run_id, 'variation' );
		$images     = STWM_Migration_Map::count( $run_id, 'image' );
		$status     = $run['status'];

		printf( '<p><strong>%s</strong> — <code>%s</code></p>', esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ), esc_html( $run_id ) );
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
				/* translators: 1: done, 2: total, 3: failed, 4: variations, 5: images */
					__( 'Products: %1$s / %2$s created, %3$s failed. Variations: %4$s. Images: %5$s.', 'shopify-to-woocommerce-migrator' ),
					number_format_i18n( $done ),
					number_format_i18n( $total ),
					number_format_i18n( $errors ),
					number_format_i18n( $variations ),
					number_format_i18n( $images )
				)
			)
		);

		if ( 'running' === $status ) {
			echo '<p><em>' . esc_html__( 'Running in the background — this page refreshes every 10 seconds.', 'shopify-to-woocommerce-migrator' ) . '</em></p>';
			echo '<meta http-equiv="refresh" content="10" />';
		}

		$rows = STWM_Migration_Map::rows_for_run( $run_id, 'product' );
		if ( $rows ) {
			echo '<table class="widefat striped" style="margin-top:1em;"><thead><tr>';
			echo '<th>' . esc_html__( 'Handle', 'shopify-to-woocommerce-migrator' ) . '</th>';
			echo '<th>' . esc_html__( 'Title', 'shopify-to-woocommerce-migrator' ) . '</th>';
			echo '<th>' . esc_html__( 'Product', 'shopify-to-woocommerce-migrator' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'shopify-to-woocommerce-migrator' ) . '</th>';
			echo '<th>' . esc_html__( 'Note', 'shopify-to-woocommerce-migrator' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 100 ) as $row ) {
				$edit_link = $row->target_id ? get_edit_post_link( (int) $row->target_id ) : '';
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $row->source_id ),
					esc_html( $row->source_ref ),
					$edit_link ? sprintf( '<a href="%s">#%d</a>', esc_url( $edit_link ), (int) $row->target_id ) : '—',
					esc_html( $row->status ),
					esc_html( $row->message )
				);
			}
			echo '</tbody></table>';
			if ( count( $rows ) > 100 ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
						/* translators: %s: total row count */
							__( 'Showing the latest 100 of %s products.', 'shopify-to-woocommerce-migrator' ),
							number_format_i18n( count( $rows ) )
						)
					)
				);
			}
		}

		self::render_log_section( $run_id );

		printf(
			'<p style="margin-top:1em;"><a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&step=connect' ) ),
			esc_html__( 'Start another migration', 'shopify-to-woocommerce-migrator' )
		);

		if ( in_array( $status, array( 'running', 'done', 'failed', 'paused' ), true ) && ( $done > 0 || $variations > 0 || $images > 0 ) ) {
			echo '<hr /><h3>' . esc_html__( 'Roll back this migration', 'shopify-to-woocommerce-migrator' ) . '</h3>';
			echo '<p>' . esc_html__( 'Permanently deletes every product, variation and image this run created, and cancels any pending batches.', 'shopify-to-woocommerce-migrator' ) . '</p>';
			printf(
				'<form method="post" action="%s" onsubmit="return confirm(%s);">',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( wp_json_encode( __( 'Delete everything this migration created? This cannot be undone.', 'shopify-to-woocommerce-migrator' ) ) )
			);
			wp_nonce_field( 'stwm_rollback' );
			echo '<input type="hidden" name="action" value="stwm_rollback" />';
			echo '<input type="hidden" name="stwm_run_id" value="' . esc_attr( $run_id ) . '" />';
			printf(
				'<p><label><input type="checkbox" name="stwm_confirm" value="1" required /> %s</label></p>',
				esc_html__( 'Yes, I understand this deletes the imported data.', 'shopify-to-woocommerce-migrator' )
			);
			printf(
				'<p><label><input type="checkbox" name="stwm_delete_images" value="1" checked /> %s</label></p>',
				esc_html__( 'Also delete imported images from the media library', 'shopify-to-woocommerce-migrator' )
			);
			submit_button( __( 'Roll back', 'shopify-to-woocommerce-migrator' ), 'delete' );
			echo '</form>';
		}
	}

	/**
	 * The run's log: counts, a CSV download, and the latest rows.
	 */
	private static function render_log_section( $run_id ) {
		$total = STWM_Log::count( $run_id );
		if ( ! $total ) {
			return;
		}
		$n_error = STWM_Log::count( $run_id, 'error' );
		$n_warn  = STWM_Log::count( $run_id, 'warning' );

		echo '<hr /><h3>' . esc_html__( 'Log', 'shopify-to-woocommerce-migrator' ) . '</h3>';
		printf(
			'<p>%s ',
			esc_html(
				sprintf(
				/* translators: 1: errors, 2: warnings, 3: total */
					__( '%1$s errors, %2$s warnings, %3$s entries total.', 'shopify-to-woocommerce-migrator' ),
					number_format_i18n( $n_error ),
					number_format_i18n( $n_warn ),
					number_format_i18n( $total )
				)
			)
		);
		printf(
			'<a class="button" href="%s">%s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stwm_log_csv&run=' . rawurlencode( $run_id ) ), 'stwm_log_csv' ) ),
			esc_html__( 'Download full log (CSV)', 'shopify-to-woocommerce-migrator' )
		);

		$log_rows = STWM_Log::rows( $run_id, 100 );
		if ( ! $log_rows ) {
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Time (UTC)', 'shopify-to-woocommerce-migrator' ) . '</th>';
		echo '<th>' . esc_html__( 'Level', 'shopify-to-woocommerce-migrator' ) . '</th>';
		echo '<th>' . esc_html__( 'Item', 'shopify-to-woocommerce-migrator' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'shopify-to-woocommerce-migrator' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $log_rows as $r ) {
			$item = trim( $r->entity_type . ' ' . $r->source_id );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r->created_at ),
				esc_html( $r->level ),
				esc_html( '' !== $item ? $item : '—' ),
				esc_html( $r->message )
			);
		}
		echo '</tbody></table>';
		if ( $total > count( $log_rows ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
					/* translators: 1: shown, 2: total */
						__( 'Showing the latest %1$s of %2$s entries — the CSV has everything.', 'shopify-to-woocommerce-migrator' ),
						number_format_i18n( count( $log_rows ) ),
						number_format_i18n( $total )
					)
				)
			);
		}
	}

	/**
	 * Stream the full run log as a CSV download.
	 */
	public static function handle_log_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'shopify-to-woocommerce-migrator' ) );
		}
		check_admin_referer( 'stwm_log_csv' );

		$run_id = isset( $_GET['run'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_GET['run'] ) ) ) : '';
		if ( '' === $run_id ) {
			wp_die( esc_html__( 'No run specified.', 'shopify-to-woocommerce-migrator' ) );
		}

		$rows = STWM_Log::all_for_csv( $run_id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=stwm-log-' . $run_id . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'time_utc', 'level', 'entity_type', 'source_id', 'message' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r->created_at, $r->level, $r->entity_type, $r->source_id, $r->message ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* --- POST handlers --------------------------------------------------- */

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

		$next = 'report';
		$idx  = array_search( $step, $steps, true );
		if ( false !== $idx && isset( $steps[ $idx + 1 ] ) ) {
			$next = $steps[ $idx + 1 ];
		}

		switch ( $step ) {
			case 'connect':
				$next = self::do_connect() ? 'analyze' : 'connect';
				break;

			case 'analyze':
				$run_id = STWM_Run::current();
				if ( $run_id ) {
					STWM_Run::update(
						$run_id,
						array(
							'options' => array(
								'download_images' => ! empty( $_POST['stwm_download_images'] ),
								'force_draft'     => ! empty( $_POST['stwm_force_draft'] ),
								'batch_size'      => max( 1, min( 100, isset( $_POST['stwm_batch_size'] ) ? (int) $_POST['stwm_batch_size'] : 15 ) ),
							),
							'status'  => 'ready',
						)
					);
				}
				break;

			case 'run':
				$run_id = STWM_Run::current();
				$run    = $run_id ? STWM_Run::get( $run_id ) : null;
				if ( $run && in_array( $run['status'], array( 'ready', 'analyzed', 'paused' ), true ) ) {
					$batch = isset( $run['options']['batch_size'] ) ? max( 1, (int) $run['options']['batch_size'] ) : 15;
					STWM_Run::update( $run_id, array( 'status' => 'running' ) );
					STWM_Queue::enqueue_batch(
						array(
							'run_id'      => $run_id,
							'entity_type' => 'product',
							'offset'      => 0,
							'batch_size'  => $batch,
						)
					);
					self::maybe_spawn_cron();
				}
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=' . $next ) );
		exit;
	}

	/**
	 * Validate and store the uploaded CSV, create the run, queue the index pass.
	 *
	 * @return bool True on success (advance to Analyze).
	 */
	private static function do_connect() {
		// Re-assert the nonce here so this method is self-contained (it is only
		// ever reached from handle_post()'s "connect" case, which already
		// verified it).
		check_admin_referer( 'stwm_wizard_connect' );

		if ( empty( $_FILES['stwm_csv'] ) || ! isset( $_FILES['stwm_csv']['tmp_name'] ) ) {
			self::set_notice( __( 'No file received. Please choose a CSV file.', 'shopify-to-woocommerce-migrator' ), 'error' );
			return false;
		}

		$error = isset( $_FILES['stwm_csv']['error'] ) ? (int) $_FILES['stwm_csv']['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			$msg = ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error )
				? __( 'The file is larger than this server allows. Export a smaller range from Shopify, or raise upload_max_filesize.', 'shopify-to-woocommerce-migrator' )
				: __( 'The upload did not complete. Please try again.', 'shopify-to-woocommerce-migrator' );
			self::set_notice( $msg, 'error' );
			return false;
		}

		$orig_name = isset( $_FILES['stwm_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['stwm_csv']['name'] ) ) : 'products.csv';
		$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			self::set_notice( __( 'That does not look like a .csv file.', 'shopify-to-woocommerce-migrator' ), 'error' );
			return false;
		}

		$run_id = STWM_Run::create(
			array(
				'source'   => 'csv',
				'status'   => 'uploaded',
				'entities' => array( 'product' ),
				'file'     => $orig_name,
				'options'  => array(
					'download_images' => true,
					'force_draft'     => false,
					'batch_size'      => 15,
				),
			)
		);

		// Move the upload into uploads/stwm/<run_id>/products.csv via WordPress's
		// own handler (no direct move_uploaded_file()). A short-lived upload_dir
		// filter targets the per-run directory; the filename is forced.
		$dir                 = stwm_run_upload_dir( $run_id );
		self::$upload_target = $run_id;
		add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'allow_csv_filetype' ), 10, 3 );

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$uploaded = wp_handle_upload(
			$_FILES['stwm_csv'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_handle_upload() performs the validation and move.
			array(
				'test_form'                => false,
				'unique_filename_callback' => static function () {
					return 'products.csv';
				},
				'mimes'                    => array(
					'csv' => 'text/csv',
					'txt' => 'text/plain',
				),
			)
		);

		remove_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'allow_csv_filetype' ), 10 );
		remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
		self::$upload_target = '';

		if ( ! is_array( $uploaded ) || isset( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			$reason = is_array( $uploaded ) && isset( $uploaded['error'] ) ? $uploaded['error'] : __( 'unknown error', 'shopify-to-woocommerce-migrator' );
			self::set_notice(
				sprintf(
					/* translators: %s: reason the upload failed */
					__( 'Could not save the uploaded file: %s', 'shopify-to-woocommerce-migrator' ),
					$reason
				),
				'error'
			);
			return false;
		}

		// Sniff the header row now the file is in place.
		$csv_path = $dir['path'] . '/products.csv';
		$fh       = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$line     = $fh ? (string) fgets( $fh ) : '';
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( false === stripos( $line, 'Handle' ) || false === stripos( $line, 'Title' ) ) {
			wp_delete_file( $csv_path );
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			self::set_notice( __( 'This CSV has no "Handle" / "Title" columns, so it is not a Shopify product export.', 'shopify-to-woocommerce-migrator' ), 'error' );
			return false;
		}

		STWM_Queue::enqueue_batch(
			array(
				'run_id'      => $run_id,
				'entity_type' => 'index',
			)
		);
		self::maybe_spawn_cron();
		return true;
	}

	public static function handle_rollback() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'shopify-to-woocommerce-migrator' ) );
		}
		check_admin_referer( 'stwm_rollback' );

		$run_id = isset( $_POST['stwm_run_id'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['stwm_run_id'] ) ) ) : '';
		if ( '' === $run_id || empty( $_POST['stwm_confirm'] ) ) {
			self::set_notice( __( 'Rollback was not confirmed.', 'shopify-to-woocommerce-migrator' ), 'error' );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=report' ) );
			exit;
		}

		STWM_Queue::cancel_all();

		$delete_images = ! empty( $_POST['stwm_delete_images'] );
		$rows          = STWM_Migration_Map::rows_for_run( $run_id );
		$deleted_p     = 0;
		$deleted_i     = 0;

		foreach ( $rows as $row ) {
			if ( in_array( $row->entity_type, array( 'product', 'variation' ), true ) && $row->target_id ) {
				wp_delete_post( (int) $row->target_id, true );
				if ( 'product' === $row->entity_type ) {
					++$deleted_p;
				}
			}
		}
		if ( $delete_images ) {
			foreach ( $rows as $row ) {
				if ( 'image' === $row->entity_type && $row->target_id ) {
					wp_delete_attachment( (int) $row->target_id, true );
					++$deleted_i;
				}
			}
		}

		STWM_Log::info( $run_id, sprintf( 'Rolled back: %d products and %d images deleted.', $deleted_p, $deleted_i ) );
		STWM_Migration_Map::delete_run( $run_id );
		STWM_Run::update( $run_id, array( 'status' => 'rolled_back' ) );

		$dir = stwm_run_upload_dir( $run_id );
		stwm_rrmdir( $dir['path'] );

		self::set_notice(
			sprintf(
				/* translators: 1: products deleted, 2: images deleted */
				__( 'Rolled back: %1$s products and %2$s images deleted.', 'shopify-to-woocommerce-migrator' ),
				number_format_i18n( $deleted_p ),
				number_format_i18n( $deleted_i )
			),
			'success'
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&step=report' ) );
		exit;
	}

	/**
	 * Nudge WP-Cron so Action Scheduler starts promptly instead of waiting for
	 * the next front-end visit.
	 */
	private static function maybe_spawn_cron() {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}
}
