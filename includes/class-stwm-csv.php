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
 * @package Shopify_To_WooCommerce_Migrator
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

	/**
	 * Single streaming pass over the CSV. Writes products.index.json (one
	 * [handle, byteOffset, rowCount] entry per product) into the run directory
	 * and records product / variant / image counts on the run.
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

		$dir      = stwm_run_upload_dir( $run_id );
		$csv_path = $dir['path'] . '/products.csv';
		if ( ! file_exists( $csv_path ) ) {
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			STWM_Logger::error( sprintf( 'Index: CSV missing for run %s.', $run_id ) );
			return;
		}

		$fh = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			STWM_Logger::error( sprintf( 'Index: cannot open CSV for run %s.', $run_id ) );
			return;
		}

		$header = self::header( $fh );
		$idx    = array_flip( $header );
		if ( ! isset( $idx['Handle'], $idx['Title'] ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			STWM_Run::update( $run_id, array( 'status' => 'failed' ) );
			STWM_Logger::error( sprintf( 'Index: run %s is not a Shopify products CSV (no Handle/Title columns).', $run_id ) );
			return;
		}

		$h_col     = $idx['Handle'];
		$title_col = $idx['Title'];
		$opt1_col  = isset( $idx['Option1 Value'] ) ? $idx['Option1 Value'] : null;
		$price_col = isset( $idx['Variant Price'] ) ? $idx['Variant Price'] : null;
		$img_col   = isset( $idx['Image Src'] ) ? $idx['Image Src'] : null;

		$handles      = array();
		$current      = null;
		$current_pos  = 0;
		$current_rows = 0;
		$n_products   = 0;
		$n_variants   = 0;
		$n_images     = 0;

		while ( true ) {
			$pos = ftell( $fh );
			$row = fgetcsv( $fh );
			if ( false === $row ) {
				break;
			}
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
				++$n_products;
			}
			++$current_rows;

			$has_variant = ( null !== $title_col && '' !== trim( (string) ( $row[ $title_col ] ?? '' ) ) )
				|| ( null !== $opt1_col && '' !== trim( (string) ( $row[ $opt1_col ] ?? '' ) ) )
				|| ( null !== $price_col && '' !== trim( (string) ( $row[ $price_col ] ?? '' ) ) );
			if ( $has_variant ) {
				++$n_variants;
			}
			if ( null !== $img_col && '' !== trim( (string) ( $row[ $img_col ] ?? '' ) ) ) {
				++$n_images;
			}
		}
		if ( null !== $current ) {
			$handles[] = array( $current, $current_pos, $current_rows );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		file_put_contents( $dir['path'] . '/products.index.json', wp_json_encode( array( 'handles' => $handles ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$stats            = isset( $run['stats'] ) ? (array) $run['stats'] : array();
		$stats['product'] = array(
			'total'    => $n_products,
			'done'     => 0,
			'variants' => $n_variants,
			'images'   => $n_images,
		);
		STWM_Run::update( $run_id, array(
			'stats'  => $stats,
			'status' => 'analyzed',
		) );
		STWM_Logger::info( sprintf( 'Run %s analyzed: %d products, %d variant rows, %d image references.', $run_id, $n_products, $n_variants, $n_images ) );
	}
}
