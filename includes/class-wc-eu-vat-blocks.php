<?php
/**
 * Blocks handling.
 *
 * @package woocommerce-eu-vat-number
 */

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

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
		WC_EU_VAT_Number::register_script_with_dependencies( 'wc-blocks-eu-vat-scripts-index', 'build/blocks' );
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
}
