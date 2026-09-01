<?php
/**
 * Plugin Name:       Store Migrator for WooCommerce
 * Plugin URI:        https://github.com/Abdoudiba/store-migrator-for-woocommerce
 * Description:        Migrate a Shopify store into WooCommerce — products, variants and images from a CSV export (free), plus collections, customers, orders, coupons and 301 redirects (premium). Batched with Action Scheduler and resumable.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            Abid
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       store-migrator-for-woocommerce
 *
 * @package Store_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'STWM_VERSION', '1.0.0' );
define( 'STWM_PLUGIN_FILE', __FILE__ );
define( 'STWM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STWM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'STWM_DB_VERSION', '2' );

// Primitives (no WooCommerce classes touched at load time): safe to require
// unconditionally so the activation hook can reach STWM_Install.
require_once STWM_PLUGIN_DIR . 'includes/class-stwm-logger.php';
require_once STWM_PLUGIN_DIR . 'includes/class-stwm-log.php';
require_once STWM_PLUGIN_DIR . 'includes/class-stwm-install.php';
require_once STWM_PLUGIN_DIR . 'includes/class-stwm-migration-map.php';
require_once STWM_PLUGIN_DIR . 'includes/class-stwm-run.php';
require_once STWM_PLUGIN_DIR . 'includes/class-stwm-queue.php';

register_activation_hook( __FILE__, array( 'STWM_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'STWM_Install', 'deactivate' ) );

/**
 * Show an admin notice instead of fatal-erroring when WooCommerce is absent.
 */
function stwm_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'Store Migrator for WooCommerce requires WooCommerce to be installed and active.', 'store-migrator-for-woocommerce' ) .
		'</p></div>';
}

/**
 * Boot the plugin once all plugins are loaded.
 */
function stwm_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'stwm_woocommerce_missing_notice' );
		return;
	}

	require_once STWM_PLUGIN_DIR . 'includes/stwm-helpers.php';
	require_once STWM_PLUGIN_DIR . 'includes/class-stwm-csv.php';
	require_once STWM_PLUGIN_DIR . 'includes/class-stwm-product-importer.php';

	STWM_Install::init();
	STWM_Queue::init();

	if ( is_admin() ) {
		require_once STWM_PLUGIN_DIR . 'includes/class-stwm-admin.php';
		STWM_Admin::init();
	}
}
add_action( 'plugins_loaded', 'stwm_init' );

/**
 * Declare HPOS compatibility. This plugin creates orders through the
 * WooCommerce CRUD API (WC_Order), never direct post writes, so it is
 * compatible with custom order tables.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
