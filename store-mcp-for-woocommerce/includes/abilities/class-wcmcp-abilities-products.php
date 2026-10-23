<?php
/**
 * Products pack: read tools, including variations.
 *
 * @package WCMCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product abilities.
 */
final class WCMCP_Abilities_Products {

	const PACK = 'products';

	const CAPABILITY = 'edit_products';

	const MAX_VARIATIONS_INLINE = 25;

	const DESCRIPTION_LIMIT = 2000;

	/**
	 * Ability definitions.
	 *
	 * @return array<string, array>
	 */
	public static function definitions() {
		return array(
			'wcmcp/products-list'   => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'List products', 'store-mcp-for-woocommerce' ),
				'description'   => 'List or search products (not individual variations). Filter by search term, SKU, status, type, category or stock status. Returns a compact summary; use products-get for full details. Paginated.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'search'       => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'Words in the product name or description.',
							),
							'sku'          => array(
								'type'        => 'string',
								'maxLength'   => 100,
								'description' => 'SKU or part of one.',
							),
							'status'       => array(
								'type'    => 'string',
								'enum'    => array( 'any', 'publish', 'draft', 'pending', 'private' ),
								'default' => 'any',
							),
							'type'         => array(
								'type' => 'string',
								'enum' => array( 'simple', 'variable', 'grouped', 'external' ),
							),
							'category'     => array(
								'type'        => 'string',
								'maxLength'   => 200,
								'description' => 'Category slug.',
							),
							'stock_status' => array(
								'type' => 'string',
								'enum' => array( 'instock', 'outofstock', 'onbackorder' ),
							),
							'orderby'      => array(
								'type'    => 'string',
								'enum'    => array( 'date', 'title', 'id', 'price', 'popularity' ),
								'default' => 'date',
							),
							'order'        => array(
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
				'execute'       => array( __CLASS__, 'list_products' ),
			),
			'wcmcp/products-get'    => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'Get product', 'store-mcp-for-woocommerce' ),
				'description'   => 'Full details of one product or variation: prices, stock, descriptions (plain text), categories, tags, attributes, dimensions and images. For variable products, includes up to ' . self::MAX_VARIATIONS_INLINE . ' variations; use product-variations-list for more.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'product_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Product or variation ID.',
						),
					),
					'required'             => array( 'product_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array( 'type' => 'object' ),
				'execute'       => array( __CLASS__, 'get_product' ),
			),
			'wcmcp/product-variations-list' => array(
				'pack'          => self::PACK,
				'op'            => WCMCP_Permissions::OP_READ,
				'capability'    => self::CAPABILITY,
				'label'         => __( 'List product variations', 'store-mcp-for-woocommerce' ),
				'description'   => 'List the variations of a variable product with their attributes, SKU, prices and stock. Paginated.',
				'input_schema'  => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'product_id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Parent (variable) product ID.',
							),
						),
						WCMCP_Format::pagination_schema()
					),
					'required'             => array( 'product_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => WCMCP_Format::paged_output_schema(),
				'execute'       => array( __CLASS__, 'list_variations' ),
			),
		);
	}

	/**
	 * Executes wcmcp/products-list.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_products( array $input ) {
		list( $page, $per_page ) = WCMCP_Format::pagination( $input );

		$status = isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $input['status'] : array( 'publish', 'draft', 'pending', 'private' );
		$args   = array(
			'status'   => $status,
			'type'     => isset( $input['type'] ) && in_array( $input['type'], array( 'simple', 'variable', 'grouped', 'external' ), true ) ? $input['type'] : array( 'simple', 'variable', 'grouped', 'external' ),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'title', 'id', 'price', 'popularity' ), true ) ? $input['orderby'] : 'date',
			'order'    => isset( $input['order'] ) && 'asc' === $input['order'] ? 'ASC' : 'DESC',
		);

		if ( 'price' === $args['orderby'] || 'popularity' === $args['orderby'] ) {
			$args['meta_key'] = 'price' === $args['orderby'] ? '_price' : 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			$args['orderby']  = 'meta_value_num';
		}

		$search = isset( $input['search'] ) ? trim( sanitize_text_field( $input['search'] ) ) : '';
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		$sku = isset( $input['sku'] ) ? trim( sanitize_text_field( $input['sku'] ) ) : '';
		if ( '' !== $sku ) {
			$args['sku'] = $sku;
		}
		$category = isset( $input['category'] ) ? sanitize_title( $input['category'] ) : '';
		if ( '' !== $category ) {
			$args['category'] = array( $category );
		}
		if ( isset( $input['stock_status'] ) && in_array( $input['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			$args['stock_status'] = $input['stock_status'];
		}

		$result = wc_get_products( $args );
		$items  = array();
		foreach ( $result->products as $product ) {
			$items[] = self::summary( $product );
		}

		return WCMCP_Format::paged( $items, $result->total, $page, $per_page );
	}

	/**
	 * Executes wcmcp/products-get.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_product( array $input ) {
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product || 'trash' === $product->get_status() ) {
			return WCMCP_Format::not_found( 'Product', $product_id );
		}

		$data = self::summary( $product );

		$description = WCMCP_Format::text( $product->get_description(), self::DESCRIPTION_LIMIT );

		$data['parent_id']             = $product->get_parent_id();
		$data['permalink']             = $product->is_type( 'variation' ) || 'publish' === $product->get_status() ? get_permalink( $product->get_id() ) : null;
		$data['description']           = $description;
		$data['short_description']     = WCMCP_Format::text( $product->get_short_description(), 1000 );
		$data['sale_from']             = WCMCP_Format::date( $product->get_date_on_sale_from() );
		$data['sale_to']               = WCMCP_Format::date( $product->get_date_on_sale_to() );
		$data['tax_status']            = $product->get_tax_status();
		$data['tax_class']             = $product->get_tax_class();
		$data['backorders']            = $product->get_backorders();
		$data['low_stock_amount']      = '' === $product->get_low_stock_amount() ? null : (int) $product->get_low_stock_amount();
		$data['virtual']               = $product->is_virtual();
		$data['downloadable']          = $product->is_downloadable();
		$data['weight']                = (string) $product->get_weight();
		$data['dimensions']            = array(
			'length' => (string) $product->get_length(),
			'width'  => (string) $product->get_width(),
			'height' => (string) $product->get_height(),
		);
		$data['tags']                  = self::terms( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(), 'product_tag' );
		$data['attributes']            = self::attributes( $product );
		$data['images']                = self::images( $product );
		$data['date_created']          = WCMCP_Format::date( $product->get_date_created() );
		$data['date_modified']         = WCMCP_Format::date( $product->get_date_modified() );

		if ( $product->is_type( 'variable' ) ) {
			$children                    = $product->get_children();
			$data['variation_count']     = count( $children );
			$data['variations']          = array();
			foreach ( array_slice( $children, 0, self::MAX_VARIATIONS_INLINE ) as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation ) {
					$data['variations'][] = self::variation_summary( $variation );
				}
			}
			$data['variations_truncated'] = count( $children ) > self::MAX_VARIATIONS_INLINE;
		}

		if ( $product->is_type( 'grouped' ) ) {
			$data['grouped_product_ids'] = array_map( 'absint', $product->get_children() );
		}

		return $data;
	}

	/**
	 * Executes wcmcp/product-variations-list.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function list_variations( array $input ) {
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product || 'trash' === $product->get_status() ) {
			return WCMCP_Format::not_found( 'Product', $product_id );
		}
		if ( ! $product->is_type( 'variable' ) ) {
			return new WP_Error( 'wcmcp_not_variable', 'This product has no variations: it is a ' . $product->get_type() . ' product.', array( 'status' => 400 ) );
		}

		list( $page, $per_page ) = WCMCP_Format::pagination( $input );

		$children = $product->get_children();
		$items    = array();
		foreach ( array_slice( $children, ( $page - 1 ) * $per_page, $per_page ) as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation ) {
				$items[] = self::variation_summary( $variation );
			}
		}

		return WCMCP_Format::paged( $items, count( $children ), $page, $per_page );
	}

	/**
	 * Compact product summary.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function summary( WC_Product $product ) {
		$category_owner = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return array(
			'product_id'     => $product->get_id(),
			'name'           => WCMCP_Format::text( $product->get_name(), 200 ),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'sku'            => (string) $product->get_sku(),
			'price'          => self::price( $product->get_price() ),
			'regular_price'  => self::price( $product->get_regular_price() ),
			'sale_price'     => self::price( $product->get_sale_price() ),
			'on_sale'        => $product->is_on_sale(),
			'manage_stock'   => (bool) $product->get_manage_stock(),
			'stock_quantity' => null === $product->get_stock_quantity() ? null : (int) $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
			'total_sales'    => (int) $product->get_total_sales(),
			'categories'     => self::terms( $category_owner, 'product_cat' ),
		);
	}

	/**
	 * Variation summary.
	 *
	 * @param WC_Product $variation Variation.
	 * @return array
	 */
	private static function variation_summary( WC_Product $variation ) {
		$attributes = array();
		if ( $variation instanceof WC_Product_Variation ) {
			foreach ( $variation->get_variation_attributes( false ) as $taxonomy => $value ) {
				$label = wc_attribute_label( $taxonomy, $variation );
				if ( taxonomy_exists( $taxonomy ) && '' !== $value ) {
					$term  = get_term_by( 'slug', $value, $taxonomy );
					$value = $term ? $term->name : $value;
				}
				$attributes[ WCMCP_Format::text( $label, 100 ) ] = '' === $value ? 'Any' : WCMCP_Format::text( $value, 100 );
			}
		}

		return array(
			'variation_id'   => $variation->get_id(),
			'attributes'     => (object) $attributes,
			'status'         => $variation->get_status(),
			'sku'            => (string) $variation->get_sku(),
			'price'          => self::price( $variation->get_price() ),
			'regular_price'  => self::price( $variation->get_regular_price() ),
			'sale_price'     => self::price( $variation->get_sale_price() ),
			'manage_stock'   => 'parent' === $variation->get_manage_stock() ? 'parent' : (bool) $variation->get_manage_stock(),
			'stock_quantity' => null === $variation->get_stock_quantity() ? null : (int) $variation->get_stock_quantity(),
			'stock_status'   => $variation->get_stock_status(),
		);
	}

	/**
	 * Price string, or null when unset.
	 *
	 * @param mixed $price Price.
	 * @return string|null
	 */
	private static function price( $price ) {
		return '' === $price || null === $price ? null : WCMCP_Format::money( $price );
	}

	/**
	 * Term names and slugs for a product.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy.
	 * @return array
	 */
	private static function terms( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_map(
			static function ( $term ) {
				return array(
					'id'   => (int) $term->term_id,
					'name' => WCMCP_Format::text( $term->name, 200 ),
					'slug' => $term->slug,
				);
			},
			array_values( $terms )
		);
	}

	/**
	 * Product attributes with their options.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function attributes( WC_Product $product ) {
		if ( $product->is_type( 'variation' ) ) {
			return array();
		}

		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$options = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();

			$attributes[] = array(
				'name'      => WCMCP_Format::text( wc_attribute_label( $attribute->get_name(), $product ), 100 ),
				'options'   => array_map(
					static function ( $option ) {
						return WCMCP_Format::text( $option, 100 );
					},
					array_values( (array) $options )
				),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			);
		}
		return $attributes;
	}

	/**
	 * Image URLs: main image first, then gallery (max 10).
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function images( WC_Product $product ) {
		$ids    = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$images = array();
		foreach ( array_slice( array_unique( array_map( 'absint', $ids ) ), 0, 10 ) as $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$images[] = array(
					'id'  => $image_id,
					'url' => esc_url_raw( $url ),
					'alt' => WCMCP_Format::text( get_post_meta( $image_id, '_wp_attachment_image_alt', true ), 200 ),
				);
			}
		}
		return $images;
	}
}
