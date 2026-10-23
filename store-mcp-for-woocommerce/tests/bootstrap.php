<?php
/**
 * PHPUnit bootstrap: loads WordPress's test library with WooCommerce and this plugin.
 *
 * Run inside wp-env's tests container:
 *   npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/store-mcp-for-woocommerce vendor/bin/phpunit
 *
 * @package WCMCP
 */

$wcmcp_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';

if ( ! file_exists( $wcmcp_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found in {$wcmcp_tests_dir}. Run the tests inside wp-env's tests-cli container.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $wcmcp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// wp-env installs WooCommerce from its download zip into a differently named folder.
		foreach ( array( 'woocommerce/woocommerce.php', 'woocommerce.latest-stable/woocommerce.php' ) as $woocommerce ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $woocommerce ) ) {
				require_once WP_PLUGIN_DIR . '/' . $woocommerce;
				break;
			}
		}
		require_once dirname( __DIR__ ) . '/store-mcp-for-woocommerce.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		// Create WooCommerce's tables and roles (shop_manager, customer) in the test database.
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
		WCMCP_Log::install();
	}
);

require $wcmcp_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/class-wcmcp-test-case.php';
