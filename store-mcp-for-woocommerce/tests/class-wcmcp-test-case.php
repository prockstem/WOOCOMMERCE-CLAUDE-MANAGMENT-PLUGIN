<?php
/**
 * Base test case: simulates MCP requests authenticated with a given API key.
 *
 * @package WCMCP
 */

/**
 * Helpers shared by the test classes.
 */
abstract class WCMCP_Test_Case extends WP_UnitTestCase {

	/**
	 * Administrator who owns the keys.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Server variables changed by a test.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Sets up an administrator and default options.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->server_backup = $_SERVER;
		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		foreach ( array_keys( WCMCP_Packs::all() ) as $pack ) {
			delete_option( WCMCP_Packs::option_name( $pack ) );
		}
		WCMCP_Auth::reset();
	}

	/**
	 * Restores server variables and authentication state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_SERVER = $this->server_backup;
		WCMCP_Auth::reset();
		parent::tear_down();
	}

	/**
	 * Creates an MCP key.
	 *
	 * @param string   $permissions read, write or read_write.
	 * @param int|null $user_id     Owner, default the administrator.
	 * @return array
	 */
	protected function create_key( $permissions, $user_id = null ) {
		$key = WCMCP_Api_Keys::create( $user_id ? $user_id : $this->admin_id, 'test ' . $permissions, $permissions );
		$this->assertIsArray( $key );
		return $key;
	}

	/**
	 * Makes the following code run as an MCP request with these credentials.
	 *
	 * @param string $consumer_key    Consumer key.
	 * @param string $consumer_secret Consumer secret.
	 * @param string $uri             Request URI.
	 * @return void
	 */
	protected function as_mcp_request( $consumer_key, $consumer_secret, $uri = '/wp-json/wcmcp/mcp' ) {
		$_SERVER['REQUEST_URI']   = $uri;
		$_SERVER['PHP_AUTH_USER'] = $consumer_key;
		$_SERVER['PHP_AUTH_PW']   = $consumer_secret;
		$_SERVER['REMOTE_ADDR']   = '203.0.113.' . wp_rand( 1, 250 );
		WCMCP_Auth::reset();
	}

	/**
	 * Runs as an MCP request authenticated with this key.
	 *
	 * @param array $key Key from create_key().
	 * @return void
	 */
	protected function use_key( array $key ) {
		$this->as_mcp_request( $key['consumer_key'], $key['consumer_secret'] );
		$this->assertNotNull( WCMCP_Auth::get_context(), 'The test key should authenticate.' );
	}

	/**
	 * Runs an ability the way the MCP server does.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Input.
	 * @return mixed
	 */
	protected function run_ability( $name, array $input = array() ) {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "Ability {$name} should be registered." );
		return $ability->execute( $input );
	}

	/**
	 * Runs an ability that must be stopped by its permission check.
	 *
	 * WP_Ability::execute() replaces a failed permission check with a generic
	 * ability_invalid_permissions error and raises a _doing_it_wrong notice carrying the original
	 * message. (Over MCP the adapter returns the original message.) The specific reason is
	 * asserted separately through WCMCP_Permissions::check().
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Input.
	 * @return void
	 */
	protected function assertAbilityDenied( $name, array $input = array() ) {
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = $this->run_ability( $name, $input );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Asserts a WP_Error, optionally with a code (the Abilities API may wrap permission errors,
	 * so the code is checked on the error or in its data).
	 *
	 * @param mixed  $result Result.
	 * @param string $code   Expected code.
	 * @return void
	 */
	protected function assertRefused( $result, $code = '' ) {
		$this->assertWPError( $result );
		if ( '' !== $code ) {
			$codes = $result->get_error_codes();
			$this->assertTrue(
				in_array( $code, $codes, true ) || false !== strpos( (string) wp_json_encode( $result->get_all_error_data() ), $code ) || $this->message_matches( $result, $code ),
				"Expected error {$code}, got " . implode( ', ', $codes ) . ': ' . $result->get_error_message()
			);
		}
	}

	/**
	 * Whether a wrapped permission error still carries the original message for this code.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $code  Code.
	 * @return bool
	 */
	private function message_matches( WP_Error $error, $code ) {
		$messages = array(
			'wcmcp_key_read_only'     => 'read-only',
			'wcmcp_key_cannot_read'   => 'write-only',
			'wcmcp_pack_disabled'     => 'switched off',
			'wcmcp_not_authenticated' => 'valid API key',
		);
		return isset( $messages[ $code ] ) && false !== stripos( implode( ' ', $error->get_error_messages() ), $messages[ $code ] );
	}

	/**
	 * Creates a published coupon.
	 *
	 * @param string $code Code.
	 * @return WC_Coupon
	 */
	protected function create_coupon( $code ) {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 5 );
		$coupon->save();
		return $coupon;
	}
}
