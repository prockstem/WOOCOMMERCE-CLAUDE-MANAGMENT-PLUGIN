<?php
/**
 * Products pack: create and update tools for products and variations.
 *
 * Uses WooCommerce product CRUD classes only. Every tool returns the resulting product summary and
 * a list of the fields that actually changed.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product and variation write abilities.
 */
final class WCMCP_Abilities_Products_Write {

	const PACK = 'products';

	const CAPABILITY = 'edit_products';

	const TEXT_LIMIT = 20000;

	/**
	 * Fields tracked in change lists.
	 *
	 * @var array<string,string>
	 */
	private static $tracked = array(
		'name'              => 'get_name',
		'status'            => 'get_status',
		'sku'               => 'get_sku',
		'regular_price'     => 'get_regular_price',
		'sale_price'        => 'get_sale_price',
		'sale_from'         => 'get_date_on_sale_from',
		'sale_to'           => 'get_date_on_sale_to',
		'description'       => 'get_description',
		'short_description' => 'get_short_description',
		'category_ids'      => 'get_category_ids',
		'manage_stock'      => 'get_manage_stock',
		'stock_quantity'    => 'get_stock_quantity',
		'stock_status'      => 'get_stock_status',
		'backorders'        => 'get_backorders',
		'weight'            => 'get_weight',
	);

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		$common = self::common_fields_schema();

		$product_fields = array_merge(
			array(
				'name'              => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'status'            => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
					'description' => 'publish makes the product visible in the shop.',
				),
				'description'       => array(
					'type'        => 'string',
					'maxLength'   => self::TEXT_LIMIT,
					'description' => 'Full description. Basic HTML allowed.',
				),
				'short_description' => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'categories'        => array(
					'type'        => 'array',
					'items'       => array( 'type' => array( 'integer', 'string' ) ),
					'description' => 'Category IDs, slugs or names. Replaces the current categories. Categories must already exist.',
				),
			),
			$common
		);

		$variation_fields = array_merge(
			array(
				'status'      => array(
					'type'        => 'string',
					'enum'        => array( 'publish', 'private' ),
					'description' => 'publish = enabled, private = disabled.',
				),
				'description' => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
			),
			$common
		);

		return array(
			'wcmcp/products-create'           => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Create product', 'store-mcp-for-woocommerce' ),
				'description'   => 'Create a simple or variable product. New products are drafts unless status is set to publish, so confirm with the store owner before publishing. For a variable product, define attributes here, then add each variation with product-variations-create.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'type'       => array(
								'type'    => 'string',
								'enum'    => array( 'simple', 'variable' ),
								'default' => 'simple',
							),
							'attributes' => array(
								'type'        => 'array',
								'description' => 'Variable products only: e.g. [{"name":"Size","options":["S","M","L"]}].',
								'items'       => array(
									'type'                 => 'object',
									'properties'           => array(
										'name'      => array(
											'type'      => 'string',
											'minLength' => 1,
											'maxLength' => 100,
										),
										'options'   => array(
											'type'     => 'array',
											'items'    => array(
												'type'      => 'string',
												'maxLength' => 100,
											),
											'minItems' => 1,
										),
										'visible'   => array(
											'type'    => 'boolean',
											'default' => true,
										),
										'variation' => array(
											'type'    => 'boolean',
											'default' => true,
										),
									),
									'required'             => array( 'name', 'options' ),
									'additionalProperties' => false,
								),
							),
						),
						$product_fields
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'create_product' ),
			),
			'wcmcp/products-update'           => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'idempotent'    => true,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Update product', 'store-mcp-for-woocommerce' ),
				'description'   => 'Update a product: name, status, SKU, prices, sale dates, descriptions, categories, stock and weight. Only the fields you pass change. Use stock_quantity to set stock or stock_change to add/remove units. For a variation, use product-variations-update. Returns exactly what changed. Before price or stock changes across several products, list them and ask the store owner to confirm.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'product_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						$product_fields
					),
					'required'             => array( 'product_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'update_product' ),
			),
			'wcmcp/product-variations-create' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Create product variation', 'store-mcp-for-woocommerce' ),
				'description'   => 'Add a variation to a variable product, e.g. attributes {"Size":"XL"}. Each attribute must be one the parent uses for variations, and the value must be one of its options. Attributes left out mean "any".',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'product_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Parent variable product ID.',
							),
							'attributes' => array(
								'type'                 => 'object',
								'description'          => 'Attribute name => option, e.g. {"Size":"XL"}.',
								'additionalProperties' => array(
									'type'      => 'string',
									'maxLength' => 100,
								),
							),
						),
						$variation_fields
					),
					'required'             => array( 'product_id', 'attributes' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'create_variation' ),
			),
			'wcmcp/product-variations-update' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_WRITE,
				'idempotent'    => true,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Update product variation', 'store-mcp-for-woocommerce' ),
				'description'   => 'Update one variation: enabled/disabled, SKU, prices, sale dates, description, stock and weight. Only the fields you pass change. Returns exactly what changed.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'variation_id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						$variation_fields
					),
					'required'             => array( 'variation_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'update_variation' ),
			),
		);
	}

	/**
	 * Schema shared by products and variations.
	 *
	 * @return array
	 */
	private static function common_fields_schema() {
		return array(
			'sku'            => array(
				'type'        => 'string',
				'maxLength'   => 100,
				'description' => 'Must be unique. Empty string removes it.',
			),
			'regular_price'  => WCMCP_Write::price_schema( 'Regular price.' ),
			'sale_price'     => WCMCP_Write::price_schema( 'Sale price; must be below the regular price.' ),
			'sale_from'      => WCMCP_Write::clearable_date_schema( 'Sale start date.' ),
			'sale_to'        => WCMCP_Write::clearable_date_schema( 'Sale end date (inclusive).' ),
			'manage_stock'   => array(
				'type'        => 'boolean',
				'description' => 'Track stock quantity.',
			),
			'stock_quantity' => array(
				'type'        => 'integer',
				'description' => 'Set stock to this number. Turns on stock tracking.',
			),
			'stock_change'   => array(
				'type'        => 'integer',
				'description' => 'Add (positive) or remove (negative) units from current stock. Cannot be combined with stock_quantity.',
			),
			'stock_status'   => array(
				'type'        => 'string',
				'enum'        => array( 'instock', 'outofstock', 'onbackorder' ),
				'description' => 'Only used when stock is not tracked; tracked stock sets this automatically.',
			),
			'backorders'     => array(
				'type' => 'string',
				'enum' => array( 'no', 'notify', 'yes' ),
			),
			'weight'         => array(
				'type'        => array( 'string', 'number' ),
				'description' => 'Weight in the store\'s weight unit. Empty string clears it.',
			),
		);
	}

	/**
	 * Executes wcmcp/products-create.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_product( array $input ) {
		$type    = isset( $input['type'] ) && 'variable' === $input['type'] ? 'variable' : 'simple';
		$product = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();

		if ( ! empty( $input['attributes'] ) ) {
			if ( 'variable' !== $type ) {
				return WCMCP_Write::error( 'wcmcp_attributes_need_variable', 'Attributes for variations need "type": "variable".' );
			}
			$attributes = self::build_attributes( $input['attributes'] );
			if ( is_wp_error( $attributes ) ) {
				return $attributes;
			}
			$product->set_attributes( $attributes );
		}

		if ( ! isset( $input['status'] ) ) {
			$input['status'] = 'draft';
		}

		$applied = self::apply_fields( $product, $input, false );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$product->save();
		self::apply_stock_change( $product, $input );

		$product = wc_get_product( $product->get_id() );
		return array(
			'created' => true,
			'product' => WCMCP_Abilities_Products::summary( $product ),
			'next'    => 'variable' === $type ? 'Add variations with product-variations-create.' : null,
		);
	}

	/**
	 * Executes wcmcp/products-update.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_product( array $input ) {
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product || 'trash' === $product->get_status() ) {
			return WCMCP_Format::not_found( 'Product', $product_id );
		}
		if ( $product->is_type( 'variation' ) ) {
			return WCMCP_Write::error( 'wcmcp_is_variation', 'This is a variation. Use product-variations-update.' );
		}
		$check = WCMCP_Write::require_changes( $input, array( 'product_id' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return self::save_with_diff( $product, $input, false );
	}

	/**
	 * Executes wcmcp/product-variations-create.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function create_variation( array $input ) {
		$parent_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$parent    = $parent_id ? wc_get_product( $parent_id ) : false;
		if ( ! $parent || 'trash' === $parent->get_status() ) {
			return WCMCP_Format::not_found( 'Product', $parent_id );
		}
		if ( ! $parent->is_type( 'variable' ) ) {
			return WCMCP_Write::error( 'wcmcp_not_variable', 'Variations can only be added to variable products.' );
		}

		$attributes = self::match_variation_attributes( $parent, isset( $input['attributes'] ) ? (array) $input['attributes'] : array() );
		if ( is_wp_error( $attributes ) ) {
			return $attributes;
		}

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( $attributes );

		$applied = self::apply_fields( $variation, $input, true );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$variation->save();
		self::apply_stock_change( $variation, $input );
		WC_Product_Variable::sync( $parent_id );

		$variation = wc_get_product( $variation->get_id() );
		return array(
			'created'   => true,
			'variation' => WCMCP_Abilities_Products::summary( $variation ) + array( 'attributes' => (object) $attributes ),
		);
	}

	/**
	 * Executes wcmcp/product-variations-update.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_variation( array $input ) {
		$variation_id = isset( $input['variation_id'] ) ? absint( $input['variation_id'] ) : 0;
		$variation    = $variation_id ? wc_get_product( $variation_id ) : false;
		if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
			return WCMCP_Format::not_found( 'Variation', $variation_id );
		}
		$check = WCMCP_Write::require_changes( $input, array( 'variation_id' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = self::save_with_diff( $variation, $input, true );
		if ( ! is_wp_error( $result ) ) {
			WC_Product_Variable::sync( $variation->get_parent_id() );
		}
		return $result;
	}

	/**
	 * Applies input, saves, and reports the changes.
	 *
	 * @param WC_Product $product      Product or variation.
	 * @param array      $input        Input.
	 * @param bool       $is_variation Whether it is a variation.
	 * @return array|WP_Error
	 */
	private static function save_with_diff( WC_Product $product, array $input, $is_variation ) {
		$before  = WCMCP_Write::snapshot( $product, self::$tracked );
		$applied = self::apply_fields( $product, $input, $is_variation );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$product->save();
		self::apply_stock_change( $product, $input );

		$product = wc_get_product( $product->get_id() );
		$after   = WCMCP_Write::snapshot( $product, self::$tracked );

		return array(
			'product' => WCMCP_Abilities_Products::summary( $product ),
			'changes' => WCMCP_Write::diff( $before, $after ),
		);
	}

	/**
	 * Validates and sets fields on a product or variation (not yet saved).
	 *
	 * @param WC_Product $product      Product or variation.
	 * @param array      $input        Input.
	 * @param bool       $is_variation Whether it is a variation.
	 * @return true|WP_Error
	 */
	private static function apply_fields( WC_Product $product, array $input, $is_variation ) {
		if ( isset( $input['name'] ) && ! $is_variation ) {
			$name = sanitize_text_field( $input['name'] );
			if ( '' === $name ) {
				return WCMCP_Write::error( 'wcmcp_empty_name', 'The product name cannot be empty.' );
			}
			$product->set_name( $name );
		}

		if ( isset( $input['status'] ) ) {
			$allowed = $is_variation ? array( 'publish', 'private' ) : array( 'draft', 'pending', 'private', 'publish' );
			if ( ! in_array( $input['status'], $allowed, true ) ) {
				return WCMCP_Write::error( 'wcmcp_invalid_status', 'Status must be one of: ' . implode( ', ', $allowed ) . '.' );
			}
			if ( ! $is_variation && 'publish' === $input['status'] && 'publish' !== $product->get_status() && ! current_user_can( 'publish_products' ) ) {
				return WCMCP_Write::error( 'wcmcp_cannot_publish', 'The key owner is not allowed to publish products.', 403 );
			}
			$product->set_status( $input['status'] );
		}

		if ( isset( $input['sku'] ) ) {
			// Throws WC_Data_Exception for duplicates; the registry turns that into an error result.
			$product->set_sku( wc_clean( $input['sku'] ) );
		}

		foreach ( array( 'regular_price', 'sale_price' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$price = WCMCP_Write::price( $input[ $field ], $field );
				if ( is_wp_error( $price ) ) {
					return $price;
				}
				$setter = "set_{$field}";
				$product->$setter( $price );
			}
		}
		$regular = $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );
		if ( '' !== $sale && ( '' === $regular || (float) $sale >= (float) $regular ) ) {
			return WCMCP_Write::error( 'wcmcp_invalid_sale_price', 'The sale price must be lower than the regular price.' );
		}

		foreach ( array(
			'sale_from' => 'set_date_on_sale_from',
			'sale_to'   => 'set_date_on_sale_to',
		) as $field => $setter ) {
			if ( array_key_exists( $field, $input ) ) {
				$date = WCMCP_Write::date( $input[ $field ], $field, 'sale_to' === $field );
				if ( is_wp_error( $date ) ) {
					return $date;
				}
				$product->$setter( '' === $date ? null : $date );
			}
		}

		if ( isset( $input['description'] ) ) {
			$product->set_description( WCMCP_Write::html( $input['description'] ) );
		}
		if ( isset( $input['short_description'] ) && ! $is_variation ) {
			$product->set_short_description( WCMCP_Write::html( $input['short_description'] ) );
		}

		if ( isset( $input['categories'] ) && ! $is_variation ) {
			$category_ids = self::resolve_categories( (array) $input['categories'] );
			if ( is_wp_error( $category_ids ) ) {
				return $category_ids;
			}
			$product->set_category_ids( $category_ids );
		}

		if ( isset( $input['stock_quantity'] ) && isset( $input['stock_change'] ) ) {
			return WCMCP_Write::error( 'wcmcp_stock_conflict', 'Use either stock_quantity (set) or stock_change (add/remove), not both.' );
		}
		if ( isset( $input['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $input['manage_stock'] );
		}
		if ( isset( $input['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $input['stock_quantity'] );
		}
		if ( isset( $input['stock_change'] ) && ! $product->get_manage_stock() ) {
			return WCMCP_Write::error( 'wcmcp_stock_not_tracked', 'Stock is not tracked for this item. Set stock_quantity instead, which turns tracking on.' );
		}
		if ( isset( $input['stock_status'] ) ) {
			$product->set_stock_status( $input['stock_status'] );
		}
		if ( isset( $input['backorders'] ) ) {
			$product->set_backorders( $input['backorders'] );
		}

		if ( array_key_exists( 'weight', $input ) ) {
			$weight = $input['weight'];
			if ( '' !== $weight && null !== $weight && ( ! is_numeric( $weight ) || (float) $weight < 0 ) ) {
				return WCMCP_Write::error( 'wcmcp_invalid_weight', 'Weight must be a number of 0 or more.' );
			}
			$product->set_weight( '' === $weight || null === $weight ? '' : wc_format_decimal( $weight ) );
		}

		return true;
	}

	/**
	 * Applies a relative stock change after saving, using WooCommerce's atomic stock update.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $input   Input.
	 * @return void
	 */
	private static function apply_stock_change( WC_Product $product, array $input ) {
		if ( empty( $input['stock_change'] ) ) {
			return;
		}
		$change = (int) $input['stock_change'];
		wc_update_product_stock( $product, abs( $change ), $change > 0 ? 'increase' : 'decrease' );
	}

	/**
	 * Category IDs from IDs, slugs or names. All must exist.
	 *
	 * @param array $values Values.
	 * @return int[]|WP_Error
	 */
	private static function resolve_categories( array $values ) {
		$ids     = array();
		$missing = array();
		foreach ( $values as $value ) {
			$term = null;
			if ( is_int( $value ) || ctype_digit( (string) $value ) ) {
				$term = get_term( (int) $value, 'product_cat' );
			} else {
				$value = sanitize_text_field( (string) $value );
				$term  = get_term_by( 'slug', sanitize_title( $value ), 'product_cat' );
				$term  = $term ? $term : get_term_by( 'name', $value, 'product_cat' );
			}
			if ( $term instanceof WP_Term ) {
				$ids[] = (int) $term->term_id;
			} else {
				$missing[] = (string) $value;
			}
		}
		if ( $missing ) {
			return WCMCP_Write::error( 'wcmcp_unknown_category', 'These categories do not exist: ' . implode( ', ', $missing ) . '. Create them in WooCommerce first, or use existing ones.' );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Builds custom attributes for a new variable product.
	 *
	 * @param array $rows Attribute rows.
	 * @return WC_Product_Attribute[]|WP_Error
	 */
	private static function build_attributes( array $rows ) {
		$attributes = array();
		$position   = 0;
		foreach ( $rows as $row ) {
			$name    = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			$options = isset( $row['options'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', (array) $row['options'] ), 'strlen' ) ) : array();
			if ( '' === $name || empty( $options ) ) {
				return WCMCP_Write::error( 'wcmcp_invalid_attribute', 'Each attribute needs a name and at least one option.' );
			}
			$attribute = new WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array_unique( $options ) );
			$attribute->set_position( $position++ );
			$attribute->set_visible( ! isset( $row['visible'] ) || (bool) $row['visible'] );
			$attribute->set_variation( ! isset( $row['variation'] ) || (bool) $row['variation'] );
			$attributes[] = $attribute;
		}
		return $attributes;
	}

	/**
	 * Maps { "Size": "XL" } onto the parent's variation attributes, validating names and options.
	 *
	 * @param WC_Product $parent Parent variable product.
	 * @param array      $input  Attribute name => option.
	 * @return array<string,string>|WP_Error Attribute key => stored value.
	 */
	private static function match_variation_attributes( WC_Product $parent, array $input ) {
		$available = array();
		foreach ( $parent->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}
			$key     = sanitize_title( $attribute->get_name() );
			$label   = wc_attribute_label( $attribute->get_name(), $parent );
			$options = array();
			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() as $term ) {
					$options[ strtolower( $term->name ) ] = $term->slug;
					$options[ strtolower( $term->slug ) ] = $term->slug;
				}
			} else {
				foreach ( $attribute->get_options() as $option ) {
					$options[ strtolower( $option ) ] = $option;
				}
			}
			$available[ strtolower( $label ) ] = array(
				'key'     => $key,
				'label'   => $label,
				'options' => $options,
			);
			$available[ strtolower( $key ) ]   = $available[ strtolower( $label ) ];
		}

		if ( empty( $available ) ) {
			return WCMCP_Write::error( 'wcmcp_no_variation_attributes', 'This product has no attributes used for variations. Add them to the product first.' );
		}

		$result = array();
		foreach ( $input as $name => $value ) {
			$name  = strtolower( sanitize_text_field( (string) $name ) );
			$value = sanitize_text_field( (string) $value );
			if ( ! isset( $available[ $name ] ) ) {
				$labels = array_unique( wp_list_pluck( $available, 'label' ) );
				return WCMCP_Write::error( 'wcmcp_unknown_attribute', sprintf( 'Unknown attribute "%s". This product varies by: %s.', $name, implode( ', ', $labels ) ) );
			}
			$attribute = $available[ $name ];
			if ( '' !== $value && ! isset( $attribute['options'][ strtolower( $value ) ] ) ) {
				return WCMCP_Write::error( 'wcmcp_unknown_option', sprintf( '"%s" is not an option for %s. Options: %s.', $value, $attribute['label'], implode( ', ', array_unique( array_values( $attribute['options'] ) ) ) ) );
			}
			$result[ $attribute['key'] ] = '' === $value ? '' : $attribute['options'][ strtolower( $value ) ];
		}

		// Refuse an exact duplicate of an existing variation.
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof WC_Product_Variation ) {
				$existing = array_change_key_case( $child->get_attributes( 'edit' ), CASE_LOWER );
				$wanted   = array_change_key_case( $result, CASE_LOWER );
				ksort( $existing );
				ksort( $wanted );
				if ( array_map( 'strtolower', $existing ) === array_map( 'strtolower', $wanted ) ) {
					return WCMCP_Write::error( 'wcmcp_duplicate_variation', sprintf( 'Variation %d already has these attributes. Update it instead.', $child_id ) );
				}
			}
		}

		return $result;
	}
}
