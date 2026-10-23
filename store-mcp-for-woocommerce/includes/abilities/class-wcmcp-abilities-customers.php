<?php
/**
 * Customers pack: read tools for registered customers.
 *
 * Only users with a customer role are returned, so staff and admin accounts are never exposed.
 * Guest buyers have no account; find them through orders-list.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer abilities.
 */
final class WCMCP_Abilities_Customers {

	const PACK = 'customers';

	const CAPABILITY = 'list_users';

	const SEARCH_LIMIT = 500;

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		return array(
			'wcmcp/customers-list' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'List customers', 'store-mcp-for-woocommerce' ),
				'description'   => 'List or search registered customers by name, email, username, company or phone. Guest buyers have no account; find them with orders-list. Paginated.' . WCMCP_Format::UNTRUSTED_NOTE,
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'search'  => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'Search term.',
							),
							'orderby' => array(
								'type'    => 'string',
								'enum'    => array( 'registered', 'name', 'email' ),
								'default' => 'registered',
							),
							'order'   => array(
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
				'execute'       => array( __CLASS__, 'list_customers' ),
			),
			'wcmcp/customers-get'  => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Get customer', 'store-mcp-for-woocommerce' ),
				'description'   => 'Details of one registered customer: contact details, billing and shipping addresses, order count, total spent and their latest order.' . WCMCP_Format::UNTRUSTED_NOTE,
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'customer_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Customer user ID.',
						),
					),
					'required'             => array( 'customer_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'get_customer' ),
			),
			'wcmcp/customers-update' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'idempotent'    => true,
				'capability'    => 'edit_users',
				'label'         => __( 'Update customer', 'store-mcp-for-woocommerce' ),
				'description'   => 'Update a registered customer\'s name, account email, and billing or shipping address. Only the fields you pass change; address fields are merged. Passwords, usernames and roles cannot be changed. Returns exactly what changed. Confirm with the store owner before changing an account email, because the customer signs in with it.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'customer_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'first_name'  => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'last_name'   => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'email'       => array(
							'type'        => 'string',
							'maxLength'   => 100,
							'description' => 'Account email. Must not belong to another user.',
						),
						'billing'     => self::address_schema( 'billing' ),
						'shipping'    => self::address_schema( 'shipping' ),
					),
					'required'             => array( 'customer_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'update_customer' ),
			),
		);
	}

	/**
	 * Address fields that can be updated.
	 *
	 * @param string $type billing or shipping.
	 * @return string[]
	 */
	private static function address_fields( $type ) {
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' );
		if ( 'billing' === $type ) {
			$fields[] = 'email';
		}
		return $fields;
	}

	/**
	 * Schema for a partial address.
	 *
	 * @param string $type billing or shipping.
	 * @return array
	 */
	private static function address_schema( $type ) {
		$properties = array();
		foreach ( self::address_fields( $type ) as $field ) {
			$properties[ $field ] = array(
				'type'      => 'string',
				'maxLength' => 200,
			);
		}
		$properties['country']['description'] = 'Two-letter country code, e.g. US, GB, PT.';

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Executes wcmcp/customers-update.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_customer( array $input ) {
		$customer_id = isset( $input['customer_id'] ) ? absint( $input['customer_id'] ) : 0;
		$customer    = self::find( $customer_id );
		if ( ! $customer ) {
			return WCMCP_Format::not_found( 'Customer', $customer_id );
		}
		if ( ! current_user_can( 'edit_user', $customer_id ) ) {
			return WCMCP_Write::error( 'wcmcp_cannot_edit_user', 'The key owner is not allowed to edit this customer.', 403 );
		}
		$check = WCMCP_Write::require_changes( $input, array( 'customer_id' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$tracked = array(
			'first_name' => 'get_first_name',
			'last_name'  => 'get_last_name',
			'email'      => 'get_email',
		);
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( self::address_fields( $type ) as $field ) {
				$tracked[ "{$type}.{$field}" ] = "get_{$type}_{$field}";
			}
		}
		$before = WCMCP_Write::snapshot( $customer, $tracked );

		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$setter = "set_{$field}";
				$customer->$setter( sanitize_text_field( $input[ $field ] ) );
			}
		}

		if ( isset( $input['email'] ) ) {
			$email = sanitize_email( $input['email'] );
			if ( ! is_email( $email ) ) {
				return WCMCP_Write::error( 'wcmcp_invalid_email', 'That is not a valid email address.' );
			}
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $customer_id ) {
				return WCMCP_Write::error( 'wcmcp_email_taken', 'Another account already uses that email address.' );
			}
			$customer->set_email( $email );
		}

		$countries = WC()->countries->get_countries();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( empty( $input[ $type ] ) || ! is_array( $input[ $type ] ) ) {
				continue;
			}
			foreach ( $input[ $type ] as $field => $value ) {
				if ( ! in_array( $field, self::address_fields( $type ), true ) ) {
					continue;
				}
				$value = sanitize_text_field( (string) $value );
				if ( 'country' === $field && '' !== $value ) {
					$value = strtoupper( $value );
					if ( ! isset( $countries[ $value ] ) ) {
						return WCMCP_Write::error( 'wcmcp_invalid_country', sprintf( '"%s" is not a country code WooCommerce knows. Use two letters, e.g. US.', $value ) );
					}
				}
				if ( 'email' === $field && '' !== $value ) {
					$value = sanitize_email( $value );
					if ( ! is_email( $value ) ) {
						return WCMCP_Write::error( 'wcmcp_invalid_email', 'The billing email is not a valid email address.' );
					}
				}
				$setter = "set_{$type}_{$field}";
				$customer->$setter( $value );
			}
		}

		$customer->save();
		$customer = new WC_Customer( $customer_id );

		return array(
			'customer' => self::summary( $customer ),
			'changes'  => WCMCP_Write::diff( $before, WCMCP_Write::snapshot( $customer, $tracked ) ),
		);
	}

	/**
	 * Roles treated as customers.
	 *
	 * @return string[]
	 */
	public static function roles() {
		/**
		 * Filters which user roles the customer tools can see.
		 *
		 * @param string[] $roles Default: customer.
		 */
		return array_values( array_filter( (array) apply_filters( 'wcmcp_customer_roles', array( 'customer' ) ), 'is_string' ) );
	}

	/**
	 * Executes wcmcp/customers-list.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_customers( array $input ) {
		list( $page, $per_page ) = WCMCP_Format::pagination( $input );

		$orderby_map = array(
			'registered' => 'registered',
			'name'       => 'display_name',
			'email'      => 'email',
		);
		$orderby     = isset( $input['orderby'], $orderby_map[ $input['orderby'] ] ) ? $orderby_map[ $input['orderby'] ] : 'registered';
		$order       = isset( $input['order'] ) && 'asc' === $input['order'] ? 'ASC' : 'DESC';

		$args = array(
			'role__in'    => self::roles(),
			'orderby'     => $orderby,
			'order'       => $order,
			'number'      => $per_page,
			'paged'       => $page,
			'count_total' => true,
			'fields'      => 'ID',
		);

		$search = isset( $input['search'] ) ? trim( sanitize_text_field( $input['search'] ) ) : '';
		if ( '' !== $search ) {
			$ids = self::search_ids( $search );
			if ( empty( $ids ) ) {
				return WCMCP_Format::paged( array(), 0, $page, $per_page );
			}
			$args['include'] = $ids;
		}

		$query = new WP_User_Query( $args );
		$items = array();
		foreach ( $query->get_results() as $user_id ) {
			$customer = new WC_Customer( (int) $user_id );
			$items[]  = self::summary( $customer );
		}

		return WCMCP_Format::paged( $items, $query->get_total(), $page, $per_page );
	}

	/**
	 * Executes wcmcp/customers-get.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_customer( array $input ) {
		$customer_id = isset( $input['customer_id'] ) ? absint( $input['customer_id'] ) : 0;
		$customer    = self::find( $customer_id );
		if ( ! $customer ) {
			return WCMCP_Format::not_found( 'Customer', $customer_id );
		}

		$data = self::summary( $customer );

		$data['username']       = sanitize_user( $customer->get_username() );
		$data['billing']        = WCMCP_Format::address( $customer, 'billing' );
		$data['shipping']       = WCMCP_Format::address( $customer, 'shipping' );
		$data['is_paying']      = (bool) $customer->get_is_paying_customer();
		$data['date_modified']  = WCMCP_Format::date( $customer->get_date_modified() );

		$last_order         = $customer->get_last_order();
		$data['last_order'] = $last_order instanceof WC_Order ? WCMCP_Abilities_Orders::summary( $last_order ) : null;

		return $data;
	}

	/**
	 * Loads a customer if the user exists and has a customer role.
	 *
	 * @param int $customer_id User ID.
	 * @return WC_Customer|null
	 */
	public static function find( $customer_id ) {
		$user = $customer_id ? get_userdata( $customer_id ) : false;
		if ( ! $user || ! array_intersect( (array) $user->roles, self::roles() ) ) {
			return null;
		}
		try {
			return new WC_Customer( $customer_id );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Compact customer summary.
	 *
	 * @param WC_Customer $customer Customer.
	 * @return array
	 */
	public static function summary( WC_Customer $customer ) {
		$name = trim( $customer->get_first_name() . ' ' . $customer->get_last_name() );
		if ( '' === $name ) {
			$name = WCMCP_Format::billing_name( $customer );
		}
		if ( '' === $name ) {
			$name = (string) $customer->get_display_name();
		}

		return array(
			'customer_id'     => $customer->get_id(),
			'name'            => WCMCP_Format::text( $name, 200 ),
			'email'           => sanitize_email( $customer->get_email() ),
			'company'         => WCMCP_Format::text( $customer->get_billing_company(), 200 ),
			'city'            => WCMCP_Format::text( $customer->get_billing_city(), 100 ),
			'country'         => (string) $customer->get_billing_country(),
			'date_registered' => WCMCP_Format::date( $customer->get_date_created() ),
			'order_count'     => (int) $customer->get_order_count(),
			'total_spent'     => WCMCP_Format::money( $customer->get_total_spent() ),
		);
	}

	/**
	 * Customer IDs matching a term in core user fields or name/company/phone meta.
	 *
	 * @param string $term Search term.
	 * @return int[]
	 */
	private static function search_ids( $term ) {
		$base = array(
			'role__in' => self::roles(),
			'fields'   => 'ID',
			'number'   => self::SEARCH_LIMIT,
		);

		$by_user = new WP_User_Query(
			$base + array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
			)
		);

		$meta_query = array( 'relation' => 'OR' );
		foreach ( array( 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'billing_company', 'billing_phone', 'billing_email' ) as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'value'   => $term,
				'compare' => 'LIKE',
			);
		}
		$by_meta = new WP_User_Query( $base + array( 'meta_query' => $meta_query ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query

		return array_values( array_unique( array_map( 'absint', array_merge( $by_user->get_results(), $by_meta->get_results() ) ) ) );
	}
}
