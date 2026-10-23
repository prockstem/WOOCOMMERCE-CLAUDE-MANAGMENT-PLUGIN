<?php
/**
 * API key authentication for the MCP endpoint.
 *
 * Requests to the plugin's REST namespace must carry an MCP API key as HTTP Basic credentials
 * (consumer key as the user name, consumer secret as the password). Cookies and Application
 * Passwords are not accepted there.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authenticates MCP requests and holds the key context for permission checks.
 */
final class WCMCP_Auth {

	/**
	 * Placeholder key sent by the admin connection test. It never matches a real key and is not rate limited.
	 */
	const TEST_CONSUMER_KEY = 'ck_wcmcp_connection_test';

	const MAX_FAILURES = 10;

	const FAILURE_WINDOW = 900;

	/**
	 * Whether authentication already ran for this request.
	 *
	 * @var bool
	 */
	private static $done = false;

	/**
	 * The authenticated key, or null.
	 *
	 * @var array{key_id:int, user_id:int, permissions:string, truncated_key:string}|null
	 */
	private static $context = null;

	/**
	 * Why authentication failed, or null.
	 *
	 * @var WP_Error|null
	 */
	private static $error = null;

	/**
	 * Hooks into WordPress authentication.
	 *
	 * @return void
	 */
	public static function init() {
		// Before Application Passwords (20) so a ck_ user name is never tried as a WordPress login.
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_current_user' ), 18 );
		add_filter( 'application_password_is_api_request', array( __CLASS__, 'disable_application_passwords' ) );
		// Before the cookie nonce check (100) so a logged-in browser cookie cannot override the key user.
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_authentication_errors' ), 15 );
	}

	/**
	 * Whether the current request targets this plugin's REST namespace.
	 *
	 * @return bool
	 */
	public static function is_mcp_request() {
		$namespace = WCMCP_Server::ROUTE_NAMESPACE . '/';

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- Only compared against a fixed prefix.
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			return 0 === strpos( ltrim( wp_unslash( $_GET['rest_route'] ), '/' ), $namespace );
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		// phpcs:enable

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return false !== strpos( $path, '/' . trailingslashit( rest_get_url_prefix() ) . $namespace );
	}

	/**
	 * The authenticated key for this request, or null.
	 *
	 * @return array{key_id:int, user_id:int, permissions:string, truncated_key:string}|null
	 */
	public static function get_context() {
		self::run();
		return self::$context;
	}

	/**
	 * Filter: on MCP requests, the key's owner is the current user.
	 *
	 * @param int|false $user_id User determined by earlier filters.
	 * @return int|false
	 */
	public static function determine_current_user( $user_id ) {
		if ( ! self::is_mcp_request() ) {
			return $user_id;
		}

		self::run();

		// No valid key means no user, even if a browser cookie identified someone.
		return self::$context ? self::$context['user_id'] : 0;
	}

	/**
	 * Filter: Application Passwords are not accepted on the MCP endpoint.
	 *
	 * @param bool $is_api_request Whether WordPress treats this as an API request.
	 * @return bool
	 */
	public static function disable_application_passwords( $is_api_request ) {
		return self::is_mcp_request() ? false : $is_api_request;
	}

	/**
	 * Filter: reports key failures for MCP requests and shields key auth from the cookie nonce check.
	 *
	 * @param WP_Error|true|null $result Earlier result.
	 * @return WP_Error|true|null
	 */
	public static function rest_authentication_errors( $result ) {
		if ( ! empty( $result ) || ! self::is_mcp_request() ) {
			return $result;
		}

		self::run();

		return self::$context ? true : self::$error;
	}

	/**
	 * Forgets the authentication result. WordPress serves one request per process, so this is
	 * only needed by the test suite, which simulates many requests in one process.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$done    = false;
		self::$context = null;
		self::$error   = null;
	}

	/**
	 * Why authentication failed for this request, if it did.
	 *
	 * @return WP_Error|null
	 */
	public static function get_error() {
		self::run();
		return self::$error;
	}

	/**
	 * Transport-level permission callback for the MCP server.
	 *
	 * @return true|WP_Error
	 */
	public static function transport_permission() {
		self::run();

		if ( self::$context ) {
			return true;
		}

		return self::$error ? self::$error : self::error( 'wcmcp_not_authenticated', __( 'Authentication required.', 'store-mcp-for-woocommerce' ), 401 );
	}

	/**
	 * Authenticates the current request once.
	 *
	 * @return void
	 */
	private static function run() {
		if ( self::$done || ! self::is_mcp_request() ) {
			return;
		}
		self::$done = true;

		if ( self::is_rate_limited() ) {
			self::$error = self::error( 'wcmcp_rate_limited', __( 'Too many failed authentication attempts. Try again in 15 minutes.', 'store-mcp-for-woocommerce' ), 429 );
			return;
		}

		$credentials = self::get_credentials();
		if ( null === $credentials ) {
			self::$error = self::error( 'wcmcp_missing_credentials', __( 'No API key received. Send the consumer key and secret as HTTP Basic credentials. If you did, your server is stripping the Authorization header.', 'store-mcp-for-woocommerce' ), 401 );
			return;
		}

		if ( ! WCMCP_Requirements::is_secure_transport() ) {
			self::$error = self::error( 'wcmcp_insecure_transport', __( 'The MCP endpoint only accepts API keys over HTTPS.', 'store-mcp-for-woocommerce' ), 403 );
			return;
		}

		list( $consumer_key, $consumer_secret ) = $credentials;

		$key = WCMCP_Api_Keys::verify( $consumer_key, $consumer_secret );
		if ( ! $key || ! get_userdata( $key['user_id'] ) ) {
			if ( self::TEST_CONSUMER_KEY !== $consumer_key ) {
				self::record_failure();
			}
			self::$error = self::error( 'wcmcp_invalid_key', __( 'The API key is invalid or has been revoked.', 'store-mcp-for-woocommerce' ), 401 );
			return;
		}

		// Same rule as key creation: the owner must still be a store manager.
		if ( ! user_can( $key['user_id'], 'manage_woocommerce' ) ) {
			self::$error = self::error( 'wcmcp_owner_not_allowed', __( 'The user who owns this API key can no longer manage the store.', 'store-mcp-for-woocommerce' ), 403 );
			return;
		}

		self::$context = $key;
		WCMCP_Api_Keys::touch( $key['key_id'] );
	}

	/**
	 * Reads HTTP Basic credentials, including from the headers some hosts rewrite.
	 *
	 * @return array{0:string, 1:string}|null
	 */
	private static function get_credentials() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Credentials are compared, never output or stored.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return array( (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ), (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) );
		}

		$header = '';
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $name ) {
			if ( ! empty( $_SERVER[ $name ] ) ) {
				$header = (string) wp_unslash( $_SERVER[ $name ] );
				break;
			}
		}
		// phpcs:enable

		if ( 0 !== stripos( $header, 'basic ' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding HTTP Basic credentials.
		$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return null;
		}

		$parts = explode( ':', $decoded, 2 );
		return array( $parts[0], $parts[1] );
	}

	/**
	 * Transient key counting failures for the requesting IP.
	 *
	 * @return string
	 */
	private static function failure_key() {
		// REMOTE_ADDR only: forwarded-for headers are client-controlled.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'wcmcp_auth_fail_' . md5( $ip );
	}

	/**
	 * Whether the requesting IP has too many recent failures.
	 *
	 * @return bool
	 */
	private static function is_rate_limited() {
		return (int) get_transient( self::failure_key() ) >= self::MAX_FAILURES;
	}

	/**
	 * Counts a failed attempt for the requesting IP.
	 *
	 * @return void
	 */
	private static function record_failure() {
		$key = self::failure_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::FAILURE_WINDOW );
	}

	/**
	 * Builds an error with an HTTP status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
