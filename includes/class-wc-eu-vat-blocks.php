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
		$scripts_to_register = array(
			'wc-blocks-eu-vat-scripts-frontend' => 'build/frontend',
			'wc-blocks-eu-vat-scripts-index'    => 'build/blocks',
		);

		foreach ( $scripts_to_register as $handler => $script ) {
			try {
				WC_EU_VAT_Number::register_script_with_dependencies( $handler, $script );
			} catch ( Exception $e ) {
				// Show admin notice if we're in the admin area.
				if ( is_admin() ) {
					$this->show_admin_notice_for_script_registration( $handler, $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Show admin notice for script registration errors.
	 *
	 * @param string $handler Script handler that failed to register.
	 * @param string $error_message The error message.
	 */
	private function show_admin_notice_for_script_registration( $handler, $error_message ) {
		add_action(
			'admin_notices',
			function () use ( $handler, $error_message ) {
				echo '<div class="notice notice-error"><p>';
				printf(
				/* translators: 1: Script handler, 2: Error message */
					esc_html__( 'WC EU VAT Number: Failed to register script %1$s: %2$s', 'woocommerce-eu-vat-number' ),
					esc_html( $handler ),
					esc_html( $error_message )
				);
				echo '</p></div>';
			}
		);
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
