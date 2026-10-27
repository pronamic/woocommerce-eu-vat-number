<?php
/**
 * VIES Client
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-vies-response.php';

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
		 * Filters how long a VIES lookup may take, in seconds.
		 *
		 * This bounds both connecting and waiting for the answer. The request holds a PHP
		 * worker for the whole time, so a long timeout means a slow VIES can take the
		 * store's workers with it.
		 *
		 * @since 3.2.2
		 *
		 * @param int $timeout Time in seconds.
		 */
		$timeout = max( 1, (int) apply_filters( 'woocommerce_eu_vat_number_lookup_timeout', 10 ) );

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
				'connection_timeout' => $timeout,
				// connection_timeout only covers connecting. Without this, reading the
				// response falls back to default_socket_timeout, 60 seconds by default.
				'stream_context'     => stream_context_create(
					array(
						'http' => array( 'timeout' => $timeout ),
					)
				),
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
				return new WP_Error( 'wc-eu-vat-api-error', __( 'Error communicating with the VAT validation server - please try again.', 'woocommerce-eu-vat-number' ) );
			}
		}

		return $this->soapclient;
	}
}
