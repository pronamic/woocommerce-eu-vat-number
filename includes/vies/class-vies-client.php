<?php
/**
 * VIES Client
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/class-vies-response.php';

/**
 * A client for the VIES SOAP web service
 *
 * Based on https://github.com/ddeboer/vatin
 */
class VIES_Client {

	/**
	 * URL to WSDL
	 *
	 * @var string
	 */
	protected $wsdl = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';

	/**
	 * SOAP client
	 *
	 * @var SoapClient
	 */
	protected $soapclient;

	/**
	 * SOAP classmap
	 *
	 * @var array
	 */
	protected $classmap = array(
		'checkVatResponse' => 'VIES_Response',
	);

	/**
	 * Check VAT
	 *
	 * @param string $country_code Country code.
	 * @param string $vat_number   VAT number.
	 *
	 * @return VIES_Response
	 */
	public function check_vat( $country_code, $vat_number ) {
		return $this->get_soap_client()->checkVat(
			array(
				'countryCode' => $country_code,
				'vatNumber'   => $vat_number,
			)
		);
	}

	/**
	 * Get SOAP client
	 *
	 * @return SoapClient
	 */
	public function get_soap_client() {

		/**
		 * Filters SoapClient Parameters.
		 *
		 * @since 2.3.7
		 *
		 * @param array $args SoapClient Parameters.
		 */
		$soap_parameters = apply_filters(
			'woocommerce_eu_vat_number_soap_parameters',
			array(
				'classmap'           => $this->classmap,
				'cache_wsdl'         => WSDL_CACHE_BOTH,
				'connection_timeout' => 45,
				'user_agent'         => 'Mozilla', // the request fails unless a (dummy) user agent is specified.
			)
		);

		if ( null === $this->soapclient ) {
			try {
				$this->soapclient = new SoapClient(
					$this->wsdl,
					$soap_parameters
				);
			} catch ( Exception $e ) {
				return new WP_Error( 'api', __( 'Error communicating with the VAT validation server - please try again.', 'woocommerce-eu-vat-number' ) );
			}
		}

		return $this->soapclient;

	}
}

