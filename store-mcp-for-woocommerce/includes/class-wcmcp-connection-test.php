<?php
/**
 * Admin connection test: requirement checks plus real requests from the site to its own MCP endpoint.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the checks behind the "Test connection" button.
 */
final class WCMCP_Connection_Test {

	const PROTOCOL_VERSION = '2025-11-25';

	/**
	 * Runs all checks.
	 *
	 * @param string $consumer_key    Optional plain consumer key for the end-to-end test.
	 * @param string $consumer_secret Optional plain consumer secret.
	 * @return array<int, array{label:string, status:string, message:string}>
	 */
	public static function run( $consumer_key = '', $consumer_secret = '' ) {
		$checks = array();

		$checks[] = self::check(
			__( 'WooCommerce', 'store-mcp-for-woocommerce' ),
			WCMCP_Requirements::has_woocommerce(),
			/* translators: %s: WooCommerce version. */
			sprintf( __( 'WooCommerce %s is active.', 'store-mcp-for-woocommerce' ), defined( 'WC_VERSION' ) ? WC_VERSION : '?' ),
			/* translators: %s: minimum WooCommerce version. */
			sprintf( __( 'WooCommerce %s or newer is required.', 'store-mcp-for-woocommerce' ), WCMCP_MIN_WC )
		);
		$checks[] = self::check(
			__( 'Abilities API', 'store-mcp-for-woocommerce' ),
			WCMCP_Requirements::has_abilities_api(),
			__( 'Available.', 'store-mcp-for-woocommerce' ),
			__( 'Missing. Update WordPress to 6.9 or newer.', 'store-mcp-for-woocommerce' )
		);
		$checks[] = self::check(
			__( 'MCP Adapter', 'store-mcp-for-woocommerce' ),
			WCMCP_Requirements::has_mcp_adapter(),
			__( 'Active.', 'store-mcp-for-woocommerce' ),
			__( 'Not active. Install and activate the MCP Adapter plugin.', 'store-mcp-for-woocommerce' )
		);
		$checks[] = self::check(
			__( 'HTTPS', 'store-mcp-for-woocommerce' ),
			WCMCP_Requirements::is_secure_transport(),
			is_ssl() ? __( 'The site uses HTTPS.', 'store-mcp-for-woocommerce' ) : __( 'Local development host: plain HTTP allowed.', 'store-mcp-for-woocommerce' ),
			__( 'The site is not on HTTPS, so API keys are refused. Enable HTTPS first.', 'store-mcp-for-woocommerce' )
		);

		if ( ! WCMCP_Requirements::has_mcp_adapter() || ! WCMCP_Requirements::has_abilities_api() ) {
			return $checks;
		}

		$routes     = rest_get_server()->get_routes();
		$route      = '/' . WCMCP_Server::ROUTE_NAMESPACE . '/' . WCMCP_Server::ROUTE;
		$has_route  = isset( $routes[ $route ] );
		$checks[]   = self::check(
			__( 'MCP endpoint', 'store-mcp-for-woocommerce' ),
			$has_route,
			/* translators: %s: endpoint URL. */
			sprintf( __( 'Registered at %s', 'store-mcp-for-woocommerce' ), WCMCP_Server::endpoint_url() ),
			__( 'Not registered. Check WooCommerce → Status → Logs (source: store-mcp) for errors.', 'store-mcp-for-woocommerce' )
		);
		if ( ! $has_route ) {
			return $checks;
		}

		$checks[] = self::header_check();

		if ( '' !== $consumer_key || '' !== $consumer_secret ) {
			$checks = array_merge( $checks, self::end_to_end( $consumer_key, $consumer_secret ) );
		}

		return $checks;
	}

	/**
	 * Sends a placeholder key to learn whether the Authorization header survives the web server.
	 *
	 * @return array{label:string, status:string, message:string}
	 */
	private static function header_check() {
		$label    = __( 'Authorization header', 'store-mcp-for-woocommerce' );
		$response = self::rpc( WCMCP_Auth::TEST_CONSUMER_KEY, 'cs_wcmcp_connection_test', 'initialize', self::initialize_params(), 1 );

		if ( is_wp_error( $response ) ) {
			return self::result(
				$label,
				'warn',
				/* translators: %s: HTTP error message. */
				sprintf( __( 'The site could not reach its own endpoint (%s). Some hosts block this; test from Claude instead.', 'store-mcp-for-woocommerce' ), $response->get_error_message() )
			);
		}

		$code = self::body_error_code( $response );

		switch ( $code ) {
			case 'wcmcp_invalid_key':
				return self::result( $label, 'pass', __( 'Your server passes API keys through to WordPress.', 'store-mcp-for-woocommerce' ) );
			case 'wcmcp_missing_credentials':
				return self::result( $label, 'fail', __( 'Your server removes the Authorization header, so keys never reach WordPress. On Apache, add this to .htaccess above the WordPress rules: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1', 'store-mcp-for-woocommerce' ) );
			case 'wcmcp_insecure_transport':
				return self::result( $label, 'fail', __( 'Keys are refused because the request did not arrive over HTTPS.', 'store-mcp-for-woocommerce' ) );
			case 'wcmcp_rate_limited':
				return self::result( $label, 'warn', __( 'Too many failed attempts from this server. Wait 15 minutes.', 'store-mcp-for-woocommerce' ) );
		}

		return self::result(
			$label,
			'warn',
			/* translators: 1: HTTP status, 2: error code. */
			sprintf( __( 'Unexpected response: HTTP %1$s %2$s. A security plugin or firewall may be filtering REST requests.', 'store-mcp-for-woocommerce' ), wp_remote_retrieve_response_code( $response ), $code )
		);
	}

	/**
	 * Signs in with a real key, lists tools and calls store info.
	 *
	 * @param string $consumer_key    Plain consumer key.
	 * @param string $consumer_secret Plain consumer secret.
	 * @return array<int, array{label:string, status:string, message:string}>
	 */
	private static function end_to_end( $consumer_key, $consumer_secret ) {
		$checks = array();

		$label    = __( 'Sign in with key', 'store-mcp-for-woocommerce' );
		$response = self::rpc( $consumer_key, $consumer_secret, 'initialize', self::initialize_params(), 1 );
		if ( is_wp_error( $response ) ) {
			$checks[] = self::result( $label, 'fail', $response->get_error_message() );
			return $checks;
		}

		$body = self::decode( wp_remote_retrieve_body( $response ) );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $body['result']['serverInfo'] ) ) {
			$checks[] = self::result( $label, 'fail', self::error_message( $response, $body ) );
			return $checks;
		}

		$checks[] = self::result(
			$label,
			'pass',
			/* translators: %s: MCP server name. */
			sprintf( __( 'Connected to "%s".', 'store-mcp-for-woocommerce' ), (string) $body['result']['serverInfo']['name'] )
		);

		$session = (string) wp_remote_retrieve_header( $response, 'mcp-session-id' );
		self::rpc( $consumer_key, $consumer_secret, 'notifications/initialized', new stdClass(), null, $session );

		$label    = __( 'Tool list', 'store-mcp-for-woocommerce' );
		$response = self::rpc( $consumer_key, $consumer_secret, 'tools/list', new stdClass(), 2, $session );
		$body     = is_wp_error( $response ) ? array() : self::decode( wp_remote_retrieve_body( $response ) );
		if ( ! isset( $body['result']['tools'] ) || ! is_array( $body['result']['tools'] ) ) {
			$checks[] = self::result( $label, 'fail', is_wp_error( $response ) ? $response->get_error_message() : self::error_message( $response, $body ) );
			return $checks;
		}

		$names = array_values( array_filter( array_map( static function ( $tool ) {
			return isset( $tool['name'] ) ? (string) $tool['name'] : '';
		}, $body['result']['tools'] ) ) );

		if ( empty( $names ) ) {
			$checks[] = self::result( $label, 'fail', __( 'Signed in, but no tools are listed. The MCP Adapter version may not expose non-public abilities on custom servers; please report this.', 'store-mcp-for-woocommerce' ) );
			return $checks;
		}

		$checks[] = self::result(
			$label,
			'pass',
			/* translators: 1: number of tools, 2: tool names. */
			sprintf( _n( '%1$d tool: %2$s', '%1$d tools: %2$s', count( $names ), 'store-mcp-for-woocommerce' ), count( $names ), implode( ', ', $names ) )
		);

		$label     = __( 'Call Store info', 'store-mcp-for-woocommerce' );
		$tool_name = '';
		foreach ( $names as $name ) {
			if ( false !== strpos( $name, 'store-info' ) ) {
				$tool_name = $name;
				break;
			}
		}
		if ( '' === $tool_name ) {
			$checks[] = self::result( $label, 'warn', __( 'Store info tool not found in the list.', 'store-mcp-for-woocommerce' ) );
			return $checks;
		}

		$response = self::rpc(
			$consumer_key,
			$consumer_secret,
			'tools/call',
			array(
				'name'      => $tool_name,
				'arguments' => array( 'include_packs' => false ),
			),
			3,
			$session
		);
		$body     = is_wp_error( $response ) ? array() : self::decode( wp_remote_retrieve_body( $response ) );
		$info     = self::tool_payload( $body );

		if ( ! empty( $body['result']['isError'] ) || empty( $info['store_name'] ) ) {
			$checks[] = self::result( $label, 'fail', is_wp_error( $response ) ? $response->get_error_message() : self::error_message( $response, $body ) );
			return $checks;
		}

		$checks[] = self::result(
			$label,
			'pass',
			/* translators: 1: store name, 2: key permission level. */
			sprintf( __( 'Store "%1$s" answered. This key has %2$s access.', 'store-mcp-for-woocommerce' ), (string) $info['store_name'], isset( $info['api_key']['label'] ) ? (string) $info['api_key']['label'] : '?' )
		);

		return $checks;
	}

	/**
	 * MCP initialize parameters.
	 *
	 * @return array
	 */
	private static function initialize_params() {
		return array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => new stdClass(),
			'clientInfo'      => array(
				'name'    => 'store-mcp-connection-test',
				'version' => WCMCP_VERSION,
			),
		);
	}

	/**
	 * Sends one JSON-RPC message to the MCP endpoint.
	 *
	 * @param string          $consumer_key    Consumer key.
	 * @param string          $consumer_secret Consumer secret.
	 * @param string          $method          JSON-RPC method.
	 * @param array|\stdClass $params          Parameters.
	 * @param int|null        $id              Request ID, or null for a notification.
	 * @param string          $session         MCP session ID.
	 * @return array|WP_Error
	 */
	private static function rpc( $consumer_key, $consumer_secret, $method, $params, $id, $session = '' ) {
		$message = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
			'params'  => $params,
		);
		if ( null !== $id ) {
			$message['id'] = $id;
		}

		$headers = array(
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json, text/event-stream',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
			'Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret ),
		);
		if ( 'initialize' !== $method ) {
			$headers['MCP-Protocol-Version'] = self::PROTOCOL_VERSION;
		}
		if ( '' !== $session ) {
			$headers['Mcp-Session-Id'] = $session;
		}

		return wp_remote_post(
			WCMCP_Server::endpoint_url(),
			array(
				'headers'     => $headers,
				'body'        => wp_json_encode( $message ),
				'timeout'     => 20,
				'redirection' => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Decodes a JSON or server-sent-events response body.
	 *
	 * @param string $raw Body.
	 * @return array
	 */
	private static function decode( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' !== $raw && '{' !== $raw[0] ) {
			// SSE: use the last data line.
			$data = '';
			foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$data = trim( substr( $line, 5 ) );
				}
			}
			$raw = $data;
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Error code from a REST error body.
	 *
	 * @param array $response HTTP response.
	 * @return string
	 */
	private static function body_error_code( $response ) {
		$body = self::decode( wp_remote_retrieve_body( $response ) );
		return isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';
	}

	/**
	 * Best available error message from a REST or JSON-RPC error.
	 *
	 * @param array $response HTTP response.
	 * @param array $body     Decoded body.
	 * @return string
	 */
	private static function error_message( $response, array $body ) {
		if ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			return $body['message'];
		}
		if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			return $body['error']['message'];
		}
		if ( isset( $body['result']['content'][0]['text'] ) && is_string( $body['result']['content'][0]['text'] ) ) {
			return $body['result']['content'][0]['text'];
		}
		/* translators: %s: HTTP status code. */
		return sprintf( __( 'Unexpected response (HTTP %s).', 'store-mcp-for-woocommerce' ), wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * Structured output of a tools/call result.
	 *
	 * @param array $body Decoded body.
	 * @return array
	 */
	private static function tool_payload( array $body ) {
		if ( isset( $body['result']['structuredContent'] ) && is_array( $body['result']['structuredContent'] ) ) {
			return $body['result']['structuredContent'];
		}
		if ( isset( $body['result']['content'][0]['text'] ) ) {
			$decoded = json_decode( (string) $body['result']['content'][0]['text'], true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	/**
	 * Pass/fail check.
	 *
	 * @param string $label        Label.
	 * @param bool   $ok           Outcome.
	 * @param string $pass_message Message on pass.
	 * @param string $fail_message Message on failure.
	 * @return array{label:string, status:string, message:string}
	 */
	private static function check( $label, $ok, $pass_message, $fail_message ) {
		return self::result( $label, $ok ? 'pass' : 'fail', $ok ? $pass_message : $fail_message );
	}

	/**
	 * A single result row.
	 *
	 * @param string $label   Label.
	 * @param string $status  pass, warn or fail.
	 * @param string $message Message.
	 * @return array{label:string, status:string, message:string}
	 */
	private static function result( $label, $status, $message ) {
		return array(
			'label'   => $label,
			'status'  => $status,
			'message' => wp_strip_all_tags( $message ),
		);
	}
}
