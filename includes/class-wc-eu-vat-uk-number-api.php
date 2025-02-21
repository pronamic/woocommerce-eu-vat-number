<?php
/**
 * UK VAT Number API class
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_EU_VAT_UK_Number_API class.
 */
class WC_EU_VAT_UK_Number_API {

	/**
	 * UK VAT Number API URL.
	 *
	 * @var string
	 */
	protected $api_url = 'https://api.service.hmrc.gov.uk/organisations/vat/check-vat-number/lookup/';

	/**
	 * UK VAT Number API Version.
	 *
	 * @var string
	 */
	protected $api_version = '1.0';


	/**
	 * Check VAT Number
	 *
	 * @param string $vat_number UK VAT Number to be validate.
	 *
	 * @return array|WP_Error A WordPress HTTP response.
	 * @throws Exception When remote request fails.
	 */
	public function check_vat_number( $vat_number ) {
		if ( empty( $vat_number ) ) {
			return false;
		}
		$api_url  = $this->api_url . $vat_number;
		$response = wp_remote_get(
			esc_url_raw( $api_url ),
			array(
				'timeout'    => 30,
				'headers'    => array(
					'Accept' => "application/vnd.hmrc.{$this->api_version}+json",
				),
				'user-agent' => 'WooCommerce/' . WC()->version,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( esc_html( $response->get_error_message() ) );
		}

		// Check if VAT number is valid.
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $response_code ) {
			$results = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( isset( $results['target'] ) && isset( $results['target']['vatNumber'] ) ) {
				return true;
			}
		}
		return false;
	}
}
