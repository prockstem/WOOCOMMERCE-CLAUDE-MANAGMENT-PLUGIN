<?php
/**
 * Logs section: the log table under the log settings. Rendered inside WooCommerce's settings
 * <form>, so the Clear button posts to admin-post.php with formaction.
 *
 * @package WCMCP
 *
 * @var array|null $notice Pending notice.
 * @var array      $log    Rows and total.
 * @var int        $page   Current page.
 * @var int        $pages  Page count.
 */

defined( 'ABSPATH' ) || exit;

$wcmcp_result_labels = array(
	'success' => __( 'Success', 'store-mcp-for-woocommerce' ),
	'preview' => __( 'Preview', 'store-mcp-for-woocommerce' ),
	'denied'  => __( 'Denied', 'store-mcp-for-woocommerce' ),
	'error'   => __( 'Error', 'store-mcp-for-woocommerce' ),
);
?>
<div class="wcmcp-logs">
	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> inline"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Recent activity', 'store-mcp-for-woocommerce' ); ?></h2>
	<p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of entries, 2: retention days. */
				_n( '%1$d entry, kept for %2$d days.', '%1$d entries, kept for %2$d days.', $log['total'], 'store-mcp-for-woocommerce' ),
				$log['total'],
				WCMCP_Log::retention_days()
			)
		);
		?>
		<?php if ( ! WCMCP_Log::is_enabled() ) : ?>
			<strong><?php esc_html_e( 'Logging is switched off.', 'store-mcp-for-woocommerce' ); ?></strong>
		<?php endif; ?>
	</p>

	<table class="widefat striped wcmcp-log-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Tool', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Key / user', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details (masked)', 'store-mcp-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $log['rows'] ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No entries yet.', 'store-mcp-for-woocommerce' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $log['rows'] as $wcmcp_row ) : ?>
				<?php
				$wcmcp_user   = $wcmcp_row['user_id'] ? get_userdata( (int) $wcmcp_row['user_id'] ) : false;
				$wcmcp_status = isset( $wcmcp_result_labels[ $wcmcp_row['result'] ] ) ? $wcmcp_row['result'] : 'error';
				?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $wcmcp_row['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
					<td><code><?php echo esc_html( str_replace( 'wcmcp/', '', $wcmcp_row['tool'] ) ); ?></code></td>
					<td>
						<?php if ( $wcmcp_row['truncated_key'] ) : ?>
							<code>&hellip;<?php echo esc_html( $wcmcp_row['truncated_key'] ); ?></code><br>
						<?php endif; ?>
						<?php echo esc_html( $wcmcp_user ? $wcmcp_user->display_name : '—' ); ?>
					</td>
					<td>
						<span class="wcmcp-log-result wcmcp-log-<?php echo esc_attr( $wcmcp_status ); ?>"><?php echo esc_html( $wcmcp_result_labels[ $wcmcp_status ] ); ?></span>
						<?php if ( $wcmcp_row['code'] ) : ?>
							<br><small><?php echo esc_html( $wcmcp_row['code'] ); ?></small>
						<?php endif; ?>
					</td>
					<td><code class="wcmcp-log-details"><?php echo esc_html( (string) $wcmcp_row['details'] ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<p class="wcmcp-log-pages">
			<?php for ( $wcmcp_p = 1; $wcmcp_p <= $pages; $wcmcp_p++ ) : ?>
				<?php if ( $wcmcp_p === $page ) : ?>
					<strong><?php echo esc_html( (string) $wcmcp_p ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'log_page', $wcmcp_p, WCMCP_Admin::page_url( 'logs' ) ) ); ?>"><?php echo esc_html( (string) $wcmcp_p ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>
		</p>
	<?php endif; ?>

	<?php wp_nonce_field( 'wcmcp_clear_logs', 'wcmcp_clear_logs_nonce' ); ?>
	<p>
		<button type="submit" class="button button-link-delete wcmcp-clear-logs" formaction="<?php echo esc_url( add_query_arg( 'action', 'wcmcp_clear_logs', admin_url( 'admin-post.php' ) ) ); ?>" <?php disabled( 0 === $log['total'] ); ?>><?php esc_html_e( 'Clear logs', 'store-mcp-for-woocommerce' ); ?></button>
	</p>
</div>
