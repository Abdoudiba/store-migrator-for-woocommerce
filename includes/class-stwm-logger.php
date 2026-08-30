<?php
/**
 * Thin wrapper around WooCommerce's logger so every migration message lands in
 * one place (WooCommerce → Status → Logs, source "stwm"). A dedicated,
 * downloadable per-row log table is planned for a later milestone.
 *
 * @package Shopify_To_WooCommerce_Migrator
 */

defined( 'ABSPATH' ) || exit;

class STWM_Logger {

	const SOURCE = 'stwm';

	/**
	 * @return WC_Logger_Interface|null Null until WooCommerce is loaded.
	 */
	private static function logger() {
		return function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
	}

	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * @param string $level   One of the PSR-3 levels WooCommerce accepts.
	 * @param string $message Human-readable line.
	 * @param array  $context Extra data; a "source" key is forced to STWM.
	 */
	public static function log( $level, $message, array $context = array() ) {
		$logger = self::logger();
		if ( ! $logger ) {
			return;
		}
		$context['source'] = self::SOURCE;
		$logger->log( $level, $message, $context );
	}
}
