<?php
/**
 * Tool packs: groups of abilities a store manager can switch on and off.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pack definitions and their on/off state (stored as WooCommerce yes/no options).
 */
final class WCMCP_Packs {

	/**
	 * The core pack (store info) is always on; it holds no store-changing tools.
	 */
	const CORE = 'core';

	/**
	 * Switchable packs. Packs that can change or remove data start switched off.
	 *
	 * @return array<string, array{label:string, description:string, default:bool}>
	 */
	public static function all() {
		return array(
			'orders'    => array(
				'label'       => __( 'Orders', 'store-mcp-for-woocommerce' ),
				'description' => __( 'List and search orders, view details, update status, add notes.', 'store-mcp-for-woocommerce' ),
				'default'     => true,
			),
			'products'  => array(
				'label'       => __( 'Products', 'store-mcp-for-woocommerce' ),
				'description' => __( 'List and search products and variations; create and update price, stock, description and categories.', 'store-mcp-for-woocommerce' ),
				'default'     => true,
			),
			'customers' => array(
				'label'       => __( 'Customers', 'store-mcp-for-woocommerce' ),
				'description' => __( 'List and search customers, view and update customer details.', 'store-mcp-for-woocommerce' ),
				'default'     => true,
			),
			'reports'   => array(
				'label'       => __( 'Reports', 'store-mcp-for-woocommerce' ),
				'description' => __( 'Sales by period, top products, top customers, low stock. Read-only.', 'store-mcp-for-woocommerce' ),
				'default'     => true,
			),
			'coupons'   => array(
				'label'       => __( 'Coupons', 'store-mcp-for-woocommerce' ),
				'description' => __( 'List, create, update and delete coupons.', 'store-mcp-for-woocommerce' ),
				'default'     => false,
			),
			'refunds'   => array(
				'label'       => __( 'Refunds', 'store-mcp-for-woocommerce' ),
				'description' => __( 'Create refunds, optionally restocking items. Always asks for confirmation.', 'store-mcp-for-woocommerce' ),
				'default'     => false,
			),
		);
	}

	/**
	 * Option name storing a pack's state.
	 *
	 * @param string $pack Pack ID.
	 * @return string
	 */
	public static function option_name( $pack ) {
		return 'wcmcp_pack_' . $pack;
	}

	/**
	 * Whether a pack is switched on. Unknown packs are always off.
	 *
	 * @param string $pack Pack ID.
	 * @return bool
	 */
	public static function is_enabled( $pack ) {
		if ( self::CORE === $pack ) {
			return true;
		}

		$packs = self::all();
		if ( ! isset( $packs[ $pack ] ) ) {
			return false;
		}

		$default = $packs[ $pack ]['default'] ? 'yes' : 'no';
		return 'yes' === get_option( self::option_name( $pack ), $default );
	}

	/**
	 * IDs of the packs currently switched on, including core.
	 *
	 * @return string[]
	 */
	public static function enabled() {
		$enabled = array( self::CORE );
		foreach ( array_keys( self::all() ) as $pack ) {
			if ( self::is_enabled( $pack ) ) {
				$enabled[] = $pack;
			}
		}
		return $enabled;
	}
}
