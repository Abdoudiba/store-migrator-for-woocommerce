<?php
/**
 * Activation / schema management.
 *
 * The ID-map table (see STWM_Migration_Map) is the backbone of the whole
 * migrator: it makes runs idempotent, lets orders link to already-imported
 * products and customers, and is what a later "incremental re-run" mode reads
 * to know what has already been brought over.
 *
 * @package Store_Migrator_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

class STWM_Install {

	/**
	 * Hook the lightweight upgrade check. Runs on every admin request but only
	 * does work when the stored schema version is behind.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Fired by register_activation_hook.
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'stwm_db_version', STWM_DB_VERSION );
	}

	/**
	 * Fired by register_deactivation_hook. Clears any pending batch actions so a
	 * disabled plugin can't keep a half-finished migration running. Uses string
	 * literals rather than STWM_Queue constants because the queue class is not
	 * guaranteed to be loaded during deactivation.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'stwm_process_batch', array(), 'stwm' );
		}
	}

	/**
	 * Bring the schema up to date after a plugin update without needing a
	 * deactivate/reactivate cycle.
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( 'stwm_db_version' ) !== (string) STWM_DB_VERSION ) {
			self::create_tables();
			update_option( 'stwm_db_version', STWM_DB_VERSION );
		}
	}

	/**
	 * Create/upgrade custom tables via dbDelta.
	 *
	 * Column types are lowercase and there are two spaces after "PRIMARY KEY"
	 * because dbDelta is picky about both.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$map             = $wpdb->prefix . 'stwm_map';
		$log             = $wpdb->prefix . 'stwm_log';

		$sql_map = "CREATE TABLE $map (
			id bigint(20) unsigned NOT NULL auto_increment,
			run_id varchar(32) NOT NULL default '',
			entity_type varchar(20) NOT NULL default '',
			source_id varchar(64) NOT NULL default '',
			source_ref varchar(191) NOT NULL default '',
			target_id bigint(20) unsigned NOT NULL default 0,
			status varchar(20) NOT NULL default 'ok',
			message varchar(255) NOT NULL default '',
			created_at datetime NOT NULL default '0000-00-00 00:00:00',
			updated_at datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY entity_source (entity_type,source_id),
			KEY run_id (run_id),
			KEY entity_target (entity_type,target_id)
		) $charset_collate;";

		$sql_log = "CREATE TABLE $log (
			id bigint(20) unsigned NOT NULL auto_increment,
			run_id varchar(32) NOT NULL default '',
			level varchar(10) NOT NULL default 'info',
			entity_type varchar(20) NOT NULL default '',
			source_id varchar(191) NOT NULL default '',
			message text NOT NULL,
			created_at datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY run_level (run_id,level),
			KEY run_id (run_id)
		) $charset_collate;";

		dbDelta( $sql_map );
		dbDelta( $sql_log );
	}
}
