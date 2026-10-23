<?php
/**
 * Admin wiring: settings tab, key management handlers, connection test and assets.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
final class WCMCP_Admin {

	const TAB = 'wcmcp';

	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Hooks admin functionality.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_get_settings_pages', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_post_wcmcp_create_key', array( __CLASS__, 'handle_create_key' ) );
		add_action( 'admin_post_wcmcp_revoke_key', array( __CLASS__, 'handle_revoke_key' ) );
		add_action( 'admin_post_wcmcp_clear_logs', array( __CLASS__, 'handle_clear_logs' ) );
		add_action( 'wp_ajax_wcmcp_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCMCP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings screen URL.
	 *
	 * @param string $section Section ID.
	 * @return string
	 */
	public static function page_url( $section = '' ) {
		$args = array(
			'page' => 'wc-settings',
			'tab'  => self::TAB,
		);
		if ( '' !== $section ) {
			$args['section'] = $section;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Registers WooCommerce → Settings → MCP.
	 *
	 * @param array $pages Settings pages.
	 * @return array
	 */
	public static function add_settings_page( $pages ) {
		require_once WCMCP_DIR . 'includes/admin/class-wcmcp-settings-page.php';
		$pages[] = new WCMCP_Settings_Page();
		return $pages;
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'store-mcp-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Handles the "Create API key" button.
	 *
	 * @return void
	 */
	public static function handle_create_key() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage MCP keys.', 'store-mcp-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'wcmcp_create_key', 'wcmcp_create_nonce' );

		$label       = isset( $_POST['wcmcp_key_label'] ) ? sanitize_text_field( wp_unslash( $_POST['wcmcp_key_label'] ) ) : '';
		$user_id     = isset( $_POST['wcmcp_key_user'] ) ? absint( $_POST['wcmcp_key_user'] ) : get_current_user_id();
		$permissions = isset( $_POST['wcmcp_key_permissions'] ) ? sanitize_key( wp_unslash( $_POST['wcmcp_key_permissions'] ) ) : 'read';

		// Same rule as WooCommerce: issuing a key for someone else requires being able to edit that user.
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
			self::flash_notice( 'error', __( 'You cannot create a key for that user.', 'store-mcp-for-woocommerce' ) );
			self::redirect();
		}

		$key = WCMCP_Api_Keys::create( $user_id, $label, $permissions );
		if ( is_wp_error( $key ) ) {
			self::flash_notice( 'error', $key->get_error_message() );
			self::redirect();
		}

		// Shown once on the next page load, then deleted.
		set_transient( self::new_key_transient(), $key, 5 * MINUTE_IN_SECONDS );
		self::flash_notice( 'success', __( 'API key created. Copy the secret now: it will not be shown again.', 'store-mcp-for-woocommerce' ) );
		self::redirect();
	}

	/**
	 * Handles a "Revoke" button.
	 *
	 * @return void
	 */
	public static function handle_revoke_key() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage MCP keys.', 'store-mcp-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'wcmcp_revoke_key', 'wcmcp_revoke_nonce' );

		$key_id = isset( $_POST['wcmcp_revoke_key'] ) ? absint( $_POST['wcmcp_revoke_key'] ) : 0;

		if ( WCMCP_Api_Keys::revoke( $key_id ) ) {
			self::flash_notice( 'success', __( 'API key revoked. Clients using it can no longer connect.', 'store-mcp-for-woocommerce' ) );
		} else {
			self::flash_notice( 'error', __( 'That key was not found.', 'store-mcp-for-woocommerce' ) );
		}
		self::redirect();
	}

	/**
	 * Handles the "Clear logs" button.
	 *
	 * @return void
	 */
	public static function handle_clear_logs() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the MCP log.', 'store-mcp-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'wcmcp_clear_logs', 'wcmcp_clear_logs_nonce' );

		WCMCP_Log::clear();
		self::flash_notice( 'success', __( 'The MCP log was cleared.', 'store-mcp-for-woocommerce' ) );
		wp_safe_redirect( self::page_url( 'logs' ) );
		exit;
	}

	/**
	 * AJAX: runs the connection test.
	 *
	 * @return void
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'wcmcp_test_connection', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to run this test.', 'store-mcp-for-woocommerce' ) ), 403 );
		}

		// Optional: a key pasted for a full end-to-end test. Used for this request only, never stored.
		$consumer_key    = isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '';
		$consumer_secret = isset( $_POST['consumer_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_secret'] ) ) : '';

		wp_send_json_success( array( 'checks' => WCMCP_Connection_Test::run( $consumer_key, $consumer_secret ) ) );
	}

	/**
	 * Loads the settings screen script and styles.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix || self::TAB !== $tab ) {
			return;
		}

		wp_enqueue_style( 'wcmcp-admin', WCMCP_URL . 'assets/css/admin.css', array(), WCMCP_VERSION );
		wp_enqueue_script( 'wcmcp-admin', WCMCP_URL . 'assets/js/admin.js', array(), WCMCP_VERSION, true );
		wp_localize_script(
			'wcmcp-admin',
			'wcmcpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wcmcp_test_connection' ),
				'i18n'    => array(
					'copied'        => __( 'Copied', 'store-mcp-for-woocommerce' ),
					'copy'          => __( 'Copy', 'store-mcp-for-woocommerce' ),
					'testing'       => __( 'Testing…', 'store-mcp-for-woocommerce' ),
					'test'          => __( 'Test connection', 'store-mcp-for-woocommerce' ),
					'failed'        => __( 'The test could not run. Reload the page and try again.', 'store-mcp-for-woocommerce' ),
					'confirmRevoke' => __( 'Revoke this key? Any Claude setup using it stops working immediately.', 'store-mcp-for-woocommerce' ),
					'confirmClear'  => __( 'Delete all MCP log entries? This cannot be undone.', 'store-mcp-for-woocommerce' ),
					'pass'          => __( 'Pass', 'store-mcp-for-woocommerce' ),
					'warn'          => __( 'Warning', 'store-mcp-for-woocommerce' ),
					'fail'          => __( 'Fail', 'store-mcp-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Takes the just-created key for display, once.
	 *
	 * @return array|null
	 */
	public static function pull_new_key() {
		$key = get_transient( self::new_key_transient() );
		delete_transient( self::new_key_transient() );
		return is_array( $key ) ? $key : null;
	}

	/**
	 * Takes the pending notice for display, once.
	 *
	 * @return array{type:string, message:string}|null
	 */
	public static function pull_notice() {
		$notice = get_transient( self::notice_transient() );
		delete_transient( self::notice_transient() );
		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Copy-paste client configurations.
	 *
	 * @param string $consumer_key    Plain key, or empty for placeholders.
	 * @param string $consumer_secret Plain secret, or empty for placeholders.
	 * @return array{desktop:string, code_http:string, code_proxy:string}
	 */
	public static function client_configs( $consumer_key = '', $consumer_secret = '' ) {
		$url    = WCMCP_Server::endpoint_url();
		$has    = '' !== $consumer_key && '' !== $consumer_secret;
		$key    = $has ? $consumer_key : 'ck_YOUR_CONSUMER_KEY';
		$secret = $has ? $consumer_secret : 'cs_YOUR_CONSUMER_SECRET';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
		$basic = $has ? base64_encode( $key . ':' . $secret ) : 'BASE64_OF_ck_KEY:cs_SECRET';

		$desktop = array(
			'mcpServers' => array(
				'woocommerce-store' => array(
					'command' => 'npx',
					'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
					'env'     => array(
						'WP_API_URL'      => $url,
						'WP_API_USERNAME' => $key,
						'WP_API_PASSWORD' => $secret,
					),
				),
			),
		);

		return array(
			'desktop'    => (string) wp_json_encode( $desktop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'code_http'  => sprintf( 'claude mcp add --transport http woocommerce-store %s --header "Authorization: Basic %s"', $url, $basic ),
			'code_proxy' => sprintf( 'claude mcp add woocommerce-store -e WP_API_URL=%s -e WP_API_USERNAME=%s -e WP_API_PASSWORD=%s -- npx -y @automattic/mcp-wordpress-remote@latest', $url, $key, $secret ),
		);
	}

	/**
	 * Per-user transient holding a just-created key.
	 *
	 * @return string
	 */
	private static function new_key_transient() {
		return 'wcmcp_new_key_' . get_current_user_id();
	}

	/**
	 * Per-user transient holding a notice.
	 *
	 * @return string
	 */
	private static function notice_transient() {
		return 'wcmcp_notice_' . get_current_user_id();
	}

	/**
	 * Stores a notice for the next page load.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message.
	 * @return void
	 */
	private static function flash_notice( $type, $message ) {
		set_transient(
			self::notice_transient(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Returns to the Connection section.
	 *
	 * @return void
	 */
	private static function redirect() {
		wp_safe_redirect( self::page_url() );
		exit;
	}
}
