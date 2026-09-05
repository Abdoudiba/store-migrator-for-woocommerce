<?php
/**
 * Reading and indexing a Shopify "Products" CSV export.
 *
 * A Shopify product CSV groups rows by Handle: the first row of a group carries
 * the product-level fields plus its first variant, following rows are extra
 * variants or extra image references. Rows for one Handle are always
 * contiguous in a genuine export, which lets us record a byte offset per group
 * during the index pass and fseek() straight to it when a batch runs.
 *
 * @package Store_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class STWM_CSV {

	/**
	 * Read the header row, stripping a UTF-8 BOM from the first cell. Leaves the
	 * file pointer positioned just after the header.
	 *
	 * @param resource $fh Open file handle.
	 * @return array List of column names ([] if the file is unreadable).
	 */
	public static function header( $fh ) {
		rewind( $fh );
		$header = fgetcsv( $fh );
		if ( is_array( $header ) && isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		}
		return is_array( $header ) ? $header : array();
	}

	/**
	 * Turn a raw fgetcsv row into a header-keyed associative row, padding or
	 * trimming so the column counts line up.
	 *
	 * @param array $header Column names.
	 * @param mixed $row    Raw row from fgetcsv.
	 * @return array|null
	 */
	public static function assoc( array $header, $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$count = count( $header );
		$row   = array_slice( $row, 0, $count );
		$row   = array_pad( $row, $count, '' );
		return array_combine( $header, $row );
	}

	/** Stop collecting problem entries once this many are known (a summary count is kept). */
	const MAX_PROBLEMS = 200;

	/** Upper bound on the unique image URLs held in memory during the pass. */
	const MAX_IMAGE_URLS = 4000;

	/** How many distinct image URLs to actually HTTP-check in the dry run. */
	const IMAGE_CHECK_LIMIT = 30;

	/**
	 * Single streaming pass over the CSV. Writes products.index.json (one
	 * [handle, byteOffset, rowCount] entry per product) into the run directory,
	 * records product / variant / image counts, and runs a dry-run validation
	 * that surfaces problems (duplicate SKUs, unparseable prices, malformed
	 * groups, unreachable images) on the run before anything is written to
	 * WooCommerce.
	 *
	 * Invoked as the "index" batch so a large file is scanned in the background
	 * rather than during the upload request.
	 *
	 * @param string $run_id
	 */
	public static function build_index( $run_id ) {
		$run = STWM_Run::get( $run_id );
		if ( ! $run ) {
			STWM_Logger::error( sprintf( 'Index: run %s not found.', $run_id ) );
			return;
		}

		// A re-analysis (e.g. after a reset) starts from a clean log.
		STWM_Log::delete_run( $run_id );

		$dir      = stwm_run_upload_dir( $run_id );
		$csv_path = $dir['path'] . '/products.csv';
		if ( ! file_exists( $csv_path ) ) {
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			STWM_Log::error( $run_id, 'Analysis: the uploaded CSV could not be found.' );
			return;
		}

		$fh = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			STWM_Log::error( $run_id, 'Analysis: the uploaded CSV could not be opened.' );
			return;
		}

		$header = self::header( $fh );
		$idx    = array_flip( $header );
		if ( ! isset( $idx['Handle'], $idx['Title'] ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			STWM_Log::error( $run_id, 'This file is not a Shopify products export: it has no "Handle" / "Title" columns.' );
			return;
		}

		$h_col      = $idx['Handle'];
		$title_col  = $idx['Title'];
		$opt1_col   = isset( $idx['Option1 Value'] ) ? $idx['Option1 Value'] : null;
		$price_col  = isset( $idx['Variant Price'] ) ? $idx['Variant Price'] : null;
		$sku_col    = isset( $idx['Variant SKU'] ) ? $idx['Variant SKU'] : null;
		$img_col    = isset( $idx['Image Src'] ) ? $idx['Image Src'] : null;
		$vimg_col   = isset( $idx['Variant Image'] ) ? $idx['Variant Image'] : null;
		$status_col = isset( $idx['Status'] ) ? $idx['Status'] : null;
		$pub_col    = isset( $idx['Published'] ) ? $idx['Published'] : null;

		$problems    = array();
		$prob_count  = 0;
		$add_problem = static function ( $level, $code, $message ) use ( &$problems, &$prob_count ) {
			++$prob_count;
			if ( count( $problems ) < self::MAX_PROBLEMS ) {
				$problems[] = array(
					'level'   => $level,
					'code'    => $code,
					'message' => $message,
				);
			}
		};

		if ( null === $price_col ) {
			$add_problem( 'error', 'no_price_column', 'The CSV has no "Variant Price" column — products would be imported with no price.' );
		}
		if ( null === $img_col ) {
			$add_problem( 'info', 'no_image_column', 'The CSV has no "Image Src" column — no product images will be imported.' );
		}
		if ( null === $status_col && null === $pub_col ) {
			$add_problem( 'info', 'no_status_column', 'The CSV has neither a "Status" nor a "Published" column — every product will be published.' );
		}

		$handles      = array();
		$current      = null;
		$current_pos  = 0;
		$current_rows = 0;
		$n_products   = 0;
		$n_variants   = 0;
		$n_images     = 0;
		$line         = 1; // header is line 1
		$first_of_grp = false;
		$skus_seen    = array();
		$image_urls   = array();
		$image_capped = false;

		$collect_url = static function ( $url ) use ( &$image_urls, &$image_capped ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return;
			}
			if ( isset( $image_urls[ $url ] ) ) {
				return;
			}
			if ( count( $image_urls ) >= self::MAX_IMAGE_URLS ) {
				$image_capped = true;
				return;
			}
			$image_urls[ $url ] = true;
		};

		while ( true ) {
			$pos = ftell( $fh );
			$row = fgetcsv( $fh );
			if ( false === $row ) {
				break;
			}
			++$line;
			if ( 1 === count( $row ) && '' === trim( (string) $row[0] ) ) {
				continue; // blank line
			}
			$handle = isset( $row[ $h_col ] ) ? trim( (string) $row[ $h_col ] ) : '';
			if ( '' === $handle ) {
				continue;
			}

			if ( $handle !== $current ) {
				if ( null !== $current ) {
					$handles[] = array( $current, $current_pos, $current_rows );
				}
				$current      = $handle;
				$current_pos  = $pos;
				$current_rows = 0;
				$first_of_grp = true;
				++$n_products;
			}
			++$current_rows;

			$title = ( null !== $title_col ) ? trim( (string) ( $row[ $title_col ] ?? '' ) ) : '';
			$opt1  = ( null !== $opt1_col ) ? trim( (string) ( $row[ $opt1_col ] ?? '' ) ) : '';
			$price = ( null !== $price_col ) ? trim( (string) ( $row[ $price_col ] ?? '' ) ) : '';
			$sku   = ( null !== $sku_col ) ? trim( (string) ( $row[ $sku_col ] ?? '' ) ) : '';

			if ( $first_of_grp && '' === $title ) {
				$add_problem( 'error', 'group_no_title', sprintf( 'Line %d: product "%s" has no Title on its first row.', $line, $handle ) );
			}
			$first_of_grp = false;

			$has_variant = ( '' !== $title ) || ( '' !== $opt1 ) || ( '' !== $price );
			if ( $has_variant ) {
				++$n_variants;
				if ( '' !== $price && null === stwm_parse_price( $price ) ) {
					$add_problem( 'warning', 'bad_price', sprintf( 'Line %d (%s): price "%s" is not a number — this variant will import with no price.', $line, $handle, $price ) );
				}
				if ( '' !== $sku ) {
					if ( isset( $skus_seen[ $sku ] ) ) {
						$add_problem( 'warning', 'dup_sku', sprintf( 'Duplicate SKU "%s" (lines %d and %d) — WooCommerce keeps SKUs unique, so the later one imports blank.', $sku, $skus_seen[ $sku ], $line ) );
					} else {
						$skus_seen[ $sku ] = $line;
					}
				}
			}

			if ( null !== $img_col && '' !== trim( (string) ( $row[ $img_col ] ?? '' ) ) ) {
				++$n_images;
				$collect_url( $row[ $img_col ] );
			}
			if ( null !== $vimg_col && '' !== trim( (string) ( $row[ $vimg_col ] ?? '' ) ) ) {
				$collect_url( $row[ $vimg_col ] );
			}
		}
		if ( null !== $current ) {
			$handles[] = array( $current, $current_pos, $current_rows );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		file_put_contents( $dir['path'] . '/products.index.json', wp_json_encode( array( 'handles' => $handles ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Dry-run reachability check on a bounded sample of the image URLs.
		$all_urls    = array_keys( $image_urls );
		$check_urls  = array_slice( $all_urls, 0, self::IMAGE_CHECK_LIMIT );
		$checked     = 0;
		$unreachable = 0;
		foreach ( $check_urls as $url ) {
			++$checked;
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				++$unreachable;
				$add_problem( 'warning', 'image_bad_url', sprintf( 'Image URL is not http(s): %s', $url ) );
				continue;
			}
			$resp = wp_safe_remote_head(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 3,
				)
			);
			$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
			if ( $code < 200 || $code >= 400 ) {
				++$unreachable;
				$add_problem( 'warning', 'image_unreachable', sprintf( 'Image not reachable (HTTP %d): %s', $code, $url ) );
			}
		}
		if ( count( $all_urls ) > $checked || $image_capped ) {
			$add_problem( 'info', 'image_check_sampled', sprintf( 'Checked %d of %d unique image URLs (%d unreachable in the sample).', $checked, count( $all_urls ) + ( $image_capped ? 1 : 0 ), $unreachable ) );
		}

		$counts = array(
			'error'   => 0,
			'warning' => 0,
			'info'    => 0,
		);
		foreach ( $problems as $p ) {
			if ( isset( $counts[ $p['level'] ] ) ) {
				++$counts[ $p['level'] ];
			}
		}

		$stats            = isset( $run['stats'] ) ? (array) $run['stats'] : array();
		$stats['product'] = array(
			'total'    => $n_products,
			'done'     => 0,
			'variants' => $n_variants,
			'images'   => $n_images,
		);

		STWM_Run::update(
			$run_id,
			array(
				'stats'          => $stats,
				'status'         => 'analyzed',
				'problems'       => $problems,
				'problem_counts' => $counts,
				'problem_total'  => $prob_count,
			)
		);

		STWM_Log::info( $run_id, sprintf( 'Analysis complete: %d products, %d variant rows, %d image references, %d issue(s) found.', $n_products, $n_variants, $n_images, $prob_count ) );
	}
}
