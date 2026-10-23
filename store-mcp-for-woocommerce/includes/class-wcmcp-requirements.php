<?php
/**
 * Environment checks shared by the bootstrap, admin notices and the connection test.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether WooCommerce, the Abilities API and the MCP Adapter are available.
 */
final class WCMCP_Requirements {

	/**
	 * Whether a supported WooCommerce version is active.
	 *
	 * @return bool
	 */
	public static function has_woocommerce() {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WCMCP_MIN_WC, '>=' );
	}

	/**
	 * Whether the WordPress Abilities API (core since 6.9) is available.
	 *
	 * @return bool
	 */
	public static function has_abilities_api() {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Whether the MCP Adapter plugin is active.
	 *
	 * @return bool
	 */
	public static function has_mcp_adapter() {
		return class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	/**
	 * Whether the current request is served over HTTPS, or from a local development host.
	 *
	 * @return bool
	 */
	public static function is_secure_transport() {
		if ( is_ssl() || self::is_local_host() ) {
			return true;
		}

		/**
		 * Filters whether MCP requests over plain HTTP are accepted.
		 *
		 * Only enable this for a private test environment: API keys travel in every request.
		 *
		 * @param bool $allow Default false.
		 */
		return (bool) apply_filters( 'wcmcp_allow_insecure_transport', false );
	}

	/**
	 * Whether the site runs on a local development host name.
	 *
	 * @return bool
	 */
	public static function is_local_host() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( trim( $host, '[]' ) );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		foreach ( array( '.localhost', '.test' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Human-readable problems that stop the MCP server from working. Empty when all is well.
	 *
	 * @return string[]
	 */
	public static function problems() {
		$problems = array();

		if ( ! self::has_woocommerce() ) {
			$problems[] = sprintf(
				/* translators: %s: minimum WooCommerce version. */
				__( 'WooCommerce %s or newer must be active.', 'store-mcp-for-woocommerce' ),
				WCMCP_MIN_WC
			);
		}
		if ( ! self::has_abilities_api() ) {
			$problems[] = __( 'The WordPress Abilities API is missing. Update WordPress to 6.9 or newer.', 'store-mcp-for-woocommerce' );
		}
		if ( ! self::has_mcp_adapter() ) {
			$problems[] = __( 'The MCP Adapter plugin is not active. Download mcp-adapter.zip from github.com/WordPress/mcp-adapter/releases and upload it under Plugins → Add New → Upload Plugin.', 'store-mcp-for-woocommerce' );
		}

		return $problems;
	}
}
