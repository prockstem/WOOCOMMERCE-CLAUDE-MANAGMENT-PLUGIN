<?php
/**
 * Only MCP keys issued by the plugin authenticate, and only on the MCP endpoint.
 *
 * @package WCMCP
 */

/**
 * Authentication tests.
 */
class Test_WCMCP_Auth extends WCMCP_Test_Case {

	/**
	 * A valid MCP key authenticates as its owner.
	 */
	public function test_valid_key_authenticates() {
		$key = $this->create_key( 'read' );
		$this->as_mcp_request( $key['consumer_key'], $key['consumer_secret'] );

		$context = WCMCP_Auth::get_context();
		$this->assertSame( $this->admin_id, $context['user_id'] );
		$this->assertSame( 'read', $context['permissions'] );
		$this->assertSame( $this->admin_id, WCMCP_Auth::determine_current_user( 0 ) );
	}

	/**
	 * Wrong secret, unknown key and missing credentials are refused.
	 */
	public function test_bad_credentials_are_refused() {
		$key = $this->create_key( 'read' );

		$this->as_mcp_request( $key['consumer_key'], 'cs_wrong' );
		$this->assertNull( WCMCP_Auth::get_context() );
		$this->assertSame( 'wcmcp_invalid_key', WCMCP_Auth::get_error()->get_error_code() );

		$this->as_mcp_request( 'ck_unknown', 'cs_unknown' );
		$this->assertNull( WCMCP_Auth::get_context() );

		$this->as_mcp_request( '', '' );
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		WCMCP_Auth::reset();
		$this->assertSame( 'wcmcp_missing_credentials', WCMCP_Auth::get_error()->get_error_code() );
		$this->assertSame( 0, WCMCP_Auth::determine_current_user( $this->admin_id ), 'A browser login must not carry over to the MCP endpoint.' );
	}

	/**
	 * An ordinary WooCommerce REST key (not issued for MCP) is refused.
	 */
	public function test_plain_woocommerce_key_is_refused() {
		global $wpdb;
		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => $this->admin_id,
				'description'     => 'Plain REST key',
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			)
		);

		$this->as_mcp_request( $consumer_key, $consumer_secret );
		$this->assertNull( WCMCP_Auth::get_context() );
	}

	/**
	 * A revoked key stops working.
	 */
	public function test_revoked_key_is_refused() {
		$key = $this->create_key( 'read_write' );
		$this->assertTrue( WCMCP_Api_Keys::revoke( $key['key_id'] ) );
		$this->as_mcp_request( $key['consumer_key'], $key['consumer_secret'] );
		$this->assertNull( WCMCP_Auth::get_context() );
	}

	/**
	 * A key whose owner is no longer a store manager is refused.
	 */
	public function test_demoted_owner_is_refused() {
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$key     = $this->create_key( 'read_write', $manager );
		( new WP_User( $manager ) )->set_role( 'customer' );

		$this->as_mcp_request( $key['consumer_key'], $key['consumer_secret'] );
		$this->assertNull( WCMCP_Auth::get_context() );
		$this->assertSame( 'wcmcp_owner_not_allowed', WCMCP_Auth::get_error()->get_error_code() );
	}

	/**
	 * Keys can only be issued to users who can manage WooCommerce.
	 */
	public function test_keys_need_a_store_manager_owner() {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->assertWPError( WCMCP_Api_Keys::create( $customer, 'nope', 'read' ) );
		$this->assertWPError( WCMCP_Api_Keys::create( $this->admin_id, 'nope', 'superuser' ) );
	}

	/**
	 * Keys are only read on the MCP endpoint, not on other REST routes.
	 */
	public function test_key_is_ignored_outside_the_mcp_endpoint() {
		$key = $this->create_key( 'read_write' );
		$this->as_mcp_request( $key['consumer_key'], $key['consumer_secret'], '/wp-json/wc/v3/orders' );
		$this->assertFalse( WCMCP_Auth::is_mcp_request() );
		$this->assertNull( WCMCP_Auth::get_context() );
	}

	/**
	 * Keys over plain HTTP on a public host are refused.
	 */
	public function test_plain_http_on_public_host_is_refused() {
		$key = $this->create_key( 'read' );
		// wp-env pins the site URL with a constant, so override it with the filter.
		$public = static function () {
			return 'http://shop.example.com';
		};
		add_filter( 'home_url', $public );

		$this->as_mcp_request( $key['consumer_key'], $key['consumer_secret'] );
		$this->assertNull( WCMCP_Auth::get_context() );
		$this->assertSame( 'wcmcp_insecure_transport', WCMCP_Auth::get_error()->get_error_code() );

		remove_filter( 'home_url', $public );
	}

	/**
	 * Repeated failures from one address are rate limited.
	 */
	public function test_failed_attempts_are_rate_limited() {
		$key = $this->create_key( 'read' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		for ( $i = 0; $i < WCMCP_Auth::MAX_FAILURES; $i++ ) {
			$_SERVER['REQUEST_URI']   = '/wp-json/wcmcp/mcp';
			$_SERVER['PHP_AUTH_USER'] = 'ck_bad' . $i;
			$_SERVER['PHP_AUTH_PW']   = 'cs_bad';
			WCMCP_Auth::reset();
			WCMCP_Auth::get_context();
		}

		$_SERVER['PHP_AUTH_USER'] = $key['consumer_key'];
		$_SERVER['PHP_AUTH_PW']   = $key['consumer_secret'];
		WCMCP_Auth::reset();
		$this->assertNull( WCMCP_Auth::get_context(), 'Even a valid key is refused while the address is locked out.' );
		$this->assertSame( 'wcmcp_rate_limited', WCMCP_Auth::get_error()->get_error_code() );
	}
}
