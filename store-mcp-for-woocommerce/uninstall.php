<?php
/**
 * Uninstall: removes the plugin's options, transients, log table and the API keys it issued.
 *
 * @package WCMCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes this plugin's data from the current site.
 *
 * @return void
 */
function wcmcp_uninstall_site() {
	global $wpdb;

	// API keys issued for MCP are useless without the plugin; remove them from WooCommerce's table.
	$key_ids = array_filter( array_map( 'absint', (array) get_option( 'wcmcp_key_ids', array() ) ) );
	if ( $key_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $key_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id IN ({$placeholders})", $key_ids ) );
	}

	// Options and transients (wcmcp_*, _transient_wcmcp_*, _transient_timeout_wcmcp_*).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'wcmcp_' ) . '%',
			$wpdb->esc_like( '_transient_wcmcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wcmcp_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wcmcp_log" );

	wp_cache_flush();
}

wp_clear_scheduled_hook( 'wcmcp_log_cleanup' );

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $wcmcp_site_id ) {
		switch_to_blog( $wcmcp_site_id );
		wcmcp_uninstall_site();
		restore_current_blog();
	}
} else {
	wcmcp_uninstall_site();
}
