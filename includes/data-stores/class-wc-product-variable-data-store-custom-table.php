<?php
/**
 * WC Variable Product Data Store: Stored in Custom Table
 *
 * @author   Automattic
 * @category Data_Store
 * @package  WooCommerce/Classes/Data_Store
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variable Product Data Store class.
 */
class WC_Product_Variable_Data_Store_Custom_Table extends WC_Product_Data_Store_Custom_Table implements WC_Object_Data_Store_Interface {

	/**
	 * Cached & hashed prices array for child variations.
	 *
	 * @var array
	 */
	protected $prices_array = array();

	/**
	 * Read product data.
	 *
	 * @since 3.0.0
	 * @param WC_Product $product Product object.
	 */
	protected function read_product_data( &$product ) {
		parent::read_product_data( $product );

		// Make sure data which does not apply to variables is unset.
		$product->set_regular_price( '' );
		$product->set_sale_price( '' );

		// Set directly since individual data needs changed at the WC_Product_Variation level -- these datasets just pull.
		$children = $this->read_children( $product );
		$product->set_children( $children['all'] );
		$product->set_visible_children( $children['visible'] );
		$product->set_variation_attributes( $this->read_variation_attributes( $product ) );
	}

	/**
	 * Loads variation child IDs. @todo woocommerce_variable_children_args bw compat
	 *
	 * @param  WC_Product $product Product object.
	 * @param  bool       $force_read True to bypass the transient.
	 * @return array
	 */
	protected function read_children( &$product, $force_read = false ) {
		$children_transient_name = 'wc_product_children_' . $product->get_id();
		$children                = get_transient( $children_transient_name );

		if ( empty( $children ) || ! is_array( $children ) || ! isset( $children['all'] ) || ! isset( $children['visible'] ) || $force_read ) {
			$products = wc_get_products( array(
				'parent'  => $product->get_id(),
				'type'    => 'variation',
				'orderby' => 'menu_order',
				'limit'   => -1,
				'return'  => 'ids',
			) );

			$children['all']     = $products;
			$children['visible'] = $products;

			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$children['visible'] = wc_get_products( array(
					'parent'       => $product->get_id(),
					'type'         => 'variation',
					'orderby'      => 'menu_order',
					'limit'        => -1,
					'stock_status' => 'instock',
					'return'       => 'ids',
				) );
			}
			set_transient( $children_transient_name, $children, DAY_IN_SECONDS * 30 );
		}
		return $children;
	}

	/**
	 * Loads an array of attributes used for variations, as well as their possible values. @todo
	 *
	 * @param WC_Product $product Product object.
	 */
	protected function read_variation_attributes( &$product ) {}

	/**
	 * Get an array of all sale and regular prices from all variations. This is used for example when displaying the price range at variable product level or seeing if the variable product is on sale. @todo
	 *
	 * Can be filtered by plugins which modify costs, but otherwise will include the raw meta costs unlike get_price() which runs costs through the woocommerce_get_price filter.
	 * This is to ensure modified prices are not cached, unless intended.
	 *
	 * @since  3.0.0
	 * @param  WC_Product $product Product object.
	 * @param  bool       $include_taxes If taxes should be calculated or not.
	 * @return array of prices
	 */
	public function read_price_data( &$product, $include_taxes = false ) {

		/**
		 * Transient name for storing prices for this product (note: Max transient length is 45).
		 *
		 * @since 2.5.0 a single transient is used per product for all prices, rather than many transients per product.
		 */
		$transient_name = 'wc_var_prices_' . $product->get_id();

		$price_hash = $this->get_price_hash( $product, $include_taxes );

		/**
		 * $this->prices_array is an array of values which may have been modified from what is stored in transients - this may not match $transient_cached_prices_array.
		 * If the value has already been generated, we don't need to grab the values again so just return them. They are already filtered.
		 */
		if ( empty( $this->prices_array[ $price_hash ] ) ) {
			$transient_cached_prices_array = array_filter( (array) json_decode( strval( get_transient( $transient_name ) ), true ) );

			// If the product version has changed since the transient was last saved, reset the transient cache.
			if ( empty( $transient_cached_prices_array['version'] ) || WC_Cache_Helper::get_transient_version( 'product' ) !== $transient_cached_prices_array['version'] ) {
				$transient_cached_prices_array = array(
					'version' => WC_Cache_Helper::get_transient_version( 'product' ),
				);
			}

			// If the prices are not stored for this hash, generate them and add to the transient.
			if ( empty( $transient_cached_prices_array[ $price_hash ] ) ) {
				$prices         = array();
				$regular_prices = array();
				$sale_prices    = array();
				$variation_ids  = $product->get_visible_children();
				foreach ( $variation_ids as $variation_id ) {
					$variation = wc_get_product( $variation_id );

					if ( $variation ) {
						$price         = apply_filters( 'woocommerce_variation_prices_price', $variation->get_price( 'edit' ), $variation, $product );
						$regular_price = apply_filters( 'woocommerce_variation_prices_regular_price', $variation->get_regular_price( 'edit' ), $variation, $product );
						$sale_price    = apply_filters( 'woocommerce_variation_prices_sale_price', $variation->get_sale_price( 'edit' ), $variation, $product );

						// Skip empty prices.
						if ( '' === $price ) {
							continue;
						}

						// If sale price does not equal price, the product is not yet on sale.
						if ( $sale_price === $regular_price || $sale_price !== $price ) {
							$sale_price = $regular_price;
						}

						// If we are getting prices for display, we need to account for taxes.
						if ( $include_taxes ) {
							if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
								$price         = '' === $price ? '' : wc_get_price_including_tax( $variation, array(
									'qty'   => 1,
									'price' => $price,
								) );
								$regular_price = '' === $regular_price ? '' : wc_get_price_including_tax( $variation, array(
									'qty'   => 1,
									'price' => $regular_price,
								) );
								$sale_price    = '' === $sale_price ? '' : wc_get_price_including_tax( $variation, array(
									'qty'   => 1,
									'price' => $sale_price,
								) );
							} else {
								$price         = '' === $price ? '' : wc_get_price_excluding_tax( $variation, array(
									'qty'   => 1,
									'price' => $price,
								) );
								$regular_price = '' === $regular_price ? '' : wc_get_price_excluding_tax( $variation, array(
									'qty'   => 1,
									'price' => $regular_price,
								) );
								$sale_price    = '' === $sale_price ? '' : wc_get_price_excluding_tax( $variation, array(
									'qty'   => 1,
									'price' => $sale_price,
								) );
							}
						}

						$prices[ $variation_id ]         = wc_format_decimal( $price, wc_get_price_decimals() );
						$regular_prices[ $variation_id ] = wc_format_decimal( $regular_price, wc_get_price_decimals() );
						$sale_prices[ $variation_id ]    = wc_format_decimal( $sale_price . '.00', wc_get_price_decimals() );
					}
				}

				$transient_cached_prices_array[ $price_hash ] = array(
					'price'         => $prices,
					'regular_price' => $regular_prices,
					'sale_price'    => $sale_prices,
				);

				set_transient( $transient_name, wp_json_encode( $transient_cached_prices_array ), DAY_IN_SECONDS * 30 );
			}

			/**
			 * Give plugins one last chance to filter the variation prices array which has been generated and store locally to the class.
			 * This value may differ from the transient cache. It is filtered once before storing locally.
			 */
			$this->prices_array[ $price_hash ] = apply_filters( 'woocommerce_variation_prices', $transient_cached_prices_array[ $price_hash ], $product, $include_taxes );
		}
		return $this->prices_array[ $price_hash ];
	}

	/**
	 * Create unique cache key based on the tax location (affects displayed/cached prices), product version and active price filters.
	 * DEVELOPERS should filter this hash if offering conditional pricing to keep it unique.
	 *
	 * @since  3.0.0
	 * @param  WC_Product $product Product object.
	 * @param  bool       $include_taxes If taxes should be calculated or not.
	 * @return string
	 */
	protected function get_price_hash( &$product, $include_taxes = false ) {
		global $wp_filter;

		$price_hash   = $include_taxes ? array( get_option( 'woocommerce_tax_display_shop', 'excl' ), WC_Tax::get_rates() ) : array( false );
		$filter_names = array( 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_regular_price', 'woocommerce_variation_prices_sale_price' );

		foreach ( $filter_names as $filter_name ) {
			if ( ! empty( $wp_filter[ $filter_name ] ) ) {
				$price_hash[ $filter_name ] = array();

				foreach ( $wp_filter[ $filter_name ] as $priority => $callbacks ) {
					$price_hash[ $filter_name ][] = array_values( wp_list_pluck( $callbacks, 'function' ) );
				}
			}
		}

		$price_hash[] = WC_Cache_Helper::get_transient_version( 'product' );
		$price_hash   = md5( wp_json_encode( apply_filters( 'woocommerce_get_variation_prices_hash', $price_hash, $product, $include_taxes ) ) );

		return $price_hash;
	}

	/**
	 * Does a child have a weight set?
	 *
	 * @since 3.0.0
	 * @param WC_Product $product Product object.
	 * @return boolean
	 */
	public function child_has_weight( $product ) {
		global $wpdb;

		$child_has_weight = wp_cache_get( 'woocommerce_product_child_has_weight_' . $product->get_id(), 'product' );

		if ( false === $child_has_weight ) {
			$query = "
				SELECT product_id
				FROM {$wpdb->prefix}wc_products as products
				LEFT JOIN {$wpdb->posts} as posts ON products.product_id = posts.ID
				WHERE posts.post_parent = %d
				AND products.weight > 0
			";

			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$query .= " AND products.stock_status = 'instock' ";
			}

			$child_has_weight = null !== $wpdb->get_var( $wpdb->prepare( $query, $product->get_id() ) ) ? 1 : 0; // WPCS: db call ok, cache ok, unprepared SQL OK.

			wp_cache_set( 'woocommerce_product_child_has_weight_' . $product->get_id(), $child_has_weight, 'product' );
		}

		return (bool) $child_has_weight;
	}

	/**
	 * Does a child have dimensions set?
	 *
	 * @since 3.0.0
	 * @param WC_Product $product Product object.
	 * @return boolean
	 */
	public function child_has_dimensions( $product ) {
		global $wpdb;

		$child_has_dimensions = wp_cache_get( 'woocommerce_product_child_has_dimensions_' . $product->get_id(), 'product' );

		if ( false === $child_has_dimensions ) {
			$query = "
				SELECT product_id
				FROM {$wpdb->prefix}wc_products as products
				LEFT JOIN {$wpdb->posts} as posts ON products.product_id = posts.ID
				WHERE posts.post_parent = %d
				AND (
					products.length > 0
					OR products.width > 0
					OR products.height > 0
				)
			";

			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
				$query .= " AND products.stock_status = 'instock' ";
			}

			$child_has_dimensions = null !== $wpdb->get_var( $wpdb->prepare( $query, $product->get_id() ) ) ? 1 : 0; // WPCS: db call ok, cache ok, unprepared SQL OK.

			wp_cache_set( 'woocommerce_product_child_has_dimensions_' . $product->get_id(), $child_has_dimensions, 'product' );
		}

		return (bool) $child_has_dimensions;
	}

	/**
	 * Is a child in stock?
	 *
	 * @since 3.0.0
	 * @param WC_Product $product Product object.
	 * @return boolean
	 */
	public function child_is_in_stock( $product ) {
		global $wpdb;

		$child_is_in_stock = wp_cache_get( 'woocommerce_product_child_is_in_stock_' . $product->get_id(), 'product' );

		if ( false === $child_is_in_stock ) {
			$child_is_in_stock = null !== $wpdb->get_var( $wpdb->prepare( "
				SELECT product_id
				FROM {$wpdb->prefix}wc_products as products
				LEFT JOIN {$wpdb->posts} as posts ON products.product_id = posts.ID
				WHERE posts.post_parent = %d
				AND products.stock_status = 'instock'
			", $product->get_id() ) ) ? 1 : 0; // WPCS: db call ok, cache ok.

			wp_cache_set( 'woocommerce_product_child_is_in_stock_' . $product->get_id(), $child_is_in_stock, 'product' );
		}

		return (bool) $child_is_in_stock;
	}

	/*
	 * @todo
	 *
	 * sync_variation_names
	 * sync_managed_variation_stock_status
	 * sync_price
	 * sync_stock_status
	 * delete_variations
	 * untrash_variations
	 * read_variation_attributes
	 */
}