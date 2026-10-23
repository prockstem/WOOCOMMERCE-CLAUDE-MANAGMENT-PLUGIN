<?php
/**
 * Core pack: always on, read-only.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store information ability, used to confirm the connection from Claude.
 */
final class WCMCP_Abilities_Core {

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		return array(
			'wcmcp/store-info' => array(
				'pack'          => WCMCP_Packs::CORE,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => 'manage_woocommerce',
				'label'         => __( 'Store info', 'store-mcp-for-woocommerce' ),
				'description'   => __( 'Returns the store name, currency, timezone, units, WooCommerce version, which tool packs are switched on and what the current API key is allowed to do. Call this first to learn the store context.', 'store-mcp-for-woocommerce' ),
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_packs' => array(
							'type'        => 'boolean',
							'description' => 'Include the list of tool packs and whether each is on.',
							'default'     => true,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'store_name'          => array( 'type' => 'string' ),
						'store_url'           => array( 'type' => 'string' ),
						'currency'            => array( 'type' => 'string' ),
						'currency_symbol'     => array( 'type' => 'string' ),
						'timezone'            => array( 'type' => 'string' ),
						'weight_unit'         => array( 'type' => 'string' ),
						'dimension_unit'      => array( 'type' => 'string' ),
						'woocommerce_version' => array( 'type' => 'string' ),
						'plugin_version'      => array( 'type' => 'string' ),
						'hpos_enabled'        => array( 'type' => 'boolean' ),
						'api_key'             => array( 'type' => 'object' ),
						'packs'               => array( 'type' => 'array' ),
					),
				),
				'execute'       => array( __CLASS__, 'store_info' ),
			),
		);
	}

	/**
	 * Executes wcmcp/store-info.
	 *
	 * @param array $input Validated input.
	 * @return array
	 */
	public static function store_info( array $input ) {
		$context = WCMCP_Auth::get_context();
		$levels  = WCMCP_Api_Keys::permission_levels();

		$hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$info = array(
			'store_name'          => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'store_url'           => home_url( '/' ),
			'currency'            => get_woocommerce_currency(),
			'currency_symbol'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'timezone'            => wp_timezone_string(),
			'weight_unit'         => (string) get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'      => (string) get_option( 'woocommerce_dimension_unit' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'plugin_version'      => WCMCP_VERSION,
			'hpos_enabled'        => $hpos,
			'api_key'             => array(
				'permissions' => $context ? $context['permissions'] : '',
				'label'       => $context && isset( $levels[ $context['permissions'] ] ) ? $levels[ $context['permissions'] ] : '',
				'can_read'    => $context && in_array( $context['permissions'], array( 'read', 'read_write' ), true ),
				'can_write'   => $context && in_array( $context['permissions'], array( 'write', 'read_write' ), true ),
			),
		);

		if ( ! isset( $input['include_packs'] ) || $input['include_packs'] ) {
			$info['packs'] = array();
			foreach ( WCMCP_Packs::all() as $id => $pack ) {
				$info['packs'][] = array(
					'id'      => $id,
					'label'   => $pack['label'],
					'enabled' => WCMCP_Packs::is_enabled( $id ),
				);
			}
		}

		return $info;
	}
}
