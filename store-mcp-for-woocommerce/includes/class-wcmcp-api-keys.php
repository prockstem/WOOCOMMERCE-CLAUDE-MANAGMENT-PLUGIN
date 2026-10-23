<?php
/**
 * WooCommerce REST API keys issued for MCP.
 *
 * Keys live in WooCommerce's own woocommerce_api_keys table (WooCommerce has no CRUD class for it),
 * so they also appear under WooCommerce → Settings → Advanced → REST API. Only keys created from this
 * plugin are accepted by the MCP server; their IDs are tracked in the wcmcp_key_ids option.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, lists, revokes and verifies MCP API keys.
 */
final class WCMCP_Api_Keys {

	const OPTION = 'wcmcp_key_ids';

	const DESCRIPTION_PREFIX = 'MCP - ';

	/**
	 * Permission levels, matching WooCommerce's REST key semantics.
	 *
	 * @return array<string, string>
	 */
	public static function permission_levels() {
		return array(
			'read'       => __( 'Read', 'store-mcp-for-woocommerce' ),
			'write'      => __( 'Write', 'store-mcp-for-woocommerce' ),
			'read_write' => __( 'Read/Write', 'store-mcp-for-woocommerce' ),
		);
	}

	/**
	 * WooCommerce's API keys table.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'woocommerce_api_keys';
	}

	/**
	 * IDs of keys issued by this plugin.
	 *
	 * @return int[]
	 */
	private static function tracked_ids() {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_values( array_unique( array_map( 'absint', $ids ) ) ) : array();
	}

	/**
	 * Saves the tracked ID list.
	 *
	 * @param int[] $ids Key IDs.
	 * @return void
	 */
	private static function save_tracked_ids( array $ids ) {
		update_option( self::OPTION, array_values( array_unique( array_map( 'absint', $ids ) ) ), false );
	}

	/**
	 * Creates a key. The consumer secret is returned here only; show it once and do not store it elsewhere.
	 *
	 * @param int    $user_id     Owner. The key acts with this user's capabilities.
	 * @param string $label       Human label.
	 * @param string $permissions read, write or read_write.
	 * @return array{key_id:int, consumer_key:string, consumer_secret:string, permissions:string}|WP_Error
	 */
	public static function create( $user_id, $label, $permissions ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$label   = sanitize_text_field( $label );

		if ( ! array_key_exists( $permissions, self::permission_levels() ) ) {
			return new WP_Error( 'wcmcp_invalid_permissions', __( 'Choose a valid permission level.', 'store-mcp-for-woocommerce' ) );
		}
		if ( ! $user_id || ! user_can( $user_id, 'manage_woocommerce' ) ) {
			return new WP_Error( 'wcmcp_invalid_user', __( 'The key owner must be a user who can manage WooCommerce.', 'store-mcp-for-woocommerce' ) );
		}
		if ( '' === $label ) {
			$label = __( 'Claude', 'store-mcp-for-woocommerce' );
		}

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		// Same storage format WooCommerce uses when it creates keys (WC_AJAX::update_api_key).
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'user_id'         => $user_id,
				'description'     => self::DESCRIPTION_PREFIX . $label,
				'permissions'     => $permissions,
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'wcmcp_key_insert_failed', __( 'The API key could not be saved.', 'store-mcp-for-woocommerce' ) );
		}

		$key_id = (int) $wpdb->insert_id;
		$ids    = self::tracked_ids();
		$ids[]  = $key_id;
		self::save_tracked_ids( $ids );

		return array(
			'key_id'          => $key_id,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
			'permissions'     => $permissions,
		);
	}

	/**
	 * Deletes a key issued by this plugin.
	 *
	 * @param int $key_id Key ID.
	 * @return bool True when the key was revoked.
	 */
	public static function revoke( $key_id ) {
		global $wpdb;

		$key_id = absint( $key_id );
		$ids    = self::tracked_ids();
		if ( ! $key_id || ! in_array( $key_id, $ids, true ) ) {
			return false;
		}

		$wpdb->delete( self::table(), array( 'key_id' => $key_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		self::save_tracked_ids( array_diff( $ids, array( $key_id ) ) );

		return true;
	}

	/**
	 * Keys issued by this plugin, without hashes or secrets.
	 *
	 * @return array<int, array{key_id:int, user_id:int, description:string, permissions:string, truncated_key:string, last_access:?string}>
	 */
	public static function all() {
		global $wpdb;

		$ids = self::tracked_ids();
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT key_id, user_id, description, permissions, truncated_key, last_access FROM ' . self::table() . " WHERE key_id IN ({$placeholders}) ORDER BY key_id DESC", $ids ), ARRAY_A );

		$keys      = array();
		$found_ids = array();
		foreach ( (array) $rows as $row ) {
			$found_ids[] = (int) $row['key_id'];
			$keys[]      = array(
				'key_id'        => (int) $row['key_id'],
				'user_id'       => (int) $row['user_id'],
				'description'   => (string) $row['description'],
				'permissions'   => (string) $row['permissions'],
				'truncated_key' => (string) $row['truncated_key'],
				'last_access'   => $row['last_access'] ? (string) $row['last_access'] : null,
			);
		}

		// Forget keys that were deleted elsewhere, e.g. from WooCommerce's REST API screen.
		if ( count( $found_ids ) !== count( $ids ) ) {
			self::save_tracked_ids( $found_ids );
		}

		return $keys;
	}

	/**
	 * Looks up an MCP key by its plain consumer key and secret.
	 *
	 * @param string $consumer_key    Plain ck_ value.
	 * @param string $consumer_secret Plain cs_ value.
	 * @return array{key_id:int, user_id:int, permissions:string, truncated_key:string}|null
	 */
	public static function verify( $consumer_key, $consumer_secret ) {
		global $wpdb;

		if ( 0 !== strpos( $consumer_key, 'ck_' ) || 0 !== strpos( $consumer_secret, 'cs_' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT key_id, user_id, permissions, consumer_secret, truncated_key FROM ' . self::table() . ' WHERE consumer_key = %s', wc_api_hash( $consumer_key ) ) );

		if ( ! $row || ! hash_equals( (string) $row->consumer_secret, $consumer_secret ) ) {
			return null;
		}
		if ( ! in_array( (int) $row->key_id, self::tracked_ids(), true ) ) {
			// A regular WooCommerce REST key, not one issued for MCP.
			return null;
		}

		return array(
			'key_id'        => (int) $row->key_id,
			'user_id'       => (int) $row->user_id,
			'permissions'   => (string) $row->permissions,
			'truncated_key' => (string) $row->truncated_key,
		);
	}

	/**
	 * Records the last time a key was used.
	 *
	 * @param int $key_id Key ID.
	 * @return void
	 */
	public static function touch( $key_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'last_access' => current_time( 'mysql' ) ), array( 'key_id' => absint( $key_id ) ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
