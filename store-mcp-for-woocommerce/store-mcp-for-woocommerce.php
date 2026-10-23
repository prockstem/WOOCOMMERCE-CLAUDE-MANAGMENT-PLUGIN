<?php
/**
 * Plugin Name:          Store MCP for WooCommerce
 * Description:          Manage your WooCommerce store from Claude over MCP. Adds a dedicated MCP server authenticated with WooCommerce REST API keys, with switchable tool packs.
 * Version:              1.0.0
 * Author:               Store owner
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          store-mcp-for-woocommerce
 * Requires at least:    6.9
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce, mcp-adapter
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

define( 'WCMCP_VERSION', '1.0.0' );
define( 'WCMCP_FILE', __FILE__ );
define( 'WCMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCMCP_URL', plugin_dir_url( __FILE__ ) );
define( 'WCMCP_MIN_PHP', '7.4' );
define( 'WCMCP_MIN_WC', '9.0' );

// Bail out before loading any class files on an unsupported PHP version.
if ( version_compare( PHP_VERSION, WCMCP_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Store MCP for WooCommerce requires PHP %1$s or newer. This site runs PHP %2$s.', 'store-mcp-for-woocommerce' ),
						WCMCP_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCMCP_FILE, true );
		}
	}
);

require_once WCMCP_DIR . 'includes/class-wcmcp-requirements.php';
require_once WCMCP_DIR . 'includes/class-wcmcp-plugin.php';

register_activation_hook( WCMCP_FILE, array( 'WCMCP_Plugin', 'activate' ) );
register_deactivation_hook( WCMCP_FILE, array( 'WCMCP_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WCMCP_Plugin', 'boot' ) );
