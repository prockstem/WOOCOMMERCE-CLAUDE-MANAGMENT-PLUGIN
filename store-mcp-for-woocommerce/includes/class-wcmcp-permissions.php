<?php
/**
 * Per-call permission checks for every MCP ability.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the authenticated key may run an ability right now.
 */
final class WCMCP_Permissions {

	const OP_READ = 'read';

	const OP_WRITE = 'write';

	const OP_DESTRUCTIVE = 'destructive';

	/**
	 * Checks key, pack, key permission level and user capability. Runs on every call.
	 *
	 * @param string $pack       Pack the ability belongs to.
	 * @param string $op         One of the OP_* constants.
	 * @param string $capability WordPress capability the key owner needs.
	 * @return true|WP_Error
	 */
	public static function check( $pack, $op, $capability ) {
		$context = WCMCP_Auth::get_context();
		if ( ! $context ) {
			return self::deny( 'wcmcp_not_authenticated', __( 'This tool can only be used through the Store MCP endpoint with a valid API key.', 'store-mcp-for-woocommerce' ), 401 );
		}

		if ( ! WCMCP_Packs::is_enabled( $pack ) ) {
			return self::deny( 'wcmcp_pack_disabled', __( 'This tool pack is switched off in WooCommerce → Settings → MCP → Tool packs.', 'store-mcp-for-woocommerce' ) );
		}

		$level = $context['permissions'];
		if ( self::OP_READ === $op ) {
			if ( ! in_array( $level, array( 'read', 'read_write' ), true ) ) {
				return self::deny( 'wcmcp_key_cannot_read', __( 'This API key is write-only and cannot read store data.', 'store-mcp-for-woocommerce' ) );
			}
		} elseif ( in_array( $op, array( self::OP_WRITE, self::OP_DESTRUCTIVE ), true ) ) {
			if ( ! in_array( $level, array( 'write', 'read_write' ), true ) ) {
				return self::deny( 'wcmcp_key_read_only', __( 'This API key is read-only and cannot create, update or delete anything.', 'store-mcp-for-woocommerce' ) );
			}
		} else {
			// Unknown operation types are never allowed.
			return self::deny( 'wcmcp_unknown_operation', __( 'This tool is misconfigured.', 'store-mcp-for-woocommerce' ), 500 );
		}

		if ( ! user_can( $context['user_id'], $capability ) ) {
			return self::deny( 'wcmcp_insufficient_capability', __( 'The user who owns this API key is not allowed to do this.', 'store-mcp-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * Builds a denial error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message shown to the assistant.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function deny( $code, $message, $status = 403 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
