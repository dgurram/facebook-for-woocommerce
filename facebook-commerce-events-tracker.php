<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

if ( ! class_exists( 'WC_Facebookcommerce_EventsTracker' ) ) :

	if ( ! class_exists( 'WC_Facebookcommerce_Utils' ) ) {
		include_once 'includes/fbutils.php';
	}

	if ( ! class_exists( 'WC_Facebookcommerce_Pixel' ) ) {
		include_once 'facebook-commerce-pixel-event.php';
	}

	class WC_Facebookcommerce_EventsTracker {
		private $pixel;
		private static $isEnabled = true;
		const FB_PRIORITY_HIGH    = 2;
		const FB_PRIORITY_LOW     = 11;

		public function __construct( $user_info ) {
			$this->pixel = new WC_Facebookcommerce_Pixel( $user_info );

			add_action( 'wp_head', array( $this, 'apply_filters' ) );

			// Pixel Tracking Hooks
			add_action(
				'wp_head',
				array( $this, 'inject_base_pixel' )
			);
			add_action(
				'wp_footer',
				array( $this, 'inject_base_pixel_noscript' )
			);

			// ViewContent for individual products
			add_action( 'woocommerce_after_single_product', [ $this, 'inject_view_content_event' ] );

			add_action(
				'woocommerce_after_shop_loop',
				array( $this, 'inject_view_category_event' )
			);
			add_action(
				'pre_get_posts',
				array( $this, 'inject_search_event' )
			);

			// AddToCart
			add_action( 'woocommerce_add_to_cart',             [ $this, 'inject_add_to_cart_event' ], 40, 4 );
			// AddToCart while AJAX is enabled
			add_action( 'wc_ajax_fb_inject_add_to_cart_event', [ $this, 'inject_ajax_add_to_cart_event' ] );
			// AddToCart while using redirect to cart page
			if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
				add_filter( 'woocommerce_add_to_cart_redirect', [ $this, 'set_last_product_added_to_cart_upon_redirect' ], 10, 2 );
				add_action( 'woocommerce_after_cart',           [ $this, 'inject_add_to_cart_redirect_event' ], 10, 2 );
			}

			add_action(
				'woocommerce_after_checkout_form',
				array( $this, 'inject_initiate_checkout_event' )
			);
			add_action(
				'woocommerce_thankyou',
				array( $this, 'inject_gateway_purchase_event' ),
				self::FB_PRIORITY_HIGH
			);
			add_action(
				'woocommerce_payment_complete',
				array( $this, 'inject_purchase_event' ),
				self::FB_PRIORITY_HIGH
			);
			add_action(
				'wpcf7_contact_form',
				array( $this, 'inject_lead_event_hook' ),
				self::FB_PRIORITY_LOW
			);

		}

		public function apply_filters() {
			self::$isEnabled = apply_filters(
				'facebook_for_woocommerce_integration_pixel_enabled',
				self::$isEnabled
			);
		}


		/**
		 * Prints the base JavaScript pixel code.
		 */
		public function inject_base_pixel() {

			if ( self::$isEnabled ) {
				// phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
				echo $this->pixel->pixel_base_code();
			}
		}


		/**
		 * Prints the base <noscript> pixel code.
		 *
		 * This is necessary to avoid W3 validation errors.
		 */
		public function inject_base_pixel_noscript() {

			if ( self::$isEnabled ) {
				// phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
				echo $this->pixel->pixel_base_code_noscript();
			}
		}


		/**
		 * Triggers ViewCategory for product category listings
		 */
		public function inject_view_category_event() {
			global $wp_query;
			if ( ! self::$isEnabled ) {
				return;
			}

			$products = array_values(
				array_map(
					function( $item ) {
						return wc_get_product( $item->ID );
					},
					$wp_query->posts
				)
			);

			// if any product is a variant, fire the pixel with
			// content_type: product_group
			$content_type = 'product';
			$product_ids  = array();
			foreach ( $products as $product ) {
				if ( ! $product ) {
					continue;
				}
				$product_ids = array_merge(
					$product_ids,
					WC_Facebookcommerce_Utils::get_fb_content_ids( $product )
				);
				if ( WC_Facebookcommerce_Utils::is_variable_type( $product->get_type() ) ) {
					$content_type = 'product_group';
				}
			}

			$categories =
			WC_Facebookcommerce_Utils::get_product_categories( get_the_ID() );

			$this->pixel->inject_event(
				'ViewCategory',
				array(
					'content_name'     => $categories['name'],
					'content_category' => $categories['categories'],
					'content_ids'      => json_encode( array_slice( $product_ids, 0, 10 ) ),
					'content_type'     => $content_type,
				),
				'trackCustom'
			);
		}

		/**
		 * Triggers Search for result pages (deduped)
		 */
		public function inject_search_event() {
			if ( ! self::$isEnabled ) {
				return;
			}

			if ( ! is_admin() && is_search() && get_search_query() !== '' ) {
				if ( $this->pixel->check_last_event( 'Search' ) ) {
					return;
				}

				if ( WC_Facebookcommerce_Utils::isWoocommerceIntegration() ) {
					$this->actually_inject_search_event();
				} else {
					add_action( 'wp_head', array( $this, 'actually_inject_search_event' ), 11 );
				}
			}
		}

		/**
		 * Triggers Search for result pages
		 */
		public function actually_inject_search_event() {
			if ( ! self::$isEnabled ) {
				return;
			}

			$this->pixel->inject_event(
				'Search',
				array(
					'search_string' => get_search_query(),
				)
			);
		}


		/**
		 * Triggers ViewContent event on product pages
		 *
		 * @internal
		 */
		public function inject_view_content_event() {
			global $post;

			if ( ! self::$isEnabled || ! isset( $post->ID ) ) {
				return;
			}

			$product = wc_get_product( $post->ID );

			if ( ! $product instanceof \WC_Product ) {
				return;
			}

			// if product is variable or grouped, fire the pixel with content_type: product_group
			if ( $product->is_type( [ 'variable', 'grouped' ] ) ) {
				$content_type = 'product_group';
			} else {
				$content_type = 'product';
			}

			$this->pixel->inject_event( 'ViewContent', [
				'content_name' => $product->get_title(),
				'content_ids'  => wp_json_encode( \WC_Facebookcommerce_Utils::get_fb_content_ids( $product ) ),
				'content_type' => $content_type,
				'value'        => $product->get_price(),
				'currency'     => get_woocommerce_currency(),
			] );
		}


		/**
		 * Triggers an AddToCart event when a product is added to cart.
		 *
		 * @see \WC_Facebookcommerce_EventsTracker::inject_ajax_add_to_cart_event() when the product is added to cart via AJAX add to cart button.
		 * @internal
		 *
		 * @param string $cart_item_key the cart item key
		 * @param int $product_id the product identifier
		 * @param int $quantity the added product quantity
		 * @param int $variation_id the product variation identifier
		 */
		public function inject_add_to_cart_event( $cart_item_key, $product_id, $quantity, $variation_id ) {

			// bail if pixel tracking disabled or invalid variables
			if ( ! self::$isEnabled || ! $product_id || ! $quantity ) {
				return;
			}

			$product = wc_get_product( $variation_id ?: $product_id );

			// bail if invalid product or error
			if ( ! $product instanceof \WC_Product ) {
				return;
			}

			$this->pixel->inject_event( 'AddToCart', [
				'content_ids'  => $this->get_cart_content_ids(),
				'content_type' => 'product',
				'contents'     => $this->get_cart_contents(),
				'value'        => $this->get_cart_total(),
				'currency'     => get_woocommerce_currency(),
			] );
		}


		/**
		 * Sends a JSON response with the JavaScript code to track an AddToCart event.
		 *
		 * When a product is added to cart from the shop page or archives and the page does not reload,
		 * the WooCommerce AJAX event alone is not sufficient to trigger an AddToCart event.
		 * Pixel needs to output a JavaScript event on the DOM to track this.
		 * @see \WC_Facebookcommerce_Pixel::pixel_base_code()
		 *
		 * @internal
		 */
		public function inject_ajax_add_to_cart_event() {

			$product_id = isset( $_REQUEST['product_id'] ) ? (int) $_REQUEST['product_id'] : 0;

			// bail if pixel tracking disabled or invalid product identifier
			if ( ! self::$isEnabled || ! $product_id ) {
				die();
			}

			$product = wc_get_product( $product_id );

			// bail if invalid product or error
			if ( ! $product instanceof \WC_Product ) {
				die();
			}

			// addresses a less common occurrence where both options (redirect to cart and shop AJAX are used)
			if ( 'yes' === get_option( 'woocommerce_enable_ajax_add_to_cart' ) && 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ) {
				WC()->session->set( 'facebook_for_woocommerce_last_product_added_to_cart', 0 );
			}

			$fb_pixel       = $this->pixel;
			$fb_pixel_event = $fb_pixel::build_event( 'AddToCart', [
				'content_ids'  => $this->get_cart_content_ids(),
				'content_type' => 'product',
				'contents'     => $this->get_cart_contents(),
				'value'        => $this->get_cart_total(),
				'currency'     => get_woocommerce_currency(),
			] );

			wp_send_json( '<script>' . $fb_pixel_event . '</script>' );
		}


		/**
		 * Sets last product added to cart to session when adding to cart a product and redirection to cart is enabled.
		 *
		 * @internal
		 *
		 * @since x.y.z
		 *
		 * @param string $redirect URL redirecting to (usually cart)
		 * @param \WC_Product $product the product just added to the cart
		 * @return string
		 */
		public function set_last_product_added_to_cart_upon_redirect( $redirect, $product ) {

			if ( $product instanceof \WC_Product ) {
				WC()->session->set( 'facebook_for_woocommerce_last_product_added_to_cart', $product->get_id() );
			}

			return $redirect;
		}


		/**
		 * Triggers an AddToCart event when redirecting to the cart page.
		 *
		 * @internal
		 *
		 * @deprecated since x.y.z
		 */
		public function inject_add_to_cart_redirect_event() {

			if ( ! self::$isEnabled ) {
				return;
			}

			$last_product_id = WC()->session->get( 'facebook_for_woocommerce_last_product_added_to_cart', 0 );

			if ( $last_product_id > 0 ) {

				$this->inject_add_to_cart_event( '', $last_product_id, 1, 0 );

				WC()->session->set( 'facebook_for_woocommerce_last_product_added_to_cart', 0 );
			}
		}


		/**
		 * Triggers InitiateCheckout for checkout page.
		 */
		public function inject_initiate_checkout_event() {

			if ( ! self::$isEnabled || $this->pixel->check_last_event( 'InitiateCheckout' ) ) {
				return;
			}

			$this->pixel->inject_event( 'InitiateCheckout', [
				'num_items'    => $this->get_cart_num_items(),
				'content_ids'  => $this->get_cart_content_ids(),
				'content_type' => 'product',
				'value'        => $this->get_cart_total(),
				'currency'     => get_woocommerce_currency(),
			] );
		}


		/**
		 * Triggers Purchase for payment transaction complete and for the thank you page in cases of delayed payment.
		 *
		 * @param int $order_id order identifier
		 */
		public function inject_purchase_event( $order_id ) {

			if ( ! self::$isEnabled || $this->pixel->check_last_event( 'Purchase' ) ) {
				return;
			}

			$this->inject_subscribe_event( $order_id );

			$order        = new \WC_Order( $order_id );
			$content_type = 'product';
			$product_ids  = [ [] ];

			foreach ( $order->get_items() as $item ) {

				if ( $product = isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null ) {

					$product_ids[] = \WC_Facebookcommerce_Utils::get_fb_content_ids( $product );

					if ( 'product_group' !== $content_type && $product->is_type( 'variable' ) ) {
						$content_type = 'product_group';
					}
				}
			}

			$product_ids = wp_json_encode( array_merge( ... $product_ids ) );

			$this->pixel->inject_event( 'Purchase', [
				'num_items'    => $this->get_cart_num_items(),
				'content_ids'  => $product_ids,
				'content_type' => $content_type,
				'value'        => $order->get_total(),
				'currency'     => get_woocommerce_currency(),
			] );
		}


		/**
		 * Triggers Subscribe for payment transaction complete of purchase with
		 * subscription.
		 */
		public function inject_subscribe_event( $order_id ) {
			if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				return;
			}

			$subscription_ids = wcs_get_subscriptions_for_order( $order_id );
			foreach ( $subscription_ids as $subscription_id ) {
				$subscription = new WC_Subscription( $subscription_id );
				$this->pixel->inject_event(
					'Subscribe',
					array(
						'sign_up_fee' => $subscription->get_sign_up_fee(),
						'value'       => $subscription->get_total(),
						'currency'    => get_woocommerce_currency(),
					)
				);
			}
		}

		/**
		 * Triggers Purchase for thank you page for COD, BACS CHEQUE payment
		 * which won't invoke woocommerce_payment_complete.
		 */
		public function inject_gateway_purchase_event( $order_id ) {
			if ( ! self::$isEnabled ||
			  $this->pixel->check_last_event( 'Purchase' ) ) {
				return;
			}

			$order   = new WC_Order( $order_id );
			$payment = $order->get_payment_method();
			$this->inject_purchase_event( $order_id );
			$this->inject_subscribe_event( $order_id );
		}

		/** Contact Form 7 Support **/
		public function inject_lead_event_hook() {
			add_action( 'wp_footer', array( $this, 'inject_lead_event' ), 11 );
		}

		public function inject_lead_event() {
			if ( ! is_admin() ) {
				$this->pixel->inject_conditional_event(
					'Lead',
					array(),
					'wpcf7submit',
					'{ em: event.detail.inputs.filter(ele => ele.name.includes("email"))[0].value }'
				);
			}
		}


		/**
		 * Gets the cart content items count.
		 *
		 * @since x.y.z
		 *
		 * @return int
		 */
		private function get_cart_num_items() {

			return WC()->cart->get_cart_contents_count();
		}


		/**
		 * Gets all content IDs from cart.
		 *
		 * @since x.y.z
		 *
		 * @return string JSON data
		 */
		private function get_cart_content_ids() {

			$product_ids = [ [] ];

			foreach ( WC()->cart->get_cart() as $item ) {
				if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
					$product_ids[] = \WC_Facebookcommerce_Utils::get_fb_content_ids( $item['data'] );
				}
			}

			return wp_json_encode( array_unique( array_merge( ... $product_ids ) ) );
		}


		/**
		 * Gets the cart content data.
		 *
		 * @since x.y.z
		 *
		 * @return string JSON data
		 */
		private function get_cart_contents() {

			$cart_contents = [];

			foreach ( WC()->cart->get_cart() as $item ) {

				if ( ! isset( $item['data'], $item['quantity'] ) || ! $item['data'] instanceof \WC_Product ) {
					continue;
				}

				$content = new \stdClass();

				$content->id       = \WC_Facebookcommerce_Utils::get_fb_retailer_id( $item['data'] );
				$content->quantity = $item['quantity'];

				$cart_contents[] = $content;
			}

			return wp_json_encode( $cart_contents );
		}


		/**
		 * Gets the cart total.
		 *
		 * @return float|int
		 */
		private function get_cart_total() {

			return WC()->cart->total;
		}


	}

endif;
