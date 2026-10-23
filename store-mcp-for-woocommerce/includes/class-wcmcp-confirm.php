<?php
/**
 * Two-step confirmation for destructive tools.
 *
 * A destructive tool called without confirmation does nothing: it returns a preview of exactly
 * what would happen and a confirmation token. The token is bound to the API key, the tool and
 * the exact arguments, expires after 10 minutes and works once. Only a second call with the
 * same arguments, "confirm": true and that token is carried out.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preview tokens for destructive actions.
 */
final class WCMCP_Confirm {

	const TTL = 600;

	/**
	 * Input keys that control confirmation rather than describe the action.
	 *
	 * @var string[]
	 */
	private static $control_keys = array( 'confirm', 'confirmation_token' );

	/**
	 * Schema properties every destructive tool accepts.
	 *
	 * @return array
	 */
	public static function schema() {
		return array(
			'confirm'            => array(
				'type'        => 'boolean',
				'description' => 'Leave out on the first call to get a preview. Set to true only after the store owner has seen the preview and said yes.',
			),
			'confirmation_token' => array(
				'type'        => 'string',
				'maxLength'   => 200,
				'description' => 'The token from the preview. Required with confirm: true.',
			),
		);
	}

	/**
	 * Description text appended to destructive tools.
	 *
	 * @return string
	 */
	public static function instructions() {
		return ' This cannot be undone. Always call it first WITHOUT confirm: you get a preview and a confirmation_token, and nothing changes. Show the store owner the preview, ask them to confirm, and only if they clearly say yes call again with the same arguments plus "confirm": true and the token. Never set confirm on your own initiative.';
	}

	/**
	 * Arguments that describe the action, in a stable order.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function action_args( array $input ) {
		$args = array_diff_key( $input, array_flip( self::$control_keys ) );
		self::ksort_recursive( $args );
		return $args;
	}

	/**
	 * Preview result: nothing done, here is what would happen and the token to proceed.
	 *
	 * @param string $ability Ability name.
	 * @param array  $input   Input.
	 * @param array  $preview Description of the action.
	 * @return array
	 */
	public static function preview_response( $ability, array $input, array $preview ) {
		return array(
			'done'                  => false,
			'confirmation_required' => true,
			'preview'               => $preview,
			'confirmation_token'    => self::issue( $ability, $input ),
			'expires_in_seconds'    => self::TTL,
			'next'                  => 'Nothing has changed yet. Show this preview to the store owner. Only if they say yes, call this tool again with exactly the same arguments plus "confirm": true and this confirmation_token.',
		);
	}

	/**
	 * Checks a confirmed call and marks its token as used.
	 *
	 * @param string $ability Ability name.
	 * @param array  $input   Input including confirm and confirmation_token.
	 * @return true|WP_Error
	 */
	public static function redeem( $ability, array $input ) {
		$token = isset( $input['confirmation_token'] ) ? (string) $input['confirmation_token'] : '';
		if ( '' === $token ) {
			return self::refuse( 'wcmcp_confirmation_token_missing', 'Not done. A confirmation_token from a preview is required. Call again without confirm to get a preview, show it to the store owner, then confirm.' );
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return self::refuse( 'wcmcp_confirmation_token_invalid', 'Not done. The confirmation_token is not valid. Get a new preview.' );
		}

		$issued = (int) $parts[0];
		if ( time() - $issued > self::TTL || $issued > time() + 60 ) {
			return self::refuse( 'wcmcp_confirmation_token_expired', 'Not done. The confirmation expired. Get a new preview and ask the store owner again.' );
		}

		if ( ! hash_equals( self::signature( $ability, $input, $issued ), $parts[1] ) ) {
			return self::refuse( 'wcmcp_confirmation_mismatch', 'Not done. The arguments differ from the preview the store owner approved, or the token belongs to another key. Get a new preview for these exact arguments.' );
		}

		$used_key = 'wcmcp_used_' . md5( $token );
		if ( get_transient( $used_key ) ) {
			return self::refuse( 'wcmcp_confirmation_used', 'Not done. This confirmation was already used. Each confirmation works once; get a new preview if this really should happen again.' );
		}
		set_transient( $used_key, 1, self::TTL + 60 );

		return true;
	}

	/**
	 * Issues a token for these exact arguments.
	 *
	 * @param string $ability Ability name.
	 * @param array  $input   Input.
	 * @return string
	 */
	private static function issue( $ability, array $input ) {
		$now = time();
		return $now . '.' . self::signature( $ability, $input, $now );
	}

	/**
	 * HMAC over key, ability, arguments and issue time.
	 *
	 * @param string $ability Ability name.
	 * @param array  $input   Input.
	 * @param int    $issued  Issue time.
	 * @return string
	 */
	private static function signature( $ability, array $input, $issued ) {
		$context = WCMCP_Auth::get_context();
		$key_id  = $context ? (int) $context['key_id'] : 0;
		$payload = implode( '|', array( $key_id, $ability, (string) wp_json_encode( self::action_args( $input ) ), (int) $issued ) );
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'wcmcp-confirm' );
	}

	/**
	 * Sorts nested associative arrays by key so argument order does not matter.
	 *
	 * @param array $array Array, sorted in place.
	 * @return void
	 */
	private static function ksort_recursive( array &$array ) {
		foreach ( $array as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
		unset( $value );
		if ( array_keys( $array ) !== range( 0, count( $array ) - 1 ) ) {
			ksort( $array );
		}
	}

	/**
	 * Refusal error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function refuse( $code, $message ) {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
