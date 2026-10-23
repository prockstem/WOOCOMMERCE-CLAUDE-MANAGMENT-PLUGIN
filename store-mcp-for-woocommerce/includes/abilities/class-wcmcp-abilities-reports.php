<?php
/**
 * Reports pack: read-only sales and stock reports built from WooCommerce CRUD data.
 *
 * Sales figures come from orders in paid statuses created in the period, loaded in batches.
 * Refunds are counted against the order they belong to. Very large periods are capped and
 * flagged so the response stays small and the request stays fast.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report abilities.
 */
final class WCMCP_Abilities_Reports {

	const PACK = 'reports';

	const CAPABILITY = 'view_woocommerce_reports';

	const BATCH = 200;

	const MAX_ORDERS = 5000;

	const MAX_LIMIT = 50;

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		$period = self::period_schema();

		return array(
			'wcmcp/reports-sales'         => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Sales report', 'store-mcp-for-woocommerce' ),
				'description'   => 'Sales totals for a period: orders, items sold, gross sales, refunds, tax, shipping, discounts, net sales and average order value, optionally broken down by day, week or month. Counts orders in paid statuses (processing, completed) by creation date.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						$period,
						array(
							'group_by' => array(
								'type'    => 'string',
								'enum'    => array( 'none', 'day', 'week', 'month' ),
								'default' => 'none',
							),
						)
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'sales' ),
			),
			'wcmcp/reports-top-products'  => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Top products', 'store-mcp-for-woocommerce' ),
				'description'   => 'Best-selling products in a period, ranked by revenue (line totals after discounts, before tax) or quantity, from orders in paid statuses.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						$period,
						array(
							'rank_by'          => array(
								'type'    => 'string',
								'enum'    => array( 'revenue', 'quantity' ),
								'default' => 'revenue',
							),
							'split_variations' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'Rank each variation separately instead of grouping under the parent product.',
							),
							'limit'            => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => self::MAX_LIMIT,
								'default' => 10,
							),
						)
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'top_products' ),
			),
			'wcmcp/reports-top-customers' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Top customers', 'store-mcp-for-woocommerce' ),
				'description'   => 'Customers who spent the most in a period (order totals minus refunds), from orders in paid statuses. Guests are grouped by billing email.' . WCMCP_Format::UNTRUSTED_NOTE,
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						$period,
						array(
							'limit' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => self::MAX_LIMIT,
								'default' => 10,
							),
						)
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'top_customers' ),
			),
			'wcmcp/reports-low-stock'     => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Low stock', 'store-mcp-for-woocommerce' ),
				'description'   => 'Products and variations with stock tracking whose stock is at or below a threshold (default: the store\'s low stock setting), lowest first. Optionally includes items that are out of stock.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'threshold'           => array(
							'type'        => 'integer',
							'description' => 'Stock level to report at or below. Default: WooCommerce → Settings → Products → Inventory → Low stock threshold.',
						),
						'include_out_of_stock' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'limit'               => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_LIMIT,
							'default' => 25,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'low_stock' ),
			),
		);
	}

	/**
	 * Schema properties for choosing a period.
	 *
	 * @return array
	 */
	private static function period_schema() {
		return array(
			'period'    => array(
				'type'        => 'string',
				'enum'        => array( 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last_7_days', 'last_30_days', 'this_year', 'last_year', 'custom' ),
				'default'     => 'last_30_days',
				'description' => 'Reporting period in the store timezone. Weeks start on the store\'s first day of the week. Use custom with date_from and date_to.',
			),
			'date_from' => WCMCP_Format::date_schema( 'Start date for a custom period (inclusive).' ),
			'date_to'   => WCMCP_Format::date_schema( 'End date for a custom period (inclusive).' ),
		);
	}

	/**
	 * Resolves the period into timestamps.
	 *
	 * @param array $input Input.
	 * @return array{from:int, to:int, label:string}|WP_Error
	 */
	public static function resolve_period( array $input ) {
		$period = isset( $input['period'] ) ? (string) $input['period'] : 'last_30_days';
		$tz     = wp_timezone();
		$today  = new DateTimeImmutable( 'today', $tz );

		$start_of_week = (int) get_option( 'start_of_week', 1 );
		$week_start    = $today->modify( '-' . ( ( (int) $today->format( 'w' ) - $start_of_week + 7 ) % 7 ) . ' days' );

		switch ( $period ) {
			case 'today':
				$from = $today;
				$to   = $today;
				break;
			case 'yesterday':
				$from = $today->modify( '-1 day' );
				$to   = $from;
				break;
			case 'this_week':
				$from = $week_start;
				$to   = $today;
				break;
			case 'last_week':
				$from = $week_start->modify( '-7 days' );
				$to   = $week_start->modify( '-1 day' );
				break;
			case 'this_month':
				$from = $today->modify( 'first day of this month' );
				$to   = $today;
				break;
			case 'last_month':
				$from = $today->modify( 'first day of last month' );
				$to   = $today->modify( 'last day of last month' );
				break;
			case 'last_7_days':
				$from = $today->modify( '-6 days' );
				$to   = $today;
				break;
			case 'this_year':
				$from = $today->setDate( (int) $today->format( 'Y' ), 1, 1 );
				$to   = $today;
				break;
			case 'last_year':
				$year = (int) $today->format( 'Y' ) - 1;
				$from = $today->setDate( $year, 1, 1 );
				$to   = $today->setDate( $year, 12, 31 );
				break;
			case 'custom':
				$from_ts = WCMCP_Format::parse_date( isset( $input['date_from'] ) ? $input['date_from'] : null );
				$to_ts   = WCMCP_Format::parse_date( isset( $input['date_to'] ) ? $input['date_to'] : null, true );
				if ( null === $from_ts || null === $to_ts ) {
					return new WP_Error( 'wcmcp_invalid_date', 'A custom period needs date_from and date_to as real dates in YYYY-MM-DD format.', array( 'status' => 400 ) );
				}
				if ( $from_ts > $to_ts ) {
					return new WP_Error( 'wcmcp_invalid_date', 'date_from is after date_to.', array( 'status' => 400 ) );
				}
				return array(
					'from'  => $from_ts,
					'to'    => $to_ts,
					'label' => 'custom',
				);
			case 'last_30_days':
			default:
				$period = 'last_30_days';
				$from   = $today->modify( '-29 days' );
				$to     = $today;
		}

		return array(
			'from'  => $from->getTimestamp(),
			'to'    => $to->setTime( 23, 59, 59 )->getTimestamp(),
			'label' => $period,
		);
	}

	/**
	 * Runs a callback for each paid order in the period, in batches.
	 *
	 * @param array    $period   Resolved period.
	 * @param callable $callback Receives a WC_Order.
	 * @return array{orders:int, capped:bool}
	 */
	private static function each_paid_order( array $period, callable $callback ) {
		$count = 0;
		$page  = 1;

		do {
			$orders = wc_get_orders(
				array(
					'type'         => 'shop_order',
					'status'       => wc_get_is_paid_statuses(),
					'date_created' => $period['from'] . '...' . $period['to'],
					'limit'        => self::BATCH,
					'page'         => $page,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);
			foreach ( $orders as $order ) {
				if ( $count >= self::MAX_ORDERS ) {
					return array(
						'orders' => $count,
						'capped' => true,
					);
				}
				if ( $order instanceof WC_Order ) {
					$callback( $order );
					++$count;
				}
			}
			++$page;
		} while ( count( $orders ) === self::BATCH );

		return array(
			'orders' => $count,
			'capped' => false,
		);
	}

	/**
	 * Period block included in every sales report.
	 *
	 * @param array $period Resolved period.
	 * @param array $scan   Result of each_paid_order().
	 * @return array
	 */
	private static function period_meta( array $period, array $scan ) {
		$tz = wp_timezone();
		return array(
			'period'        => $period['label'],
			'from'          => wp_date( 'Y-m-d', $period['from'], $tz ),
			'to'            => wp_date( 'Y-m-d', $period['to'], $tz ),
			'currency'      => get_woocommerce_currency(),
			'statuses'      => wc_get_is_paid_statuses(),
			'orders_capped' => $scan['capped'],
			'note'          => $scan['capped'] ? 'Only the first ' . self::MAX_ORDERS . ' orders in the period were counted. Use a shorter period for exact figures.' : null,
		);
	}

	/**
	 * Executes wcmcp/reports-sales.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function sales( array $input ) {
		$period = self::resolve_period( $input );
		if ( is_wp_error( $period ) ) {
			return $period;
		}
		$group_by = isset( $input['group_by'] ) && in_array( $input['group_by'], array( 'day', 'week', 'month' ), true ) ? $input['group_by'] : 'none';

		$empty   = array(
			'orders'     => 0,
			'items_sold' => 0,
			'gross'      => 0.0,
			'refunds'    => 0.0,
			'tax'        => 0.0,
			'shipping'   => 0.0,
			'discounts'  => 0.0,
		);
		$totals  = $empty;
		$buckets = array();

		$scan = self::each_paid_order(
			$period,
			static function ( WC_Order $order ) use ( &$totals, &$buckets, $group_by, $empty ) {
				$row = array(
					'orders'     => 1,
					'items_sold' => $order->get_item_count(),
					'gross'      => (float) $order->get_total(),
					'refunds'    => (float) $order->get_total_refunded(),
					'tax'        => (float) $order->get_total_tax(),
					'shipping'   => (float) $order->get_shipping_total(),
					'discounts'  => (float) $order->get_total_discount(),
				);
				foreach ( $row as $key => $value ) {
					$totals[ $key ] += $value;
				}
				if ( 'none' !== $group_by ) {
					$date   = $order->get_date_created();
					$format = array(
						'day'   => 'Y-m-d',
						'week'  => 'o-\WW',
						'month' => 'Y-m',
					);
					$key    = $date ? $date->date( $format[ $group_by ] ) : 'unknown';
					if ( ! isset( $buckets[ $key ] ) ) {
						$buckets[ $key ] = $empty;
					}
					foreach ( $row as $metric => $value ) {
						$buckets[ $key ][ $metric ] += $value;
					}
				}
			}
		);

		$report           = self::period_meta( $period, $scan );
		$report['totals'] = self::sales_row( $totals );
		if ( 'none' !== $group_by ) {
			ksort( $buckets );
			$report['group_by'] = $group_by;
			$report['breakdown'] = array();
			foreach ( $buckets as $key => $bucket ) {
				$report['breakdown'][] = array( 'period' => (string) $key ) + self::sales_row( $bucket );
			}
		}
		$report['definitions'] = array(
			'gross'     => 'Order totals including tax and shipping.',
			'net_sales' => 'Gross minus refunds, tax and shipping.',
			'refunds'   => 'Refunded on orders created in the period, whenever the refund happened.',
		);

		return $report;
	}

	/**
	 * Formats one row of sales figures.
	 *
	 * @param array $row Raw sums.
	 * @return array
	 */
	private static function sales_row( array $row ) {
		$net = $row['gross'] - $row['refunds'] - $row['tax'] - $row['shipping'];
		return array(
			'orders'              => (int) $row['orders'],
			'items_sold'          => (int) $row['items_sold'],
			'gross'               => WCMCP_Format::money( $row['gross'] ),
			'refunds'             => WCMCP_Format::money( $row['refunds'] ),
			'tax'                 => WCMCP_Format::money( $row['tax'] ),
			'shipping'            => WCMCP_Format::money( $row['shipping'] ),
			'discounts'           => WCMCP_Format::money( $row['discounts'] ),
			'net_sales'           => WCMCP_Format::money( $net ),
			'average_order_value' => WCMCP_Format::money( $row['orders'] > 0 ? $row['gross'] / $row['orders'] : 0 ),
		);
	}

	/**
	 * Executes wcmcp/reports-top-products.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function top_products( array $input ) {
		$period = self::resolve_period( $input );
		if ( is_wp_error( $period ) ) {
			return $period;
		}
		$rank_by = isset( $input['rank_by'] ) && 'quantity' === $input['rank_by'] ? 'quantity' : 'revenue';
		$split   = ! empty( $input['split_variations'] );
		$limit   = self::limit( $input, 10 );

		$products = array();
		$scan     = self::each_paid_order(
			$period,
			static function ( WC_Order $order ) use ( &$products, $split ) {
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					/** @var WC_Order_Item_Product $item */
					$id = $split && $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
					if ( ! $id ) {
						continue;
					}
					if ( ! isset( $products[ $id ] ) ) {
						$products[ $id ] = array(
							'quantity' => 0,
							'revenue'  => 0.0,
							'orders'   => 0,
							'name'     => $item->get_name(),
						);
					}
					$products[ $id ]['quantity'] += $item->get_quantity() - absint( $order->get_qty_refunded_for_item( $item->get_id() ) );
					$products[ $id ]['revenue']  += (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item->get_id() );
					++$products[ $id ]['orders'];
				}
			}
		);

		uasort(
			$products,
			static function ( $a, $b ) use ( $rank_by ) {
				return $b[ $rank_by ] <=> $a[ $rank_by ];
			}
		);

		$rows = array();
		foreach ( array_slice( $products, 0, $limit, true ) as $id => $row ) {
			$product = wc_get_product( $id );
			$rows[]  = array(
				'product_id' => (int) $id,
				'name'       => WCMCP_Format::text( $product ? $product->get_name() : $row['name'], 200 ),
				'sku'        => $product ? (string) $product->get_sku() : '',
				'quantity'   => (int) $row['quantity'],
				'revenue'    => WCMCP_Format::money( $row['revenue'] ),
				'orders'     => (int) $row['orders'],
			);
		}

		$report             = self::period_meta( $period, $scan );
		$report['rank_by']  = $rank_by;
		$report['products'] = $rows;
		return $report;
	}

	/**
	 * Executes wcmcp/reports-top-customers.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function top_customers( array $input ) {
		$period = self::resolve_period( $input );
		if ( is_wp_error( $period ) ) {
			return $period;
		}
		$limit = self::limit( $input, 10 );

		$customers = array();
		$scan      = self::each_paid_order(
			$period,
			static function ( WC_Order $order ) use ( &$customers ) {
				$email = strtolower( sanitize_email( $order->get_billing_email() ) );
				$key   = $order->get_customer_id() ? 'user:' . $order->get_customer_id() : 'guest:' . $email;
				if ( ! isset( $customers[ $key ] ) ) {
					$customers[ $key ] = array(
						'customer_id' => $order->get_customer_id() ? $order->get_customer_id() : null,
						'guest'       => ! $order->get_customer_id(),
						'name'        => WCMCP_Format::billing_name( $order ),
						'email'       => $email,
						'orders'      => 0,
						'spent'       => 0.0,
					);
				}
				++$customers[ $key ]['orders'];
				$customers[ $key ]['spent'] += (float) $order->get_total() - (float) $order->get_total_refunded();
			}
		);

		uasort(
			$customers,
			static function ( $a, $b ) {
				return $b['spent'] <=> $a['spent'];
			}
		);

		$rows = array();
		foreach ( array_slice( $customers, 0, $limit ) as $row ) {
			$row['spent'] = WCMCP_Format::money( $row['spent'] );
			$rows[]       = $row;
		}

		$report              = self::period_meta( $period, $scan );
		$report['customers'] = $rows;
		return $report;
	}

	/**
	 * Executes wcmcp/reports-low-stock.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function low_stock( array $input ) {
		$threshold = isset( $input['threshold'] ) ? (int) $input['threshold'] : (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$include   = ! isset( $input['include_out_of_stock'] ) || (bool) $input['include_out_of_stock'];
		$limit     = self::limit( $input, 25 );

		$meta_query = array(
			array(
				'key'     => '_stock',
				'value'   => $threshold,
				'compare' => '<=',
				'type'    => 'NUMERIC',
			),
		);
		if ( ! $include ) {
			$meta_query[] = array(
				'key'     => '_stock',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			);
		}

		$meta_query[] = array(
			'key'   => '_manage_stock',
			'value' => 'yes',
		);

		// wc_get_products() drops custom meta queries, so find IDs with WP_Query (products are posts
		// in every WooCommerce storage mode; only orders move to HPOS) and load them through CRUD.
		$query = new WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_key'       => '_stock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'orderby'        => array(
					'meta_value_num' => 'ASC',
					'ID'             => 'ASC',
				),
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
			)
		);

		$rows = array();
		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$rows[] = array(
				'product_id'     => $product->get_id(),
				'parent_id'      => $product->get_parent_id(),
				'name'           => WCMCP_Format::text( $product->get_name(), 200 ),
				'sku'            => (string) $product->get_sku(),
				'stock_quantity' => (int) $product->get_stock_quantity(),
				'stock_status'   => $product->get_stock_status(),
				'backorders'     => $product->get_backorders(),
			);
		}

		return array(
			'threshold'            => $threshold,
			'include_out_of_stock' => $include,
			'total'                => (int) $query->found_posts,
			'showing'              => count( $rows ),
			'products'             => $rows,
		);
	}

	/**
	 * Clamped result limit.
	 *
	 * @param array $input   Input.
	 * @param int   $default Default.
	 * @return int
	 */
	private static function limit( array $input, $default ) {
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : $default;
		return min( self::MAX_LIMIT, max( 1, $limit ) );
	}
}
