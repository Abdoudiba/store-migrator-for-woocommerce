<?php
/**
 * Small stateless helpers shared across the migrator.
 *
 * @package Shopify_To_WooCommerce_Migrator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a column from a header-keyed CSV row, tolerating absent columns
 * (Shopify's export varies by store age and locale).
 *
 * @param array  $row Associative row (header => value).
 * @param string $key Column name.
 * @return string Always a string; '' when the column is missing.
 */
function stwm_col( array $row, $key ) {
	return array_key_exists( $key, $row ) ? (string) $row[ $key ] : '';
}

/**
 * Parse a Shopify CSV money value into a float.
 *
 * Shopify exports dot-decimal with no thousands separator, but hand-edited
 * files turn up with comma decimals or comma thousands, so cover both.
 *
 * @param string $raw Raw cell.
 * @return float|null Null when the cell is empty or not numeric.
 */
function stwm_parse_price( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}
	$raw = preg_replace( '/[^0-9.,\-]/', '', $raw );
	if ( false !== strpos( $raw, ',' ) && false !== strpos( $raw, '.' ) ) {
		$raw = str_replace( ',', '', $raw ); // 1,234.56 -> comma is thousands.
	} elseif ( false !== strpos( $raw, ',' ) ) {
		$raw = str_replace( ',', '.', $raw ); // 1234,56 -> comma is decimal.
	}
	return is_numeric( $raw ) ? (float) $raw : null;
}

/**
 * Convert a weight in grams (Shopify's "Variant Grams") to the store's
 * configured weight unit.
 *
 * @param float|string $grams Grams.
 * @return string Decimal string formatted for WooCommerce.
 */
function stwm_weight_from_grams( $grams ) {
	$grams = (float) str_replace( ',', '.', (string) $grams );
	$unit  = get_option( 'woocommerce_weight_unit', 'kg' );
	switch ( $unit ) {
		case 'g':
			$value = $grams;
			break;
		case 'lbs':
			$value = $grams / 453.59237;
			break;
		case 'oz':
			$value = $grams / 28.349523125;
			break;
		case 'kg':
		default:
			$value = $grams / 1000;
			break;
	}
	return function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $value, 4 ) : (string) round( $value, 4 );
}

/**
 * Per-run private upload directory: wp-content/uploads/stwm/<run_id>/.
 *
 * The parent stwm/ folder gets an Apache deny rule and an empty index file the
 * first time it is created. On nginx the folder is still guessable, so nothing
 * secret is ever written here — only the merchant's own export.
 *
 * @param string $run_id
 * @return array{path:string,url:string,base:string}
 */
function stwm_run_upload_dir( $run_id ) {
	$run_id = preg_replace( '/[^a-f0-9]/', '', (string) $run_id );
	$upload = wp_upload_dir();
	$base   = trailingslashit( $upload['basedir'] ) . 'stwm';
	$path   = $base . '/' . $run_id;

	if ( ! file_exists( $path ) ) {
		wp_mkdir_p( $path );
	}
	if ( ! file_exists( $base . '/.htaccess' ) ) {
		file_put_contents( $base . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	if ( ! file_exists( $base . '/index.html' ) ) {
		file_put_contents( $base . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	return array(
		'path' => $path,
		'url'  => trailingslashit( $upload['baseurl'] ) . 'stwm/' . $run_id,
		'base' => $base,
	);
}

/**
 * Recursively delete a directory (used when a run is rolled back).
 *
 * @param string $dir Absolute path.
 */
function stwm_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$child = $dir . '/' . $item;
		if ( is_dir( $child ) ) {
			stwm_rrmdir( $child );
		} else {
			unlink( $child ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
