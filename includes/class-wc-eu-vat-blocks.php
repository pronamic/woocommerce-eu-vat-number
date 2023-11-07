<?php
/**
 * Blocks handling.
 *
 * @package woocommerce-eu-vat-number
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_Blocks class.
 */
class WC_EU_VAT_Blocks_Integration implements IntegrationInterface {

	/**
	 * The name of the integration.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'woocommerce-eu-vat-number';
	}

	/**
	 * When called invokes any initialization/setup for the integration.
	 */
	public function initialize() {
		WC_EU_VAT_Number::register_script_with_dependencies( 'wc-blocks-eu-vat-scripts-frontend', 'build/frontend' );
		WC_EU_VAT_Number::register_script_with_dependencies( 'wc-blocks-eu-vat-scripts-index', 'build/index' );

		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'update_order_meta' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'update_order_from_request' ), 10, 2 );
		$this->extend_rest_api();
	}

	/**
	 * When an order is completed, woocommerce_store_api_checkout_update_order_from_request is fired. This action allows
	 * extensions to update the customer's order. In this method, we set the customer's self-delared country and the
	 * country we think they are from based on their IP.
	 *
	 * @param WC_Order        $order The current customer's order object.
	 * @param WP_REST_Request $request The API request currently being processed.
	 * @return void
	 */
	public function update_order_from_request( $order, $request ) {
		if ( false !== WC_EU_VAT_Number::get_ip_country() ) {
			$order->update_meta_data( '_customer_ip_country', WC_EU_VAT_Number::get_ip_country() );
			$order->update_meta_data( '_customer_self_declared_country', ! empty( $request['extensions']['woocommerce-eu-vat-number']['location_confirmation'] ) ? 'true' : 'false' );
		}
	}

	/**
	 * Returns an array of script handles to enqueue in the frontend context.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'wc-blocks-eu-vat-scripts-frontend' );
	}

	/**
	 * Returns an array of script handles to enqueue in the editor context.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array( 'wc-blocks-eu-vat-scripts-frontend', 'wc-blocks-eu-vat-scripts-index' );
	}


	/**
	 * An array of key, value pairs of data made available to the block on the client side.
	 *
	 * @return array
	 */
	public function get_script_data() {
		return array(
			'ip_country'                            => WC_EU_VAT_Number::get_ip_country(),
			'ip_address'                            => apply_filters( 'wc_eu_vat_self_declared_ip_address', WC_Geolocation::get_ip_address() ),
			'woocommerce_eu_vat_number_validate_ip' => get_option( 'woocommerce_eu_vat_number_validate_ip', 'no' ),
		);
	}

	/**
	 * Stores Rest Extending instance.
	 *
	 * @var ExtendRestApi
	 */
	private static $extend;

	/**
	 * Adding order meta data that are part of the plugin of orders created using the
	 * checkout block.
	 *
	 * @param WC_Order $order The order being placed.
	 * @return void
	 */
	public function update_order_meta( $order ) {

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$vat_number = WC()->session->get( 'vat-number' );
		if ( ! $vat_number ) {
			return;
		}

		$data = $this::validate();
		$order->update_meta_data( '_vat_number_is_validated', ! is_null( $data['validation']['valid'] ) ? 'true' : 'false' );
		$order->update_meta_data( '_vat_number_is_valid', true === $data['validation']['valid'] ? 'true' : 'false' );
		$order->update_meta_data( '_billing_vat_number', $vat_number );
		$customer_id = $order->get_customer_id();

		if ( $customer_id ) {
			$customer = new \WC_Customer( $customer_id );
			$customer->update_meta_data( 'vat_number', $vat_number );
			$customer->save_meta_data();
		}

		if ( false !== WC_EU_VAT_Number::get_ip_country() ) {
			$order->update_meta_data( '_customer_ip_country', WC_EU_VAT_Number::get_ip_country() );
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

		$extend->register_update_callback(
			array(
				'namespace' => 'woocommerce-eu-vat-number',
				'callback'  => function( $data ) {
					if ( isset( $data['vat_number'] ) ) {
						if ( empty( $data['vat_number'] ) ) {
							WC()->session->set( 'vat-number', null );
							WC()->customer->set_is_vat_exempt( false );
						} else {
							WC()->session->set( 'vat-number', strtoupper( $data['vat_number'] ) );
							$this->maybe_apply_exemption( false );
						}
					} else {
						WC()->session->set( 'vat-number', null );
						WC()->customer->set_is_vat_exempt( false );
					}
				},
			)
		);

		/**
		 * A checkout endpoint extension to inform our frontend component about the result of
		 * the validity of the VAT number and react accordingly.
		 */
		$extend->register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => 'woocommerce-eu-vat-number',
				'data_callback'   => array( $this, 'vat_number_information' ),
				'schema_callback' => array( $this, 'schema_for_vat_number_information' ),
				'schema_type'     => ARRAY_A,
			)
		);

		/**
		 * A checkout endpoint to accept the location_confirmation key.
		 */
		$extend->register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => 'woocommerce-eu-vat-number',
				'schema_callback' => function() {
					return array(
						'location_confirmation' => array(
							'description' => __( 'Location confirmation.', 'woocommerce-eu-vat-number' ),
							'type'        => 'boolean',
							'context'     => array(),
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Information about the status of the given VAT Number.
	 *
	 * @return Array Information about the validity of the VAT Number.
	 */
	public function vat_number_information() {
		return array_merge( $this::validate(), array( 'cart_has_digital_goods' => WC_EU_VAT_Number::cart_has_digital_goods() ) );
	}

	/**
	 * Checks if VAT number is formatted correctly.
	 *
	 * @return Array Information about the result of the validation.
	 */
	public function validate() {
		$data         = array();
		$vat_number   = WC()->session->get( 'vat-number' );
		$country      = WC()->customer->get_billing_country();
		$postcode     = WC()->customer->get_billing_postcode();
		$fail_handler = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );
		$valid        = WC_EU_VAT_Number::vat_number_is_valid( $vat_number, $country, $postcode );

		if ( is_wp_error( $valid ) ) {
			$data['vat_number'] = $vat_number;
			$data['validation'] = array(
				'valid' => false,
				'error' => $valid->get_error_message(),
			);
			return $data;
		}

		$vat_number_formatted = WC_EU_VAT_Number::get_formatted_vat_number( $vat_number );
		$data['vat_number']   = $valid ? WC_EU_VAT_Number::get_vat_number_prefix( $country ) . $vat_number_formatted : $vat_number;
		$data['validation']   = array(
			'valid' => $valid,
			'error' => false,
		);

		if ( 'reject' === $fail_handler && ( ! $valid || ! $vat_number ) ) {
			$data['validation']['error'] = ! $valid ? __( 'Invalid VAT number.', 'woocommerce-eu-vat-number' ) : false;
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

		$vat_number = WC()->session->get( 'vat-number' );

		if ( ! $vat_number ) {
			return;
		}

		$validation   = $this::validate();
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

		$this->set_vat_exemption( $validation );
	}

	/**
	 * Tries to apply the exemption given the information.
	 *
	 * @param  mixed $validation Result of the validation of the VAT Number.
	 * @return void
	 */
	private function set_vat_exemption( $validation ) {

		$fail_handler = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );
		$b_country    = WC()->customer->get_billing_country();
		$s_country    = WC()->customer->get_shipping_country();

		if ( true === (bool) $validation['validation']['valid'] ) {
			WC_EU_VAT_Number::maybe_set_vat_exempt( true, $b_country, $s_country );
		} else {
			switch ( $fail_handler ) {
				case 'accept_with_vat':
					WC_EU_VAT_Number::maybe_set_vat_exempt( false, $b_country, $s_country );
					break;
				case 'accept':
					WC_EU_VAT_Number::maybe_set_vat_exempt( true, $b_country, $s_country );
					break;
				default:
					WC_EU_VAT_Number::maybe_set_vat_exempt( false, $b_country, $s_country );
					break;
			}
		}
	}

	/**
	 * Schema for the information about the VAT Number.
	 *
	 * @return Array Information about this vat number.
	 */
	public function schema_for_vat_number_information() {
		return array(
			'vat_data'        => array(
				'description' => __( 'VAT Data', 'woocommerce-eu-vat-number' ),
				'type'        => 'array',
				'readonly'    => true,
			),
		);
	}
}
