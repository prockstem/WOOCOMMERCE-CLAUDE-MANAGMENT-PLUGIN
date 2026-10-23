<?php
/**
 * Coupons pack: list, get, create and update coupons with the WC_Coupon CRUD class.
 *
 * Coupons are posts in every WooCommerce storage mode, so they are found with WP_Query and
 * then loaded and saved through WC_Coupon. Deleting coupons is a destructive tool (Phase 4).
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coupon abilities.
 */
final class WCMCP_Abilities_Coupons {

	const PACK = 'coupons';

	const CAPABILITY = 'edit_shop_coupons';

	/**
	 * Fields tracked in change lists.
	 *
	 * @var array<string,string>
	 */
	private static $tracked = array(
		'code'                   => 'get_code',
		'status'                 => 'get_status',
		'discount_type'          => 'get_discount_type',
		'amount'                 => 'get_amount',
		'description'            => 'get_description',
		'date_expires'           => 'get_date_expires',
		'usage_limit'            => 'get_usage_limit',
		'usage_limit_per_user'   => 'get_usage_limit_per_user',
		'limit_usage_to_x_items' => 'get_limit_usage_to_x_items',
		'individual_use'         => 'get_individual_use',
		'free_shipping'          => 'get_free_shipping',
		'exclude_sale_items'     => 'get_exclude_sale_items',
		'minimum_amount'         => 'get_minimum_amount',
		'maximum_amount'         => 'get_maximum_amount',
		'product_ids'            => 'get_product_ids',
		'excluded_product_ids'   => 'get_excluded_product_ids',
		'email_restrictions'     => 'get_email_restrictions',
	);

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		$fields = self::fields_schema();

		return array(
			'wcmcp/coupons-list'   => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'List coupons', 'store-mcp-for-woocommerce' ),
				'description'   => 'List or search coupons by code, newest first. Shows type, amount, expiry and usage. Paginated.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'search' => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'Part of a coupon code.',
							),
							'status' => array(
								'type'    => 'string',
								'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private' ),
								'default' => 'any',
							),
						),
						WCMCP_Format::pagination_schema()
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => WCMCP_Format::paged_output_schema(),
				'execute'       => array( __CLASS__, 'list_coupons' ),
			),
			'wcmcp/coupons-get'    => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Get coupon', 'store-mcp-for-woocommerce' ),
				'description'   => 'All settings and usage of one coupon, by ID or code.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'coupon_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'code'      => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'get_coupon' ),
			),
			'wcmcp/coupons-create' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Create coupon', 'store-mcp-for-woocommerce' ),
				'description'   => 'Create a coupon. It is live as soon as it is created unless status is draft, so confirm the code, discount and limits with the store owner first. Percentage discounts cannot exceed 100.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => $fields,
					'required'             => array( 'code', 'discount_type', 'amount' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'create_coupon' ),
			),
			'wcmcp/coupons-update' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'idempotent'    => true,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Update coupon', 'store-mcp-for-woocommerce' ),
				'description'   => 'Change a coupon\'s code, discount, expiry, limits, restrictions or status. Only the fields you pass change. Set status to draft to switch a coupon off without deleting it. Returns exactly what changed.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'coupon_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						$fields
					),
					'required'             => array( 'coupon_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'update_coupon' ),
			),
			'wcmcp/coupons-delete' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_DESTRUCTIVE,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Delete coupon', 'store-mcp-for-woocommerce' ),
				'description'   => 'Delete a coupon so it can no longer be used. By default it goes to the trash, where it can be restored from WordPress; permanently: true deletes it for good. To pause a coupon instead, use coupons-update with status draft.' . WCMCP_Confirm::instructions(),
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'coupon_id'   => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'permanently' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => 'false = move to trash (restorable), true = delete for good.',
							),
						),
						WCMCP_Confirm::schema()
					),
					'required'             => array( 'coupon_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'preview'       => array( __CLASS__, 'preview_delete' ),
				'execute'       => array( __CLASS__, 'delete_coupon' ),
			),
		);
	}

	/**
	 * Step 1 of wcmcp/coupons-delete: validates and describes. Changes nothing.
	 *
	 * @param array $input Input without confirmation fields.
	 * @return array|WP_Error
	 */
	public static function preview_delete( array $input ) {
		$coupon = self::find_from_input( array( 'coupon_id' => isset( $input['coupon_id'] ) ? $input['coupon_id'] : 0 ) );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		if ( ! current_user_can( 'delete_post', $coupon->get_id() ) ) {
			return WCMCP_Write::error( 'wcmcp_cannot_delete', 'The key owner is not allowed to delete this coupon.', 403 );
		}

		$permanently = ! empty( $input['permanently'] );
		return array(
			'coupon'      => self::details( $coupon ),
			'permanently' => $permanently,
			'effect'      => $permanently
				? 'The coupon will be deleted for good and cannot be restored.'
				: 'The coupon will move to the trash and stop working. It can be restored from Marketing → Coupons → Trash.',
		);
	}

	/**
	 * Step 2 of wcmcp/coupons-delete.
	 *
	 * @param array $input Input without confirmation fields.
	 * @return array|WP_Error
	 */
	public static function delete_coupon( array $input ) {
		$preview = self::preview_delete( $input );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$coupon = new WC_Coupon( absint( $input['coupon_id'] ) );
		if ( ! $coupon->delete( $preview['permanently'] ) ) {
			return WCMCP_Write::error( 'wcmcp_delete_failed', 'The coupon could not be deleted.', 500 );
		}

		return array(
			'done'        => true,
			'coupon_id'   => $preview['coupon']['coupon_id'],
			'code'        => $preview['coupon']['code'],
			'permanently' => $preview['permanently'],
			'result'      => $preview['permanently'] ? 'Deleted permanently.' : 'Moved to the trash.',
		);
	}

	/**
	 * Settable coupon fields.
	 *
	 * @return array
	 */
	private static function fields_schema() {
		$ids = array(
			'type'  => 'array',
			'items' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		return array(
			'code'                   => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 100,
			),
			'status'                 => array(
				'type'        => 'string',
				'enum'        => array( 'publish', 'draft' ),
				'description' => 'publish = usable at checkout, draft = switched off.',
			),
			'discount_type'          => array(
				'type'        => 'string',
				'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
				'description' => 'percent of the cart, fixed amount off the cart, or fixed amount off each eligible product.',
			),
			'amount'                 => WCMCP_Write::price_schema( 'Discount amount (a percentage for percent coupons).' ),
			'description'            => array(
				'type'      => 'string',
				'maxLength' => 1000,
			),
			'date_expires'           => WCMCP_Write::clearable_date_schema( 'Last day the coupon works.' ),
			'usage_limit'            => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Total uses allowed. 0 = unlimited.',
			),
			'usage_limit_per_user'   => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Uses per customer. 0 = unlimited.',
			),
			'limit_usage_to_x_items' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => 'Max items the discount applies to. 0 = all.',
			),
			'individual_use'         => array(
				'type'        => 'boolean',
				'description' => 'Cannot be combined with other coupons.',
			),
			'free_shipping'          => array( 'type' => 'boolean' ),
			'exclude_sale_items'     => array( 'type' => 'boolean' ),
			'minimum_amount'         => WCMCP_Write::price_schema( 'Minimum cart subtotal.' ),
			'maximum_amount'         => WCMCP_Write::price_schema( 'Maximum cart subtotal.' ),
			'product_ids'            => $ids + array( 'description' => 'Only these products qualify. Empty = all.' ),
			'excluded_product_ids'   => $ids,
			'email_restrictions'     => array(
				'type'        => 'array',
				'items'       => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'description' => 'Only these billing emails may use it. Wildcards like *@example.com are allowed.',
			),
		);
	}

	/**
	 * Executes wcmcp/coupons-list.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_coupons( array $input ) {
		list( $page, $per_page ) = WCMCP_Format::pagination( $input );

		$status = isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $input['status'] : array( 'publish', 'draft', 'pending', 'private', 'future' );
		$args   = array(
			'post_type'      => 'shop_coupon',
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		$search = isset( $input['search'] ) ? trim( sanitize_text_field( $input['search'] ) ) : '';
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $coupon_id ) {
			$items[] = self::summary( new WC_Coupon( $coupon_id ) );
		}

		return WCMCP_Format::paged( $items, $query->found_posts, $page, $per_page );
	}

	/**
	 * Executes wcmcp/coupons-get.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_coupon( array $input ) {
		$coupon = self::find_from_input( $input );
		return is_wp_error( $coupon ) ? $coupon : self::details( $coupon );
	}

	/**
	 * Executes wcmcp/coupons-create.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_coupon( array $input ) {
		$coupon  = new WC_Coupon();
		$applied = self::apply_fields( $coupon, $input );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$coupon->save();
		self::save_status( $coupon, isset( $input['status'] ) ? $input['status'] : 'publish' );

		return array(
			'created' => true,
			'coupon'  => self::details( new WC_Coupon( $coupon->get_id() ) ),
		);
	}

	/**
	 * Executes wcmcp/coupons-update.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_coupon( array $input ) {
		$coupon = self::find_from_input( array( 'coupon_id' => isset( $input['coupon_id'] ) ? $input['coupon_id'] : 0 ) );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}
		$check = WCMCP_Write::require_changes( $input, array( 'coupon_id' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$before  = self::snapshot( $coupon );
		$applied = self::apply_fields( $coupon, $input );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$coupon->save();
		if ( isset( $input['status'] ) ) {
			self::save_status( $coupon, $input['status'] );
		}

		$coupon = new WC_Coupon( $coupon->get_id() );
		return array(
			'coupon'  => self::details( $coupon ),
			'changes' => WCMCP_Write::diff( $before, self::snapshot( $coupon ) ),
		);
	}

	/**
	 * Tracked values, with status read from the post (WC_Coupon has no status getter in every version).
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array
	 */
	private static function snapshot( WC_Coupon $coupon ) {
		$values           = WCMCP_Write::snapshot( $coupon, self::$tracked );
		$values['status'] = (string) get_post_status( $coupon->get_id() );
		return $values;
	}

	/**
	 * Validates and sets fields (not yet saved).
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @param array     $input  Input.
	 * @return true|WP_Error
	 */
	private static function apply_fields( WC_Coupon $coupon, array $input ) {
		if ( isset( $input['code'] ) ) {
			$code = wc_format_coupon_code( sanitize_text_field( $input['code'] ) );
			if ( '' === $code ) {
				return WCMCP_Write::error( 'wcmcp_empty_code', 'The coupon code cannot be empty.' );
			}
			if ( wc_get_coupon_id_by_code( $code, $coupon->get_id() ) ) {
				return WCMCP_Write::error( 'wcmcp_code_taken', sprintf( 'A coupon with the code "%s" already exists.', $code ) );
			}
			$coupon->set_code( $code );
		}

		if ( isset( $input['discount_type'] ) ) {
			if ( ! array_key_exists( $input['discount_type'], wc_get_coupon_types() ) ) {
				return WCMCP_Write::error( 'wcmcp_invalid_discount_type', 'Discount type must be percent, fixed_cart or fixed_product.' );
			}
			$coupon->set_discount_type( $input['discount_type'] );
		}

		if ( array_key_exists( 'amount', $input ) ) {
			$amount = WCMCP_Write::price( $input['amount'], 'amount' );
			if ( is_wp_error( $amount ) ) {
				return $amount;
			}
			$coupon->set_amount( '' === $amount ? 0 : $amount );
		}
		if ( 'percent' === $coupon->get_discount_type( 'edit' ) && (float) $coupon->get_amount( 'edit' ) > 100 ) {
			return WCMCP_Write::error( 'wcmcp_percent_over_100', 'A percentage discount cannot be more than 100.' );
		}

		if ( isset( $input['description'] ) ) {
			$coupon->set_description( sanitize_textarea_field( $input['description'] ) );
		}

		if ( array_key_exists( 'date_expires', $input ) ) {
			$date = WCMCP_Write::date( $input['date_expires'], 'date_expires', true );
			if ( is_wp_error( $date ) ) {
				return $date;
			}
			$coupon->set_date_expires( '' === $date ? null : $date );
		}

		foreach ( array( 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$setter = "set_{$field}";
				$coupon->$setter( absint( $input[ $field ] ) );
			}
		}

		foreach ( array( 'individual_use', 'free_shipping', 'exclude_sale_items' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$setter = "set_{$field}";
				$coupon->$setter( (bool) $input[ $field ] );
			}
		}

		foreach ( array( 'minimum_amount', 'maximum_amount' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$amount = WCMCP_Write::price( $input[ $field ], $field );
				if ( is_wp_error( $amount ) ) {
					return $amount;
				}
				$setter = "set_{$field}";
				$coupon->$setter( $amount );
			}
		}
		$min = $coupon->get_minimum_amount( 'edit' );
		$max = $coupon->get_maximum_amount( 'edit' );
		if ( '' !== $min && '' !== $max && (float) $max > 0 && (float) $min > (float) $max ) {
			return WCMCP_Write::error( 'wcmcp_invalid_spend_limits', 'The minimum spend is higher than the maximum spend.' );
		}

		foreach ( array( 'product_ids', 'excluded_product_ids' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$ids     = array_values( array_unique( array_map( 'absint', (array) $input[ $field ] ) ) );
				$missing = array_filter(
					$ids,
					static function ( $id ) {
						return ! wc_get_product( $id );
					}
				);
				if ( $missing ) {
					return WCMCP_Write::error( 'wcmcp_unknown_product', 'These product IDs do not exist: ' . implode( ', ', $missing ) . '.' );
				}
				$setter = "set_{$field}";
				$coupon->$setter( $ids );
			}
		}

		if ( isset( $input['email_restrictions'] ) ) {
			$emails = array();
			foreach ( (array) $input['email_restrictions'] as $email ) {
				$email = strtolower( trim( sanitize_text_field( $email ) ) );
				if ( '' === $email ) {
					continue;
				}
				// is_email() rejects wildcards, so check them with a placeholder local part.
				if ( ! is_email( str_replace( '*', 'x', $email ) ) ) {
					return WCMCP_Write::error( 'wcmcp_invalid_email', sprintf( '"%s" is not a valid email or wildcard pattern.', $email ) );
				}
				$emails[] = $email;
			}
			$coupon->set_email_restrictions( array_values( array_unique( $emails ) ) );
		}

		if ( isset( $input['status'] ) && 'publish' === $input['status'] && ! current_user_can( 'publish_shop_coupons' ) ) {
			return WCMCP_Write::error( 'wcmcp_cannot_publish', 'The key owner is not allowed to publish coupons.', 403 );
		}

		return true;
	}

	/**
	 * Sets the coupon's post status.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @param string    $status publish or draft.
	 * @return void
	 */
	private static function save_status( WC_Coupon $coupon, $status ) {
		$status = 'draft' === $status ? 'draft' : 'publish';
		if ( is_callable( array( $coupon, 'set_status' ) ) ) {
			$coupon->set_status( $status );
			$coupon->save();
			return;
		}
		wp_update_post(
			array(
				'ID'          => $coupon->get_id(),
				'post_status' => $status,
			)
		);
	}

	/**
	 * Loads a coupon from coupon_id or code.
	 *
	 * @param array $input Input.
	 * @return WC_Coupon|WP_Error
	 */
	private static function find_from_input( array $input ) {
		$coupon_id = isset( $input['coupon_id'] ) ? absint( $input['coupon_id'] ) : 0;
		if ( ! $coupon_id && ! empty( $input['code'] ) ) {
			$coupon_id = (int) wc_get_coupon_id_by_code( wc_format_coupon_code( sanitize_text_field( $input['code'] ) ) );
		}
		if ( ! $coupon_id && empty( $input['code'] ) ) {
			return WCMCP_Write::error( 'wcmcp_missing_coupon', 'Pass coupon_id or code.' );
		}
		if ( ! $coupon_id || 'shop_coupon' !== get_post_type( $coupon_id ) || 'trash' === get_post_status( $coupon_id ) ) {
			return WCMCP_Format::not_found( 'Coupon', $coupon_id );
		}
		return new WC_Coupon( $coupon_id );
	}

	/**
	 * Coupon summary.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array
	 */
	public static function summary( WC_Coupon $coupon ) {
		return array(
			'coupon_id'     => $coupon->get_id(),
			'code'          => $coupon->get_code(),
			'status'        => (string) get_post_status( $coupon->get_id() ),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => WCMCP_Format::money( $coupon->get_amount() ),
			'date_expires'  => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : null,
			'usage_count'   => (int) $coupon->get_usage_count(),
			'usage_limit'   => $coupon->get_usage_limit() ? (int) $coupon->get_usage_limit() : null,
		);
	}

	/**
	 * Full coupon details.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return array
	 */
	private static function details( WC_Coupon $coupon ) {
		$money = static function ( $value ) {
			return '' === $value || null === $value ? null : WCMCP_Format::money( $value );
		};

		return self::summary( $coupon ) + array(
			'description'            => WCMCP_Format::text( $coupon->get_description(), 1000 ),
			'usage_limit_per_user'   => $coupon->get_usage_limit_per_user() ? (int) $coupon->get_usage_limit_per_user() : null,
			'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items() ? (int) $coupon->get_limit_usage_to_x_items() : null,
			'individual_use'         => $coupon->get_individual_use(),
			'free_shipping'          => $coupon->get_free_shipping(),
			'exclude_sale_items'     => $coupon->get_exclude_sale_items(),
			'minimum_amount'         => $money( $coupon->get_minimum_amount() ),
			'maximum_amount'         => $money( $coupon->get_maximum_amount() ),
			'product_ids'            => array_map( 'absint', $coupon->get_product_ids() ),
			'excluded_product_ids'   => array_map( 'absint', $coupon->get_excluded_product_ids() ),
			'email_restrictions'     => array_values( $coupon->get_email_restrictions() ),
			'date_created'           => WCMCP_Format::date( $coupon->get_date_created() ),
		);
	}
}
