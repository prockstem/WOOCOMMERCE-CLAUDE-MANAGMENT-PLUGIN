<?php
/**
 * The plugin's own MCP server, registered with the MCP Adapter.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the key-authenticated MCP server at /wp-json/wcmcp/mcp.
 */
final class WCMCP_Server {

	const SERVER_ID = 'wcmcp-store';

	const ROUTE_NAMESPACE = 'wcmcp';

	const ROUTE = 'mcp';

	const TRANSPORT_CLASS = '\WP\MCP\Transport\HttpTransport';

	/**
	 * Hooks into the adapter.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * The public MCP endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Creates the server. Only tools from switched-on packs are listed.
	 *
	 * @param object $adapter \WP\MCP\Core\McpAdapter instance.
	 * @return void
	 */
	public static function register( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		if ( ! class_exists( self::TRANSPORT_CLASS ) ) {
			wc_get_logger()->error( 'MCP Adapter HTTP transport class not found; the Store MCP server was not registered.', array( 'source' => 'store-mcp' ) );
			return;
		}

		$result = $adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			__( 'Store MCP for WooCommerce', 'store-mcp-for-woocommerce' ),
			__( 'Manage this WooCommerce store: orders, products, customers, coupons, refunds and reports.', 'store-mcp-for-woocommerce' ),
			WCMCP_VERSION,
			array( self::TRANSPORT_CLASS ),
			null,
			null,
			WCMCP_Abilities::enabled_names(),
			array(),
			array(),
			array( 'WCMCP_Auth', 'transport_permission' )
		);

		if ( is_wp_error( $result ) ) {
			wc_get_logger()->error( 'Store MCP server registration failed: ' . $result->get_error_message(), array( 'source' => 'store-mcp' ) );
		}
	}
}
