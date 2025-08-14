<?php
/**
 * WooCommerce EU VAT Number Extend Store API.
 *
 * A class to extend the store public API with EU VAT Number related data.
 *
 * @package woocommerce-eu-vat-number
 * @since 2.8.9
 */

use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;

/**
 * WC_EU_VAT_Extend_Store_Endpoint class.
 * Extends the store API with EU VAT Number related data.
 */
class WC_EU_VAT_Extend_Store_Endpoint {
	/**
	 * Plugin Identifier, unique to each plugin.
	 *
	 * @var string
	 */
	const IDENTIFIER = 'woocommerce-eu-vat-number';

	/**
	 * Initialization.
	 */
	public function init() {
		// Extend StoreAPI.
		$this->extend_rest_api();

		// Handle Checkout.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'update_order_meta' ), 10, 2 );
	}

	/**
	 * Adding order meta data that are part of the plugin of orders created using the
	 * checkout block.
	 *
	 * @param WC_Order        $order   The order being placed.
	 * @param WP_REST_Request $request The API request currently being processed.
	 * @return void
	 */
	public function update_order_meta( $order, $request ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$billing_address = $request->get_param( 'billing_address' );
		$country         = wc_clean( $billing_address['country'] );
		$postcode        = wc_clean( $billing_address['postcode'] );

		if ( WC()->customer->has_shipping_address() && wc_eu_vat_use_shipping_country() ) {
			$country  = WC()->customer->get_shipping_country() ?? $country;
			$postcode = WC()->customer->get_shipping_postcode() ?? '';
		}

		if ( ! empty( $request['extensions']['woocommerce-eu-vat-number']['vat_number'] ) ) {
			$vat_number   = $request['extensions']['woocommerce-eu-vat-number']['vat_number'];
			$is_valid     = WC_EU_VAT_Number::vat_number_is_valid( $vat_number, $country, $postcode );
			$fail_handler = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );

			if ( 'reject' === $fail_handler && ( is_wp_error( $is_valid ) || false === $is_valid ) ) {
				wc_add_notice( __( 'Invalid VAT number.', 'woocommerce-eu-vat-number' ), 'error' );
			}
		}

		$customer_id = $order->get_customer_id();

		if ( $customer_id ) {
			$customer = new \WC_Customer( $customer_id );
			$customer->update_meta_data( 'vat_number', $vat_number );
			$customer->save_meta_data();
		}

		if ( ! $vat_number ) {
			return;
		}

		$data = $this->validate( $vat_number );
		$order->update_meta_data( '_vat_number_is_validated', ! is_null( $data['validation']['valid'] ) ? 'true' : 'false' );
		$order->update_meta_data( '_vat_number_is_valid', true === $data['validation']['valid'] ? 'true' : 'false' );
		$order->update_meta_data( '_billing_vat_number', $vat_number );

		// We set the customer's self-delared country and the country we think they are from based on their IP.
		if ( false !== WC_EU_VAT_Number::get_ip_country() ) {
			$order->update_meta_data( '_customer_ip_country', WC_EU_VAT_Number::get_ip_country() );
			$order->update_meta_data( '_customer_self_declared_country', ! empty( $request['extensions']['woocommerce-eu-vat-number']['location_confirmation'] ) ? 'true' : 'false' );
		}

		$this->maybe_apply_exemption();
	}

	/**
	 * Registers extensions to two rest API endpoints:
	 * 1 - a cart update endpoint to get the VAT number after it has been typed and check for
	 * vat excepmption while on the checkout form.
	 * 2 - a checkout endpoint extension to inform our frontend component about the result of
	 * the validity of the VAT number and react accordingly.
	 *
	 * @return void
	 */
	public function extend_rest_api() {
		/**
		 * A cart update endpoint to get the VAT number after it has been typed and check for
		 * vat exemption while on the checkout form.
		 */
		$extend = StoreApi::container()->get( ExtendSchema::class );

		$extend->register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => self::IDENTIFIER,
				'schema_callback' => function () {
					return array(
						'location_confirmation' => array(
							'description' => __( 'Location confirmation.', 'woocommerce-eu-vat-number' ),
							'type'        => 'boolean',
							'context'     => array(),
						),
						'vat_number'            => array(
							'description' => __( 'VAT number.', 'woocommerce-eu-vat-number' ),
							'type'        => array( 'string', 'null' ),
							'context'     => array(),
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);

		$extend->register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::IDENTIFIER,
				'schema_callback' => function () {
					return array(
						'vat_data' => array(
							'description' => __( 'VAT Data', 'woocommerce-eu-vat-number' ),
							'type'        => 'array',
							'readonly'    => true,
						),
					);
				},
				'data_callback'   => array( $this, 'get_cart_data' ),
				'schema_type'     => ARRAY_A,
			)
		);

		$extend->register_update_callback(
			array(
				'namespace' => self::IDENTIFIER,
				'callback'  => array( $this, 'set_cart_data' ),
			)
		);
	}

	/**
	 * Returns data to be used in the cart.
	 *
	 * @return array
	 */
	public function get_cart_data() {
		$vat_number  = WC()->session->get( 'vat_number' );
		$customer_id = get_current_user_id();

		if ( $customer_id && empty( $vat_number ) ) {
			$vat_number = get_user_meta( $customer_id, 'vat_number', true );
		}

		$validation_result = $this->validate( $vat_number );
		$vat_number        = $validation_result['vat_number'] ?? $vat_number;

		return array(
			'vat_data' => array(
				'number'                 => $vat_number,
				'is_valid'               => $validation_result['validation']['valid'] ?? false,
				'error'                  => $validation_result['validation']['error'] ?? '',
				'is_required'            => get_option( 'woocommerce_eu_vat_number_b2b', 'no' ),
				'accept_if_invalid'      => 'reject' !== get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' ),
				'cart_has_digital_goods' => WC_EU_VAT_Number::cart_has_digital_goods(),
			),
		);
	}

	/**
	 * Sets the data for the cart and applies the VAT exemption if needed.
	 *
	 * @param array $data Extension data received when the cart is updated.
	 */
	public function set_cart_data( $data ) {
		if ( ! isset( $data['vat_number'] ) ) {
			return;
		}

		$vat_number = $data['vat_number'];

		/**
		 * If VAT is empty:
		 * - Remove VAT from session.
		 * - Set VAT exemption to false.
		 */
		if ( empty( $vat_number ) ) {
			WC()->session->set( 'vat_number', null );
			WC()->customer->set_is_vat_exempt( false );

			return;
		}

		WC()->session->set( 'wc_eu_vat_current_country', $data['needs_shipping'] && $data['use_shipping_country'] ? $data['shipping_country'] : $data['billing_country'] );

		$validation_result = $this->validate( $vat_number );
		$vat_number        = $validation_result['vat_number'] ?? $vat_number;

		$this->maybe_set_vat_exemption( $validation_result );

		/**
		 * We'll save the VAT number in the session if valid so
		 * that it is available to the customer on page refresh.
		 */
		WC()->session->set( 'vat_number', $vat_number );
	}

	/**
	 * Checks if VAT number is formatted correctly.
	 *
	 * @param string|null $vat_number VAT number.
	 * @return array Information about the result of the validation.
	 */
	public function validate( $vat_number = null ) {
		$vat_number = ! is_null( $vat_number ) ? $vat_number : WC()->session->get( 'vat_number' );

		if ( ! $vat_number ) {
			return array(
				'vat_number' => '',
				'validation' => array(
					'valid' => null,
					'error' => false,
				),
			);
		}

		$country = WC()->session->get( 'wc_eu_vat_current_country' );

		if ( ! $country ) {
			$billing_country  = WC()->customer->get_billing_country();
			$shipping_country = WC()->customer->get_shipping_country() ? WC()->customer->get_shipping_country() : $billing_country;
			$country          = $billing_country;

			$use_shipping_country = wc_eu_vat_use_shipping_country();
			$needs_shipping       = WC()->cart ? WC()->cart->needs_shipping_address() : false;

			if ( $use_shipping_country && $needs_shipping ) {
				$country = $shipping_country;
			}
		}

		$fail_handler    = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );
		$is_format_valid = WC_EU_VAT_Number::validate_vat_format( $vat_number );

		if ( is_wp_error( $is_format_valid ) ) {
			$data['vat_number'] = $vat_number;
			$data['validation'] = array(
				'valid' => false,
				'error' => $is_format_valid->get_error_message(),
				'code'  => $is_format_valid->get_error_code(),
			);
			WC()->session->set( 'vat_number', null );
			return $data;
		}

		$is_registered_valid = WC_EU_VAT_Number::vat_number_is_valid( $vat_number, $country );

		if ( is_wp_error( $is_registered_valid ) ) {
			$data['vat_number'] = $vat_number;
			$data['validation'] = array(
				'valid' => false,
				'error' => $is_registered_valid->get_error_message(),
				'code'  => $is_registered_valid->get_error_code(),
			);

			return $data;
		}

		$vat_number_formatted = WC_EU_VAT_Number::get_formatted_vat_number( $vat_number );

		$data['vat_number'] = $is_registered_valid ? WC_EU_VAT_Number::get_vat_number_prefix( $country ) . $vat_number_formatted : $vat_number;
		$data['validation'] = array(
			'valid' => $is_registered_valid,
			'error' => false,
		);

		$use_shipping_country = wc_eu_vat_use_shipping_country();

		if ( ! $is_registered_valid ) {
			if ( 'reject' === $fail_handler ) {
				$data['validation']['error'] = sprintf(
					/* translators: 1: VAT number field label, 2: VAT Number, 3: Address type, 4: Country */
					__( 'You have entered an invalid %1$s (%2$s) for your %3$s country (%4$s).', 'woocommerce-eu-vat-number' ),
					get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ),
					$vat_number,
					$use_shipping_country ? __( 'shipping', 'woocommerce-eu-vat-number' ) : __( 'billing', 'woocommerce-eu-vat-number' ),
					$country
				);
			} else {
				$data['validation']['error'] = false;
			}
		}

		return $data;
	}

	/**
	 * Validates VAT Number and tries to apply the exemption given the information.
	 *
	 * @param boolean $with_notices Indicates whether to add notices or just run without any feedback.
	 * This is used while chaning the field on the checkout for and when submitting the order, hence
	 * the two separate use cases.
	 *
	 * @return void
	 */
	public function maybe_apply_exemption( $with_notices = true ) {
		$validation   = $this->validate();
		$fail_handler = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );

		if ( false === (bool) $validation['validation']['valid'] && $with_notices ) {
			switch ( $fail_handler ) {
				case 'accept_with_vat':
					wc_add_notice( $validation['validation']['error'], 'error' );
					break;
				case 'accept':
					break;
				default:
					wc_add_notice( $validation['validation']['error'], 'error' );
					break;
			}
		}

		$this->maybe_set_vat_exemption( $validation );
	}

	/**
	 * Tries to apply the exemption given the information.
	 *
	 * @param  mixed $validation Result of the validation of the VAT Number.
	 */
	private function maybe_set_vat_exemption( $validation ) {
		$fail_handler = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );
		$vat_number   = $validation['vat_number'] ?? '';

		if ( true === (bool) $validation['validation']['valid'] ) {
			WC_EU_VAT_Number::maybe_apply_vat_exemption( $vat_number, true );
		} else {
			switch ( $fail_handler ) {
				case 'accept_with_vat':
					WC_EU_VAT_Number::maybe_apply_vat_exemption( $vat_number, false );
					break;
				case 'accept':
					$error_code = $validation['validation']['code'] ?? false;

					if ( 'wc-eu-vat-api-error' !== $error_code ) {
						WC_EU_VAT_Number::maybe_apply_vat_exemption( $vat_number, true );
					}
					break;
				default:
					WC_EU_VAT_Number::maybe_apply_vat_exemption( $vat_number, false );
					break;
			}
		}
	}
}
