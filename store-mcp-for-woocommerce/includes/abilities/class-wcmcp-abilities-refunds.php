<?php
/**
 * Refunds pack: create refunds, optionally per line item with restock, behind preview + confirm.
 *
 * By default a refund is recorded in WooCommerce only ("manual refund"): no money moves and the
 * store owner pays the customer back themselves. Sending money through the payment gateway
 * happens only with refund_payment: true and a gateway that supports automatic refunds.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Refund abilities.
 */
final class WCMCP_Abilities_Refunds {

	const PACK = 'refunds';

	const CAPABILITY = 'edit_shop_orders';

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		return array(
			'wcmcp/refunds-create' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_DESTRUCTIVE,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Create refund', 'store-mcp-for-woocommerce' ),
				'description'   => 'Refund an order: a set amount, or specific line items and quantities (amount worked out automatically, tax included). Optionally put refunded items back in stock. By default the refund is only recorded in WooCommerce and no money is sent; refund_payment: true sends the money back through the payment gateway, if it supports that. A full refund marks the order Refunded.' . WCMCP_Confirm::instructions(),
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'order_id'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'amount'         => WCMCP_Write::price_schema( 'Amount to refund. Leave out when refunding line_items.' ),
							'line_items'     => array(
								'type'        => 'array',
								'description' => 'Items to refund, by item_id from orders-get, e.g. [{"item_id":12,"quantity":1}].',
								'items'       => array(
									'type'                 => 'object',
									'properties'           => array(
										'item_id'  => array(
											'type'    => 'integer',
											'minimum' => 1,
										),
										'quantity' => array(
											'type'    => 'integer',
											'minimum' => 1,
										),
									),
									'required'             => array( 'item_id', 'quantity' ),
									'additionalProperties' => false,
								),
							),
							'restock'        => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'Put refunded line items back in stock. Needs line_items.',
							),
							'reason'         => array(
								'type'      => 'string',
								'maxLength' => 500,
							),
							'refund_payment' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'true sends the money back through the payment gateway. false only records the refund.',
							),
						),
						WCMCP_Confirm::schema()
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'preview'       => array( __CLASS__, 'preview' ),
				'execute'       => array( __CLASS__, 'create_refund' ),
			),
		);
	}

	/**
	 * Step 1: validates the refund and describes it. Changes nothing.
	 *
	 * @param array $input Input without confirmation fields.
	 * @return array|WP_Error
	 */
	public static function preview( array $input ) {
		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		unset( $plan['wc_line_items'] );
		return $plan;
	}

	/**
	 * Step 2: creates the refund after a confirmed preview.
	 *
	 * @param array $input Input without confirmation fields.
	 * @return array|WP_Error
	 */
	public static function create_refund( array $input ) {
		// Re-check against the order as it is now; it may have changed since the preview.
		$plan = self::plan( $input );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $plan['order_id'],
				'amount'         => $plan['amount'],
				'reason'         => $plan['reason'],
				'line_items'     => $plan['wc_line_items'],
				'refund_payment' => $plan['refund_payment'],
				'restock_items'  => $plan['restock'],
			)
		);

		if ( is_wp_error( $refund ) ) {
			return WCMCP_Write::error( 'wcmcp_refund_failed', 'The refund was not created: ' . $refund->get_error_message() );
		}

		$order = wc_get_order( $plan['order_id'] );
		unset( $plan['wc_line_items'] );

		return array(
			'done'      => true,
			'refund_id' => $refund->get_id(),
			'refund'    => $plan,
			'order'     => WCMCP_Abilities_Orders::summary( $order ),
			'remaining_refundable' => WCMCP_Format::money( $order->get_remaining_refund_amount() ),
		);
	}

	/**
	 * Validates input against the order and builds the refund.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	private static function plan( array $input ) {
		$order_id = isset( $input['order_id'] ) ? absint( $input['order_id'] ) : 0;
		$order    = WCMCP_Abilities_Orders::find( $order_id );
		if ( ! $order ) {
			return WCMCP_Format::not_found( 'Order', $order_id );
		}

		$remaining = (float) $order->get_remaining_refund_amount();
		if ( $remaining <= 0 ) {
			return WCMCP_Write::error( 'wcmcp_nothing_to_refund', 'This order has already been fully refunded.' );
		}

		$decimals  = wc_get_price_decimals();
		$restock   = ! empty( $input['restock'] );
		$items_in  = isset( $input['line_items'] ) ? (array) $input['line_items'] : array();
		$wc_items  = array();
		$described = array();
		$computed  = 0.0;

		if ( $restock && empty( $items_in ) ) {
			return WCMCP_Write::error( 'wcmcp_restock_needs_items', 'Restocking needs line_items, so WooCommerce knows which products to put back.' );
		}

		foreach ( $items_in as $row ) {
			$item_id  = isset( $row['item_id'] ) ? absint( $row['item_id'] ) : 0;
			$quantity = isset( $row['quantity'] ) ? absint( $row['quantity'] ) : 0;
			$item     = $item_id ? $order->get_item( $item_id ) : false;

			if ( ! $item instanceof WC_Order_Item_Product ) {
				return WCMCP_Write::error( 'wcmcp_unknown_item', sprintf( 'Item %d is not a product line on this order. Use the item_id values from orders-get.', $item_id ) );
			}
			if ( isset( $wc_items[ $item_id ] ) ) {
				return WCMCP_Write::error( 'wcmcp_duplicate_item', sprintf( 'Item %d is listed twice.', $item_id ) );
			}

			$ordered   = (int) $item->get_quantity();
			$refunded  = absint( $order->get_qty_refunded_for_item( $item_id ) );
			$available = $ordered - $refunded;
			if ( $quantity < 1 || $quantity > $available ) {
				return WCMCP_Write::error( 'wcmcp_invalid_quantity', sprintf( '%s: %d can still be refunded (ordered %d, already refunded %d).', $item->get_name(), max( 0, $available ), $ordered, $refunded ) );
			}

			$share = $quantity / $ordered;
			$total = round( (float) $item->get_total() * $share, $decimals );
			$taxes = array();
			$tax   = 0.0;
			$item_taxes = $item->get_taxes();
			foreach ( isset( $item_taxes['total'] ) ? $item_taxes['total'] : array() as $rate_id => $rate_total ) {
				$amount            = round( (float) $rate_total * $share, $decimals );
				$taxes[ $rate_id ] = $amount;
				$tax              += $amount;
			}

			$wc_items[ $item_id ] = array(
				'qty'          => $quantity,
				'refund_total' => $total,
				'refund_tax'   => $taxes,
			);
			$computed            += $total + $tax;
			$described[]          = array(
				'item_id'  => $item_id,
				'name'     => WCMCP_Format::text( $item->get_name(), 200 ),
				'quantity' => $quantity,
				'amount'   => WCMCP_Format::money( $total + $tax ),
				'restock'  => $restock && $item->get_product() && $item->get_product()->managing_stock(),
			);
		}

		$has_amount = isset( $input['amount'] ) && '' !== $input['amount'];
		if ( $has_amount ) {
			$amount = WCMCP_Write::price( $input['amount'], 'amount' );
			if ( is_wp_error( $amount ) ) {
				return $amount;
			}
			$amount = (float) $amount;
			if ( $wc_items && abs( $amount - $computed ) > 0.01 ) {
				return WCMCP_Write::error( 'wcmcp_amount_mismatch', sprintf( 'The line items add up to %s, not %s. Leave out amount to use the line item total.', WCMCP_Format::money( $computed ), WCMCP_Format::money( $amount ) ) );
			}
		} elseif ( $wc_items ) {
			$amount = $computed;
		} else {
			return WCMCP_Write::error( 'wcmcp_amount_required', 'Pass an amount, or line_items to refund.' );
		}

		$amount = round( $amount, $decimals );
		if ( $amount <= 0 ) {
			return WCMCP_Write::error( 'wcmcp_invalid_amount', 'The refund amount must be more than 0.' );
		}
		if ( $amount > $remaining + 0.001 ) {
			return WCMCP_Write::error( 'wcmcp_amount_too_high', sprintf( 'At most %s can still be refunded on this order.', WCMCP_Format::money( $remaining ) ) );
		}

		$refund_payment = ! empty( $input['refund_payment'] );
		$gateway_title  = (string) $order->get_payment_method_title();
		if ( $refund_payment ) {
			$gateway = wc_get_payment_gateway_by_order( $order );
			if ( ! $gateway || ! $gateway->can_refund_order( $order ) ) {
				return WCMCP_Write::error( 'wcmcp_gateway_cannot_refund', sprintf( 'The payment method "%s" cannot send refunds automatically. Use refund_payment: false to record the refund, then pay the customer back yourself.', $gateway_title ) );
			}
		}

		return array(
			'order_id'             => $order->get_id(),
			'order_number'         => (string) $order->get_order_number(),
			'customer'             => WCMCP_Format::billing_name( $order ),
			'amount'               => WCMCP_Format::money( $amount ),
			'currency'             => $order->get_currency(),
			'order_total'          => WCMCP_Format::money( $order->get_total() ),
			'already_refunded'     => WCMCP_Format::money( $order->get_total_refunded() ),
			'remaining_after'      => WCMCP_Format::money( $remaining - $amount ),
			'full_refund'          => abs( $remaining - $amount ) < 0.001,
			'line_items'           => $described,
			'restock'              => $restock,
			'refund_payment'       => $refund_payment,
			'money_movement'       => $refund_payment
				? sprintf( 'Money will be sent back to the customer through %s.', $gateway_title )
				: 'No money will be sent. The refund is only recorded; pay the customer back yourself.',
			'reason'               => isset( $input['reason'] ) ? sanitize_textarea_field( $input['reason'] ) : '',
			'wc_line_items'        => $wc_items,
		);
	}
}
