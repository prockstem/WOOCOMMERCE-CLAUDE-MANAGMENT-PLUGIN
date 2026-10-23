<?php
/**
 * Registers the plugin's abilities with the WordPress Abilities API.
 *
 * Each pack class supplies definitions; this registry wraps every one with the shared
 * permission check, so no ability can be registered without it.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ability registry.
 */
final class WCMCP_Abilities {

	const CATEGORY = 'wcmcp-store';

	/**
	 * Cached definitions.
	 *
	 * @var array<string, array>|null
	 */
	private static $definitions = null;

	/**
	 * Hooks registration into the Abilities API.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Ability definitions keyed by ability name.
	 *
	 * Each definition: pack, op (WCMCP_Permissions::OP_*), capability, label, description,
	 * input_schema, output_schema, execute (callable receiving the input array).
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		if ( null === self::$definitions ) {
			self::$definitions = array_merge(
				WCMCP_Abilities_Core::definitions(),
				WCMCP_Abilities_Orders::definitions(),
				WCMCP_Abilities_Products::definitions(),
				WCMCP_Abilities_Products_Write::definitions(),
				WCMCP_Abilities_Customers::definitions(),
				WCMCP_Abilities_Coupons::definitions(),
				WCMCP_Abilities_Refunds::definitions(),
				WCMCP_Abilities_Reports::definitions()
			);
		}
		return self::$definitions;
	}

	/**
	 * Ability names the MCP server lists: pack switched on and, when a key is known, allowed for
	 * that key's level (Read keys never see write tools). Every call is still checked separately.
	 *
	 * @return string[]
	 */
	public static function enabled_names() {
		$context = WCMCP_Auth::get_context();
		$level   = $context ? $context['permissions'] : null;

		$names = array();
		foreach ( self::definitions() as $name => $definition ) {
			if ( ! WCMCP_Packs::is_enabled( $definition['pack'] ) ) {
				continue;
			}
			$is_read = WCMCP_Permissions::OP_READ === $definition['op'];
			if ( 'read' === $level && ! $is_read ) {
				continue;
			}
			if ( 'write' === $level && $is_read ) {
				continue;
			}
			$names[] = $name;
		}
		return $names;
	}

	/**
	 * Registers the ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Store MCP', 'store-mcp-for-woocommerce' ),
				'description' => __( 'WooCommerce store management tools for AI assistants.', 'store-mcp-for-woocommerce' ),
			)
		);
	}

	/**
	 * Registers every ability with the permission wrapper.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		foreach ( self::definitions() as $name => $definition ) {
			$op = $definition['op'];

			// Destructive tools cannot be registered without a preview step.
			if ( WCMCP_Permissions::OP_DESTRUCTIVE === $op && ( ! isset( $definition['preview'] ) || ! is_callable( $definition['preview'] ) ) ) {
				continue;
			}

			$args = array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => self::CATEGORY,
				'execute_callback'    => static function ( $input = null ) use ( $name, $definition ) {
					$input  = is_array( $input ) ? $input : array();
					$result = self::run( $name, $definition, $input );
					WCMCP_Log::record( $name, $definition['op'], $input, $result );
					return $result;
				},
				'permission_callback' => static function ( $input = null ) use ( $name, $definition ) {
					$allowed = WCMCP_Permissions::check( $definition['pack'], $definition['op'], $definition['capability'] );
					if ( is_wp_error( $allowed ) ) {
						WCMCP_Log::record( $name, $definition['op'], is_array( $input ) ? $input : array(), $allowed );
					}
					return $allowed;
				},
				'meta'                => array(
					// Not exposed through the Abilities REST routes or the adapter's default server:
					// only the plugin's own key-authenticated server lists these tools.
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => WCMCP_Permissions::OP_READ === $op,
						'destructive' => WCMCP_Permissions::OP_DESTRUCTIVE === $op,
						'idempotent'  => isset( $definition['idempotent'] ) ? (bool) $definition['idempotent'] : WCMCP_Permissions::OP_READ === $op,
					),
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
				),
			);

			if ( ! empty( $definition['input_schema'] ) ) {
				$args['input_schema'] = $definition['input_schema'];
			}
			if ( ! empty( $definition['output_schema'] ) ) {
				$args['output_schema'] = $definition['output_schema'];
			}

			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Runs one tool call: permission check, the confirmation steps for destructive tools, then
	 * the tool itself. Exceptions become error results.
	 *
	 * @param string $name       Ability name.
	 * @param array  $definition Definition.
	 * @param array  $input      Input.
	 * @return mixed Tool result or WP_Error.
	 */
	private static function run( $name, array $definition, array $input ) {
		// Check again inside execution, in case a caller skipped the permission callback.
		$allowed = WCMCP_Permissions::check( $definition['pack'], $definition['op'], $definition['capability'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		try {
			if ( WCMCP_Permissions::OP_DESTRUCTIVE === $definition['op'] ) {
				// Step 1: no confirmation yet, so validate and describe, change nothing.
				if ( ! isset( $input['confirm'] ) || true !== $input['confirm'] ) {
					$plan = call_user_func( $definition['preview'], WCMCP_Confirm::action_args( $input ) );
					return is_wp_error( $plan ) ? $plan : WCMCP_Confirm::preview_response( $name, $input, $plan );
				}
				// Step 2: confirmed with a valid, unused token for these exact arguments.
				$redeemed = WCMCP_Confirm::redeem( $name, $input );
				if ( is_wp_error( $redeemed ) ) {
					return $redeemed;
				}
				$input = WCMCP_Confirm::action_args( $input );
			}

			return call_user_func( $definition['execute'], $input );
		} catch ( WC_Data_Exception $e ) {
			// WooCommerce setters reject invalid values (duplicate SKU, bad email…) by throwing.
			return new WP_Error( 'wcmcp_invalid_data', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( Exception $e ) {
			wc_get_logger()->error( 'Store MCP tool failed: ' . $e->getMessage(), array( 'source' => 'store-mcp' ) );
			return new WP_Error( 'wcmcp_failed', __( 'The store could not complete this action. Details are in WooCommerce → Status → Logs (source: store-mcp).', 'store-mcp-for-woocommerce' ), array( 'status' => 500 ) );
		}
	}
}
