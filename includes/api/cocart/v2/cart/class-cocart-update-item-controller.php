<?php
/**
 * CoCart - Update Item controller
 *
 * Handles the request to update items in the cart with /cart/item endpoint.
 *
 * @author  Sébastien Dumont
 * @package CoCart\API\v2
 * @since   3.0.0
 * @version 3.1.0
 * @license GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CoCart REST API v2 - Item controller class.
 *
 * @package CoCart\API
 * @extends CoCart_Cart_V2_Controller
 */
class CoCart_Update_Item_v2_Controller extends CoCart_Cart_V2_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'cart/item';

	/**
	 * Register routes.
	 *
	 * @access public
	 */
	public function register_routes() {
		// Update Item - cocart/v2/cart/item/6364d3f0f495b6ab9dcf8d3b5c6e0b01 (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<item_key>[\w]+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				),
			)
		);
	} // register_routes()

	/**
	 * Update Item in Cart.
	 *
	 * @throws CoCart_Data_Exception Exception if invalid data is detected.
	 *
	 * @access  public
	 * @since   1.0.0
	 * @version 3.1.0
	 * @param   WP_REST_Request $request Full details about the request.
	 * @return  WP_REST_Response
	 */
	public function update_item( $request = array() ) {
		try {
			$item_key = ! isset( $request['item_key'] ) ? 0 : sanitize_text_field( wp_unslash( wc_clean( $request['item_key'] ) ) );
			$quantity = ! isset( $request['quantity'] ) ? 1 : wc_stock_amount( wp_unslash( $request['quantity'] ) );

			$item_key = $this->throw_missing_item_key( $item_key, 'update' );

			// Allows removing of items if quantity is zero should for example the item was with a product bundle.
			if ( 0 == $quantity ) {
				$controller = new CoCart_Remove_Item_v2_Controller();

				return $controller->remove_item( $request );
			}

			$quantity = $this->validate_quantity( $quantity );

			/**
			 * If validation returned an error return error response.
			 *
			 * @param $quantity
			 */
			if ( is_wp_error( $quantity ) ) {
				return $quantity;
			}

			// Check item exists in cart before fetching the cart item data to update.
			$current_data = $this->get_cart_item( $item_key, 'container' );

			// If item does not exist in cart return response.
			if ( empty( $current_data ) ) {
				$message = __( 'Item specified does not exist in cart.', 'cart-rest-api-for-woocommerce' );

				/**
				 * Filters message about cart item key required.
				 *
				 * @since 2.1.0
				 * @param string $message Message.
				 */
				$message = apply_filters( 'cocart_item_not_in_cart_message', $message, 'update' );

				throw new CoCart_Data_Exception( 'cocart_item_not_in_cart', $message, 404 );
			}

			$has_stock = $this->has_enough_stock( $current_data, $quantity ); // Checks if the item has enough stock before updating.

			/**
			 * If not true, return error response.
			 *
			 * @param $has_stock
			 */
			if ( is_wp_error( $has_stock ) ) {
				return $has_stock;
			}

			/**
			 * Update cart validation.
			 *
			 * @since   2.1.0
			 * @version 2.6.2
			 * @param   bool
			 * @param   string $item_key - Item key.
			 * @param   array  $current_data  - Product data of the item in cart.
			 * @param   float  $quantity      - The requested quantity to change to.
			 */
			$passed_validation = apply_filters( 'cocart_update_cart_validation', true, $item_key, $current_data, $quantity );

			/**
			 * If validation returned an error return error response.
			 *
			 * @param $passed_validation
			 */
			if ( is_wp_error( $passed_validation ) ) {
				return $passed_validation;
			}

			// Return error if product is_sold_individually.
			if ( $current_data['data']->is_sold_individually() && $quantity > 1 ) {
				/* translators: %s Product name. */
				$message = sprintf( __( 'You can only have 1 "%s" in your cart.', 'cart-rest-api-for-woocommerce' ), $current_data['data']->get_name() );

				/**
				 * Filters message about product not being allowed to increase quantity.
				 *
				 * @param string     $message      - Message.
				 * @param WC_Product $current_data - Product data.
				 */
				$message = apply_filters( 'cocart_can_not_increase_quantity_message', $message, $current_data['data'] );

				throw new CoCart_Data_Exception( 'cocart_can_not_increase_quantity', $message, 403 );
			}

			// Only update cart item quantity if passed validation.
			if ( $passed_validation ) {
				if ( $this->get_cart_instance()->set_quantity( $item_key, $quantity ) ) {
					$new_data = $this->get_cart_item( $item_key, 'update' );

					$product_id   = ! isset( $new_data['product_id'] ) ? 0 : absint( wp_unslash( $new_data['product_id'] ) );
					$variation_id = ! isset( $new_data['variation_id'] ) ? 0 : absint( wp_unslash( $new_data['variation_id'] ) );

					$product_data = wc_get_product( $variation_id ? $variation_id : $product_id );

					if ( $quantity !== $current_data['quantity'] ) {
						do_action( 'cocart_item_quantity_changed', $item_key, $new_data );

						/**
						 * Calculates the cart totals if an item has changed it's quantity.
						 *
						 * @since 2.1.0 Introduced.
						 * @since 3.1.0 Changed to calculate all totals.
						 */
						$this->calculate_totals();
					}
				} else {
					$message = __( 'Unable to update item quantity in cart.', 'cart-rest-api-for-woocommerce' );

					/**
					 * Filters message about can not update item.
					 *
					 * @since 2.1.0
					 * @param string $message Message.
					 */
					$message = apply_filters( 'cocart_can_not_update_item_message', $message );

					throw new CoCart_Data_Exception( 'cocart_can_not_update_item', $message, array( 'status' => 403 ) );
				}

				$response = $this->get_cart_contents( $request );

				// Was it requested to return status once item updated?
				if ( $request['return_status'] ) {
					$response = array();

					// Return response based on product quantity increment.
					if ( $quantity > $current_data['quantity'] ) {
						$response = array(
							'message'  => sprintf(
								/* translators: 1: product name, 2: new quantity */
								__( 'The quantity for "%1$s" has increased to "%2$s".', 'cart-rest-api-for-woocommerce' ),
								$product_data->get_name(),
								$new_data['quantity']
							),
							'quantity' => $new_data['quantity'],
						);
					} elseif ( $quantity < $current_data['quantity'] ) {
						$response = array(
							'message'  => sprintf(
								/* translators: 1: product name, 2: new quantity */
								__( 'The quantity for "%1$s" has decreased to "%2$s".', 'cart-rest-api-for-woocommerce' ),
								$product_data->get_name(),
								$new_data['quantity']
							),
							'quantity' => $new_data['quantity'],
						);
					} else {
						$response = array(
							'message'  => sprintf(
								/* translators: %s: product name */
								__( 'The quantity for "%s" has not changed.', 'cart-rest-api-for-woocommerce' ),
								$product_data->get_name()
							),
							'quantity' => $quantity,
						);
					}

					$response = apply_filters( 'cocart_update_item', $response, $new_data, $quantity, $product_data );
				}

				return CoCart_Response::get_response( $response, $this->namespace, $this->rest_base );
			}
		} catch ( CoCart_Data_Exception $e ) {
			return CoCart_Response::get_error_response( $e->getErrorCode(), $e->getMessage(), $e->getCode(), $e->getAdditionalData() );
		}
	} // END update_item()

	/**
	 * Get the query params for updating an item.
	 *
	 * @access  public
	 * @since   3.0.0
	 * @version 3.1.0
	 * @return  array $params
	 */
	public function get_collection_params() {
		// Cart query parameters.
		$params = parent::get_collection_params();

		// Update item query parameters.
		$params += array(
			'item_key'      => array(
				'description'       => __( 'Unique identifier for the item in the cart.', 'cart-rest-api-for-woocommerce' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'quantity'      => array(
				'description'       => __( 'Set the quantity you wish to update the item to.', 'cart-rest-api-for-woocommerce' ),
				'default'           => 1,
				'type'              => 'float',
				'validate_callback' => function( $value, $request, $param ) {
					return is_numeric( $value );
				},
			),
			'return_status' => array(
				'description'       => __( 'Returns a message and quantity value after updating item in cart.', 'cart-rest-api-for-woocommerce' ),
				'default'           => false,
				'type'              => 'boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);

		return $params;
	} // END get_collection_params()

} // END class
