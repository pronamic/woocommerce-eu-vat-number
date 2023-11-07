<?php
/**
 * List of countries to consider under EU.
 *
 * @package woocommerce-eu-vat-number
 * @since 1.0.0
 * @return void
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the EU Country codes.
 *
 * @since 2.3.6
 *
 * @param array $country_codes Country codes.
 */
return apply_filters(
	'woocommerce_eu_vat_number_country_codes',
	array(
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'ES',
		'FI',
		'FR',
		'GB',
		'GR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
		'MC',
		'IM',
	)
);
