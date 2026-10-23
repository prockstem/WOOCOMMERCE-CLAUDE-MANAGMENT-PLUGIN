<?php
/**
 * Orders pack: read tools. Uses WooCommerce CRUD only, so it works with HPOS on or off.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order abilities.
 */
final class WCMCP_Abilities_Orders {

	const PACK = 'orders';

	const CAPABILITY = 'edit_shop_orders';

	const MAX_LINE_ITEMS = 100;

	const MAX_NOTES = 20;

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		return array(
			'wcmcp/orders-list' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'List orders', 'store-mcp-for-woocommerce' ),
				'description'   => 'List or search orders, newest first by default. Filter by status, customer, date range or a search term (order number, customer name, email, address, product name). Returns a compact summary per order; use orders-get for full details. Paginated.' . WCMCP_Format::UNTRUSTED_NOTE,
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'search'      => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'Search term.',
							),
							'status'      => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'Order statuses, e.g. ["processing","on-hold"]. Default: all except trash.',
							),
							'customer_id' => array(
								'type'        => 'integer',
								'minimum'     => 0,
								'description' => 'Registered customer user ID. 0 means guest orders.',
							),
							'date_after'  => WCMCP_Format::date_schema( 'Orders created on or after this date.' ),
							'date_before' => WCMCP_Format::date_schema( 'Orders created on or before this date.' ),
							'orderby'     => array(
								'type'    => 'string',
								'enum'    => array( 'date', 'id', 'total' ),
								'default' => 'date',
							),
							'order'       => array(
								'type'    => 'string',
								'enum'    => array( 'desc', 'asc' ),
								'default' => 'desc',
							),
						),
						WCMCP_Format::pagination_schema()
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => WCMCP_Format::paged_output_schema(),
				'execute'       => array( __CLASS__, 'list_orders' ),
			),
			'wcmcp/orders-get'  => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Get order', 'store-mcp-for-woocommerce' ),
				'description'   => 'Full details of one order: status, dates, totals, line items, coupons, shipping, billing and shipping addresses, refunds and recent notes. Payment details are limited to the payment method name.' . WCMCP_Format::UNTRUSTED_NOTE,
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Order ID.',
						),
						'include_notes' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Include the ' . self::MAX_NOTES . ' most recent order notes.',
						),
					),
					'required'             => array( 'order_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'get_order' ),
			),
			'wcmcp/orders-update-status' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'idempotent'    => true,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Update order status', 'store-mcp-for-woocommerce' ),
				'description'   => 'Change one order\'s status, optionally with a note explaining why. WooCommerce runs its normal side effects: it may email the customer (e.g. on completed), and cancelling or failing an order puts reserved stock back. Refunded is not available here: use the refunds tools. Before changing more than one order, list them and ask the store owner to confirm.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'status'   => array(
							'type' => 'string',
							'enum' => self::writable_statuses(),
						),
						'note'     => array(
							'type'        => 'string',
							'maxLength'   => 1000,
							'description' => 'Private note saved with the status change.',
						),
					),
					'required'             => array( 'order_id', 'status' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'update_status' ),
			),
			'wcmcp/orders-add-note'      => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Add order note', 'store-mcp-for-woocommerce' ),
				'description'   => 'Add a note to an order. Private notes are visible to staff only. A customer note is emailed to the customer immediately, so show the store owner the exact text and get a yes before sending one.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'order_id'         => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'note'             => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 2000,
						),
						'customer_visible' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'true emails the note to the customer.',
						),
					),
					'required'             => array( 'order_id', 'note' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'add_note' ),
			),
		);
	}

	/**
	 * Statuses the status tool may set. Refunded (which creates refund records) and internal
	 * checkout drafts are excluded.
	 *
	 * @return string[]
	 */
	public static function writable_statuses() {
		return array_values( array_diff( WCMCP_Format::order_statuses(), array( 'refunded', 'checkout-draft' ) ) );
	}

	/**
	 * Executes wcmcp/orders-update-status.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_status( array $input ) {
		$order_id = isset( $input['order_id'] ) ? absint( $input['order_id'] ) : 0;
		$order    = self::find( $order_id );
		if ( ! $order ) {
			return WCMCP_Format::not_found( 'Order', $order_id );
		}

		$status = isset( $input['status'] ) ? WCMCP_Format::order_status( sanitize_key( $input['status'] ) ) : '';
		if ( ! in_array( $status, self::writable_statuses(), true ) ) {
			return WCMCP_Write::error( 'wcmcp_invalid_status', 'Status must be one of: ' . implode( ', ', self::writable_statuses() ) . '.' );
		}

		$from = $order->get_status();
		if ( $from === $status ) {
			return array(
				'order'   => self::summary( $order ),
				'changes' => array(),
				'message' => 'The order already has this status. Nothing changed.',
			);
		}

		$note = isset( $input['note'] ) ? sanitize_textarea_field( $input['note'] ) : '';
		$order->update_status( $status, $note, true );

		$order = wc_get_order( $order_id );
		return array(
			'order'   => self::summary( $order ),
			'changes' => array(
				array(
					'field' => 'status',
					'from'  => $from,
					'to'    => $order->get_status(),
				),
			),
		);
	}

	/**
	 * Executes wcmcp/orders-add-note.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function add_note( array $input ) {
		$order_id = isset( $input['order_id'] ) ? absint( $input['order_id'] ) : 0;
		$order    = self::find( $order_id );
		if ( ! $order ) {
			return WCMCP_Format::not_found( 'Order', $order_id );
		}

		$note = isset( $input['note'] ) ? trim( sanitize_textarea_field( $input['note'] ) ) : '';
		if ( '' === $note ) {
			return WCMCP_Write::error( 'wcmcp_empty_note', 'The note is empty.' );
		}
		$customer_visible = ! empty( $input['customer_visible'] );

		// Attributed to the key owner, like a note added in the admin.
		$note_id = $order->add_order_note( $note, $customer_visible ? 1 : 0, true );
		if ( ! $note_id ) {
			return WCMCP_Write::error( 'wcmcp_note_failed', 'The note could not be saved.', 500 );
		}

		return array(
			'order_id'         => $order->get_id(),
			'note_id'          => (int) $note_id,
			'customer_visible' => $customer_visible,
			'emailed_customer' => $customer_visible,
			'note'             => $note,
		);
	}

	/**
	 * Executes wcmcp/orders-list.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_orders( array $input ) {
		list( $page, $per_page ) = WCMCP_Format::pagination( $input );

		$args = array(
			'type'     => 'shop_order',
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'id', 'total' ), true ) ? $input['orderby'] : 'date',
			'order'    => isset( $input['order'] ) && 'asc' === $input['order'] ? 'ASC' : 'DESC',
		);
		if ( 'total' === $args['orderby'] ) {
			// CPT storage keeps the total in meta; HPOS has a column. Both accept this key.
			$args['orderby'] = self::hpos_enabled() ? 'total' : 'meta_value_num';
			if ( ! self::hpos_enabled() ) {
				$args['meta_key'] = '_order_total'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			}
		}

		if ( ! empty( $input['status'] ) && is_array( $input['status'] ) ) {
			$valid    = WCMCP_Format::order_statuses();
			$statuses = array_values( array_intersect( array_map( array( 'WCMCP_Format', 'order_status' ), array_map( 'sanitize_key', $input['status'] ) ), $valid ) );
			if ( empty( $statuses ) ) {
				return new WP_Error( 'wcmcp_invalid_status', 'Unknown order status. Valid statuses: ' . implode( ', ', $valid ), array( 'status' => 400 ) );
			}
			$args['status'] = $statuses;
		}

		if ( isset( $input['customer_id'] ) ) {
			$args['customer_id'] = absint( $input['customer_id'] );
		}

		$range = WCMCP_Format::date_range( isset( $input['date_after'] ) ? $input['date_after'] : null, isset( $input['date_before'] ) ? $input['date_before'] : null );
		if ( is_wp_error( $range ) ) {
			return $range;
		}
		if ( null !== $range ) {
			$args['date_created'] = $range;
		}

		$search = isset( $input['search'] ) ? trim( sanitize_text_field( $input['search'] ) ) : '';
		if ( '' !== $search ) {
			if ( self::hpos_enabled() ) {
				$args['s'] = $search;
			} else {
				// The posts-table data store has no search query var; use WooCommerce's order search.
				$ids             = array_map( 'absint', (array) wc_order_search( $search ) );
				$args['post__in'] = empty( $ids ) ? array( 0 ) : $ids;
			}
		}

		$result = wc_get_orders( $args );
		$items  = array();
		foreach ( $result->orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$items[] = self::summary( $order );
			}
		}

		return WCMCP_Format::paged( $items, $result->total, $page, $per_page );
	}

	/**
	 * Executes wcmcp/orders-get.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_order( array $input ) {
		$order_id = isset( $input['order_id'] ) ? absint( $input['order_id'] ) : 0;
		$order    = self::find( $order_id );
		if ( ! $order ) {
			return WCMCP_Format::not_found( 'Order', $order_id );
		}

		$data = self::summary( $order );

		$data['date_modified']  = WCMCP_Format::date( $order->get_date_modified() );
		$data['date_paid']      = WCMCP_Format::date( $order->get_date_paid() );
		$data['date_completed'] = WCMCP_Format::date( $order->get_date_completed() );
		$data['totals']         = array(
			'subtotal'       => WCMCP_Format::money( $order->get_subtotal() ),
			'discount'       => WCMCP_Format::money( $order->get_total_discount() ),
			'shipping'       => WCMCP_Format::money( $order->get_shipping_total() ),
			'tax'            => WCMCP_Format::money( $order->get_total_tax() ),
			'fees'           => WCMCP_Format::money( array_sum( array_map( static function ( $fee ) {
				return (float) $fee->get_total();
			}, $order->get_fees() ) ) ),
			'total'          => WCMCP_Format::money( $order->get_total() ),
			'refunded'       => WCMCP_Format::money( $order->get_total_refunded() ),
			'net_after_refunds' => WCMCP_Format::money( $order->get_total() - $order->get_total_refunded() ),
		);
		$data['prices_include_tax'] = $order->get_prices_include_tax();
		$data['billing']            = WCMCP_Format::address( $order, 'billing' );
		$data['shipping']           = WCMCP_Format::address( $order, 'shipping' );
		$data['customer_note']      = WCMCP_Format::text( $order->get_customer_note(), 1000 );
		$data['created_via']        = (string) $order->get_created_via();

		$data['line_items'] = array();
		$line_items         = $order->get_items( 'line_item' );
		foreach ( array_slice( $line_items, 0, self::MAX_LINE_ITEMS ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product              = $item->get_product();
			$data['line_items'][] = array(
				'item_id'      => $item->get_id(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => WCMCP_Format::text( $item->get_name(), 200 ),
				'sku'          => $product ? (string) $product->get_sku() : '',
				'quantity'     => $item->get_quantity(),
				'refunded_quantity' => absint( $order->get_qty_refunded_for_item( $item->get_id() ) ),
				'subtotal'     => WCMCP_Format::money( $item->get_subtotal() ),
				'total'        => WCMCP_Format::money( $item->get_total() ),
				'tax'          => WCMCP_Format::money( $item->get_total_tax() ),
			);
		}
		$data['line_items_truncated'] = count( $line_items ) > self::MAX_LINE_ITEMS;

		$data['shipping_lines'] = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$data['shipping_lines'][] = array(
				'method' => WCMCP_Format::text( $item->get_method_title(), 200 ),
				'total'  => WCMCP_Format::money( $item->get_total() ),
			);
		}

		$data['coupons'] = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$data['coupons'][] = array(
				'code'     => wc_format_coupon_code( $item->get_code() ),
				'discount' => WCMCP_Format::money( $item->get_discount() ),
			);
		}

		$data['refunds'] = array();
		foreach ( $order->get_refunds() as $refund ) {
			$data['refunds'][] = array(
				'refund_id' => $refund->get_id(),
				'date'      => WCMCP_Format::date( $refund->get_date_created() ),
				'amount'    => WCMCP_Format::money( $refund->get_amount() ),
				'reason'    => WCMCP_Format::text( $refund->get_reason(), 300 ),
			);
		}

		if ( ! isset( $input['include_notes'] ) || $input['include_notes'] ) {
			$data['notes'] = array();
			$notes         = wc_get_order_notes(
				array(
					'order_id' => $order->get_id(),
					'limit'    => self::MAX_NOTES,
				)
			);
			foreach ( $notes as $note ) {
				$data['notes'][] = array(
					'note_id'          => (int) $note->id,
					'date'             => WCMCP_Format::date( $note->date_created ),
					'author'           => WCMCP_Format::text( $note->added_by, 100 ),
					'customer_visible' => (bool) $note->customer_note,
					'content'          => WCMCP_Format::text( $note->content, 1000 ),
				);
			}
		}

		return $data;
	}

	/**
	 * Compact order summary used in lists and as the base of details.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function summary( WC_Order $order ) {
		return array(
			'order_id'       => $order->get_id(),
			'number'         => (string) $order->get_order_number(),
			'status'         => $order->get_status(),
			'date_created'   => WCMCP_Format::date( $order->get_date_created() ),
			'total'          => WCMCP_Format::money( $order->get_total() ),
			'currency'       => $order->get_currency(),
			'customer_id'    => $order->get_customer_id(),
			'customer_name'  => WCMCP_Format::billing_name( $order ),
			'customer_email' => sanitize_email( $order->get_billing_email() ),
			'item_count'     => $order->get_item_count(),
			'payment_method' => WCMCP_Format::text( $order->get_payment_method_title(), 100 ),
		);
	}

	/**
	 * Loads a real order (not a refund or another order type).
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order|null
	 */
	public static function find( $order_id ) {
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return null;
		}
		return $order;
	}

	/**
	 * Whether orders are stored in the HPOS tables.
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
