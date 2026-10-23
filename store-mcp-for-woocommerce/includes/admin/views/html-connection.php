<?php
/**
 * Connection section. Rendered inside WooCommerce's settings <form>, so buttons use formaction
 * to post to admin-post.php instead of nesting forms.
 *
 * @package WCMCP
 *
 * @var array|null $notice   Pending notice.
 * @var array|null $new_key  Just-created key, shown once.
 * @var array      $keys     Issued keys.
 * @var array      $levels   Permission levels.
 * @var string[]   $problems Requirement problems.
 * @var array      $configs  Client configurations.
 */

defined( 'ABSPATH' ) || exit;

$wcmcp_post_url = admin_url( 'admin-post.php' );
?>
<div class="wcmcp-connection">

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> inline"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( $problems ) : ?>
		<div class="notice notice-error inline">
			<p><strong><?php esc_html_e( 'The MCP server cannot run yet:', 'store-mcp-for-woocommerce' ); ?></strong></p>
			<ul>
				<?php foreach ( $problems as $wcmcp_problem ) : ?>
					<li><?php echo esc_html( $wcmcp_problem ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( ! WCMCP_Requirements::is_secure_transport() ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site is not using HTTPS. The MCP endpoint refuses API keys over plain HTTP; enable HTTPS before connecting Claude.', 'store-mcp-for-woocommerce' ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'MCP endpoint', 'store-mcp-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Claude connects here. Requests must include one of the API keys below.', 'store-mcp-for-woocommerce' ); ?></p>
	<div class="wcmcp-copy-row">
		<code id="wcmcp-endpoint"><?php echo esc_html( WCMCP_Server::endpoint_url() ); ?></code>
		<button type="button" class="button wcmcp-copy" data-copy-target="wcmcp-endpoint"><?php esc_html_e( 'Copy', 'store-mcp-for-woocommerce' ); ?></button>
	</div>

	<?php if ( $new_key ) : ?>
		<div class="wcmcp-new-key notice notice-warning inline">
			<p><strong><?php esc_html_e( 'New API key. Copy the secret now: it is shown only once.', 'store-mcp-for-woocommerce' ); ?></strong></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Consumer key', 'store-mcp-for-woocommerce' ); ?></th>
					<td class="wcmcp-copy-row"><code id="wcmcp-new-ck"><?php echo esc_html( $new_key['consumer_key'] ); ?></code> <button type="button" class="button wcmcp-copy" data-copy-target="wcmcp-new-ck"><?php esc_html_e( 'Copy', 'store-mcp-for-woocommerce' ); ?></button></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Consumer secret', 'store-mcp-for-woocommerce' ); ?></th>
					<td class="wcmcp-copy-row"><code id="wcmcp-new-cs"><?php echo esc_html( $new_key['consumer_secret'] ); ?></code> <button type="button" class="button wcmcp-copy" data-copy-target="wcmcp-new-cs"><?php esc_html_e( 'Copy', 'store-mcp-for-woocommerce' ); ?></button></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Permissions', 'store-mcp-for-woocommerce' ); ?></th>
					<td><?php echo esc_html( isset( $levels[ $new_key['permissions'] ] ) ? $levels[ $new_key['permissions'] ] : $new_key['permissions'] ); ?></td>
				</tr>
			</table>
			<p><?php esc_html_e( 'The configurations below already contain this key.', 'store-mcp-for-woocommerce' ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'API keys', 'store-mcp-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Each key acts as its owner, limited by its permission level. Read keys can never create, update or delete. Write keys cannot read. Use Read/Write for full management. Only keys created here work with MCP.', 'store-mcp-for-woocommerce' ); ?></p>

	<?php wp_nonce_field( 'wcmcp_revoke_key', 'wcmcp_revoke_nonce' ); ?>
	<table class="widefat striped wcmcp-keys">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Description', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Key ending in', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Owner', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Permissions', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last used', 'store-mcp-for-woocommerce' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'store-mcp-for-woocommerce' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $keys ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No MCP keys yet. Create one below.', 'store-mcp-for-woocommerce' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $keys as $wcmcp_key ) : ?>
				<?php $wcmcp_owner = get_userdata( $wcmcp_key['user_id'] ); ?>
				<tr>
					<td><?php echo esc_html( $wcmcp_key['description'] ); ?></td>
					<td><code>&hellip;<?php echo esc_html( $wcmcp_key['truncated_key'] ); ?></code></td>
					<td><?php echo esc_html( $wcmcp_owner ? $wcmcp_owner->display_name : __( '(deleted user)', 'store-mcp-for-woocommerce' ) ); ?></td>
					<td><?php echo esc_html( isset( $levels[ $wcmcp_key['permissions'] ] ) ? $levels[ $wcmcp_key['permissions'] ] : $wcmcp_key['permissions'] ); ?></td>
					<td><?php echo esc_html( $wcmcp_key['last_access'] ? $wcmcp_key['last_access'] : __( 'Never', 'store-mcp-for-woocommerce' ) ); ?></td>
					<td>
						<button type="submit" class="button button-link-delete wcmcp-revoke" name="wcmcp_revoke_key" value="<?php echo esc_attr( (string) $wcmcp_key['key_id'] ); ?>" formaction="<?php echo esc_url( add_query_arg( 'action', 'wcmcp_revoke_key', $wcmcp_post_url ) ); ?>"><?php esc_html_e( 'Revoke', 'store-mcp-for-woocommerce' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Create an API key', 'store-mcp-for-woocommerce' ); ?></h3>
	<?php wp_nonce_field( 'wcmcp_create_key', 'wcmcp_create_nonce' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wcmcp_key_label"><?php esc_html_e( 'Description', 'store-mcp-for-woocommerce' ); ?></label></th>
			<td><input type="text" class="regular-text" id="wcmcp_key_label" name="wcmcp_key_label" placeholder="<?php esc_attr_e( 'e.g. Claude Desktop on my laptop', 'store-mcp-for-woocommerce' ); ?>" maxlength="150"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcmcp_key_user"><?php esc_html_e( 'Owner', 'store-mcp-for-woocommerce' ); ?></label></th>
			<td>
				<?php
				wp_dropdown_users(
					array(
						'name'       => 'wcmcp_key_user',
						'id'         => 'wcmcp_key_user',
						'selected'   => get_current_user_id(),
						'capability' => 'manage_woocommerce',
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'The key can do only what this user can do. A dedicated Shop Manager account is safest.', 'store-mcp-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="wcmcp_key_permissions"><?php esc_html_e( 'Permissions', 'store-mcp-for-woocommerce' ); ?></label></th>
			<td>
				<select id="wcmcp_key_permissions" name="wcmcp_key_permissions">
					<?php foreach ( $levels as $wcmcp_level => $wcmcp_level_label ) : ?>
						<option value="<?php echo esc_attr( $wcmcp_level ); ?>" <?php selected( 'read', $wcmcp_level ); ?>><?php echo esc_html( $wcmcp_level_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>
	<p>
		<button type="submit" class="button button-primary" id="wcmcp-create-button" formaction="<?php echo esc_url( add_query_arg( 'action', 'wcmcp_create_key', $wcmcp_post_url ) ); ?>"><?php esc_html_e( 'Create API key', 'store-mcp-for-woocommerce' ); ?></button>
	</p>

	<h2><?php esc_html_e( 'Connect Claude', 'store-mcp-for-woocommerce' ); ?></h2>
	<?php if ( ! $new_key ) : ?>
		<p><?php esc_html_e( 'Replace the placeholders with your key. Secrets are never shown again after creation, so create a new key if you lost one.', 'store-mcp-for-woocommerce' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Claude Desktop', 'store-mcp-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Settings → Developer → Edit Config, merge this into claude_desktop_config.json, then restart Claude. Requires Node.js.', 'store-mcp-for-woocommerce' ); ?></p>
	<div class="wcmcp-snippet">
		<textarea id="wcmcp-config-desktop" class="large-text code" rows="14" readonly><?php echo esc_textarea( $configs['desktop'] ); ?></textarea>
		<button type="button" class="button wcmcp-copy" data-copy-target="wcmcp-config-desktop"><?php esc_html_e( 'Copy', 'store-mcp-for-woocommerce' ); ?></button>
	</div>

	<h3><?php esc_html_e( 'Claude Code', 'store-mcp-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Run in a terminal. Connects directly over HTTP, no Node.js needed:', 'store-mcp-for-woocommerce' ); ?></p>
	<div class="wcmcp-snippet">
		<textarea id="wcmcp-config-code-http" class="large-text code" rows="3" readonly><?php echo esc_textarea( $configs['code_http'] ); ?></textarea>
		<button type="button" class="button wcmcp-copy" data-copy-target="wcmcp-config-code-http"><?php esc_html_e( 'Copy', 'store-mcp-for-woocommerce' ); ?></button>
	</div>
	<p><?php esc_html_e( 'Or through the same proxy Claude Desktop uses:', 'store-mcp-for-woocommerce' ); ?></p>
	<div class="wcmcp-snippet">
		<textarea id="wcmcp-config-code-proxy" class="large-text code" rows="3" readonly><?php echo esc_textarea( $configs['code_proxy'] ); ?></textarea>
		<button type="button" class="button wcmcp-copy" data-copy-target="wcmcp-config-code-proxy"><?php esc_html_e( 'Copy', 'store-mcp-for-woocommerce' ); ?></button>
	</div>
	<p class="description"><?php esc_html_e( 'These contain your secret. Do not paste them into shared documents or commit them to version control.', 'store-mcp-for-woocommerce' ); ?></p>

	<h2><?php esc_html_e( 'Test connection', 'store-mcp-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Checks the requirements, that the endpoint is reachable and that your server passes the Authorization header through. Paste a key to also run a full sign-in, list the tools and call Store info. The key is used for this test only and is not saved.', 'store-mcp-for-woocommerce' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="wcmcp_test_ck"><?php esc_html_e( 'Consumer key (optional)', 'store-mcp-for-woocommerce' ); ?></label></th>
			<td><input type="text" class="regular-text code" id="wcmcp_test_ck" autocomplete="off" spellcheck="false" placeholder="ck_…" value="<?php echo $new_key ? esc_attr( $new_key['consumer_key'] ) : ''; ?>"></td>
		</tr>
		<tr>
			<th scope="row"><label for="wcmcp_test_cs"><?php esc_html_e( 'Consumer secret (optional)', 'store-mcp-for-woocommerce' ); ?></label></th>
			<td><input type="password" class="regular-text code" id="wcmcp_test_cs" autocomplete="new-password" spellcheck="false" placeholder="cs_…" value="<?php echo $new_key ? esc_attr( $new_key['consumer_secret'] ) : ''; ?>"></td>
		</tr>
	</table>
	<p><button type="button" class="button" id="wcmcp-test-button"><?php esc_html_e( 'Test connection', 'store-mcp-for-woocommerce' ); ?></button></p>
	<ul id="wcmcp-test-results" class="wcmcp-test-results" aria-live="polite"></ul>
</div>
