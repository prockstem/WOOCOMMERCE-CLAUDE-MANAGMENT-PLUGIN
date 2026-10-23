<?php
/**
 * Plugin bootstrap.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's components once WooCommerce is available.
 */
final class WCMCP_Plugin {

	/**
	 * Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'admin_notices', array( __CLASS__, 'requirement_notices' ) );

		// Nothing else works without WooCommerce: its keys table, CRUD classes and settings screen.
		if ( ! WCMCP_Requirements::has_woocommerce() ) {
			return;
		}

		require_once WCMCP_DIR . 'includes/class-wcmcp-api-keys.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-packs.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-auth.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-permissions.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-abilities.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-server.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-format.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-write.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-confirm.php';
		require_once WCMCP_DIR . 'includes/class-wcmcp-log.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-core.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-orders.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-products.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-products-write.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-customers.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-coupons.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-refunds.php';
		require_once WCMCP_DIR . 'includes/abilities/class-wcmcp-abilities-reports.php';

		WCMCP_Auth::init();
		WCMCP_Log::init();
		WCMCP_Abilities::init();
		WCMCP_Server::init();

		if ( is_admin() ) {
			require_once WCMCP_DIR . 'includes/class-wcmcp-connection-test.php';
			require_once WCMCP_DIR . 'includes/admin/class-wcmcp-admin.php';
			WCMCP_Admin::init();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'store-mcp-for-woocommerce', false, dirname( plugin_basename( WCMCP_FILE ) ) . '/languages' );
	}

	/**
	 * Shows missing-dependency notices to store managers.
	 *
	 * @return void
	 */
	public static function requirement_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$problems = WCMCP_Requirements::problems();
		if ( empty( $problems ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Store MCP for WooCommerce is not running:', 'store-mcp-for-woocommerce' ) . '</strong></p><ul>';
		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Activation: seed defaults without overwriting existing settings.
	 *
	 * @return void
	 */
	public static function activate() {
		add_option( 'wcmcp_version', WCMCP_VERSION );
		add_option( 'wcmcp_key_ids', array(), '', false );

		require_once WCMCP_DIR . 'includes/class-wcmcp-log.php';
		WCMCP_Log::install();
	}

	/**
	 * Deactivation: stop the log cleanup schedule. Data and keys are kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wcmcp_log_cleanup' );
	}
}
