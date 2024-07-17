<?php
/**
 * EU VAT Number Plugin class
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vies/class-vies-client.php';
require_once __DIR__ . '/class-wc-eu-vat-uk-number-api.php';

/**
 * WC_EU_VAT_Number class.
 */
class WC_EU_VAT_Number {

	/**
	 * Stores an array of EU country codes.
	 *
	 * @var array
	 */
	private static $eu_countries = array();

	/**
	 * Stores an array of RegEx patterns for country codes.
	 *
	 * @var array
	 */
	private static $country_codes_patterns = array(
		'AT' => 'U[A-Z\d]{8}',
		'BE' => '0\d{9}',
		'BG' => '\d{9,10}',
		'CY' => '\d{8}[A-Z]',
		'CZ' => '\d{8,10}',
		'DE' => '\d{9}',
		'DK' => '(\d{2} ?){3}\d{2}',
		'EE' => '\d{9}',
		'EL' => '\d{9}',
		'ES' => '[A-Z]\d{7}[A-Z]|\d{8}[A-Z]|[A-Z]\d{8}',
		'FI' => '\d{8}',
		'FR' => '([A-Z]{2}|[A-Z0-9]{2})\d{9}',
		'GB' => '\d{9}|\d{12}|(GD|HA)\d{3}',
		'XI' => '\d{9}|\d{12}|(GD|HA)\d{3}',
		'HR' => '\d{11}',
		'HU' => '\d{8}',
		'IE' => '[A-Z\d]{8,10}',
		'IT' => '\d{11}',
		'LT' => '(\d{9}|\d{12})',
		'LU' => '\d{8}',
		'LV' => '\d{11}',
		'MT' => '\d{8}',
		'NL' => '\d{9}B\d{2}',
		'PL' => '\d{10}',
		'PT' => '\d{9}',
		'RO' => '\d{2,10}',
		'SE' => '\d{12}',
		'SI' => '\d{8}',
		'SK' => '\d{10}',
	);

	/**
	 * VAT Number data.
	 *
	 * @var array
	 */
	private static $data = array(
		'vat_number' => false,
		'validation' => array(
			'valid' => null,
			'error' => false,
		),
	);

	/**
	 * Stores the current IP Address' country code after geolocation.
	 *
	 * @var boolean
	 */
	private static $ip_country = false;

	/**
	 * Init.
	 */
	public static function init() {
		// Add fields to checkout process.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'load_scripts' ) );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'vat_number_field' ) );
		if ( wc_eu_vat_use_shipping_country() ) {
			add_filter( 'woocommerce_shipping_fields', array( __CLASS__, 'shipping_vat_number_field' ) );
		}
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'process_checkout' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'ajax_update_checkout_totals' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'location_confirmation' ) );
		add_action( 'woocommerce_deposits_after_scheduled_order_props_set', array( __CLASS__, 'set_vat_details_for_scheduled_orders' ), 10, 2 );

		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'set_order_data' ) );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'set_customer_data' ) );
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'set_refund_data' ) );

		// Add VAT to addresses.
		add_filter( 'woocommerce_order_formatted_billing_address', array( __CLASS__, 'formatted_billing_address' ), 10, 2 );
		add_filter( 'woocommerce_order_formatted_shipping_address', array( __CLASS__, 'formatted_shipping_address' ), 10, 2 );
		add_filter( 'woocommerce_formatted_address_replacements', array( __CLASS__, 'output_company_vat_number' ), 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', array( __CLASS__, 'localisation_address_formats' ), 10, 2 );

		// Digital goods taxable location.
		add_filter( 'woocommerce_get_tax_location', array( __CLASS__, 'woocommerce_get_tax_location' ), 10, 2 );

		// Add VAT Number in order endpoint (REST API).
		add_filter( 'woocommerce_api_order_response', array( __CLASS__, 'add_vat_number_to_order_response' ) );
		add_filter( 'woocommerce_rest_prepare_shop_order', array( __CLASS__, 'add_vat_number_to_order_response' ) );
	}

	/**
	 * Load scripts used on the checkout.
	 */
	public static function load_scripts() {
		if ( is_checkout() ) {
			$asset_file = require_once WC_EU_ABSPATH . '/build/eu-vat.asset.php';

			if ( ! is_array( $asset_file ) ) {
				return;
			}

			wp_enqueue_script( 'wc-eu-vat', WC_EU_VAT_PLUGIN_URL . '/build/eu-vat.js', array( 'jquery', 'wc-checkout' ), $asset_file['version'], true );
			self::localize_wc_eu_vat_params( 'wc-eu-vat' );
		}
	}

	/**
	 * Localise data for the `wc_eu_vat_params` script.
	 *
	 * @param string $script_handle Script handle.
	 */
	public static function localize_wc_eu_vat_params( $script_handle ) {
			wp_localize_script(
				$script_handle,
				'wc_eu_vat_params',
				array(
					'eu_countries'         => self::get_eu_countries(),
					'b2b_required'         => get_option( 'woocommerce_eu_vat_number_b2b', 'false' ),
					'input_label'          => get_option( 'woocommerce_eu_vat_number_field_label', 'VAT number' ),
					'input_description'    => get_option( 'woocommerce_eu_vat_number_field_description', '' ),
					'failure_handler'      => get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' ),
					'use_shipping_country' => wc_eu_vat_use_shipping_country(),
				)
			);
	}

	/**
	 * Get EU Country codes.
	 *
	 * @return array
	 */
	public static function get_eu_countries() {
		if ( empty( self::$eu_countries ) ) {
			self::$eu_countries = include 'data/eu-country-codes.php';
		}
		return self::$eu_countries;
	}

	/**
	 * Reset number.
	 */
	public static function reset() {
		WC()->customer->set_is_vat_exempt( false );
		self::$data = array(
			'vat_number' => false,
			'validation' => array(
				'valid' => null,
				'error' => false,
			),
		);
	}

	/**
	 * Add VAT Number field wrapper on the shipping fields.
	 *
	 * @since 2.9.4
	 *
	 * @param array $fields Shipping Fields.
	 * @return array
	 */
	public static function shipping_vat_number_field( $fields ) {
		$user_id = get_current_user_id();

		// If on edit address page, unset vat number field.
		if ( is_wc_endpoint_url( 'edit-address' ) ) {
			if ( isset( $fields['shipping_vat_number'] ) ) {
				unset( $fields['shipping_vat_number'] );
			}
			return $fields;
		}

		$fields['shipping_vat_number'] = array(
			'label'       => get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ),
			'default'     => $user_id > 0 ? get_user_meta( $user_id, 'vat_number', true ) : '',
			'required'    => false,
			'class'       => array(
				'form-row-wide',
			),
			'description' => get_option( 'woocommerce_eu_vat_number_field_description', '' ),
			'id'          => 'woocommerce_eu_vat_number_shipping',
			'priority'    => 120,
		);

		return $fields;
	}

	/**
	 * Show the VAT field on the checkout.
	 *
	 * @since 1.0.0
	 * @version 2.3.1
	 * @param array $fields Billing Fields.
	 * @return array
	 */
	public static function vat_number_field( $fields ) {
		$b2b_vat_enabled = get_option( 'woocommerce_eu_vat_number_b2b', 'no' );
		$user_id         = get_current_user_id();

		// If on edit address page, unset vat number field.
		if ( is_wc_endpoint_url( 'edit-address' ) ) {
			if ( isset( $fields['billing_vat_number'] ) ) {
				unset( $fields['billing_vat_number'] );
			}
			return $fields;
		}

		$fields['billing_vat_number'] = array(
			'label'       => get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ),
			'default'     => $user_id > 0 ? get_user_meta( $user_id, 'vat_number', true ) : '',
			'required'    => false,
			'class'       => array(
				'form-row-wide',
			),
			'description' => get_option( 'woocommerce_eu_vat_number_field_description', '' ),
			'id'          => 'woocommerce_eu_vat_number',
			'priority'    => 120,
		);

		return $fields;
	}

	/**
	 * Return the vat number prefix.
	 *
	 * @param  string $country Country Code.
	 * @return string
	 */
	public static function get_vat_number_prefix( $country ) {
		switch ( $country ) {
			case 'GR':
				$vat_prefix = 'EL';
				break;
			case 'MC':
				$vat_prefix = 'FR';
				break;
			case 'IM':
				$vat_prefix = 'GB';
				break;
			default:
				$vat_prefix = $country;
				break;
		}
		return $vat_prefix;
	}

	/**
	 * Remove unwanted chars and the prefix from a VAT number.
	 *
	 * @param  string $vat VAT Number.
	 * @return string
	 */
	public static function get_formatted_vat_number( $vat ) {
		$vat = strtoupper( str_replace( array( ' ', '.', '-', ',', ', ' ), '', $vat ) );

		if ( in_array( substr( $vat, 0, 2 ), array_merge( self::get_eu_countries(), array( 'EL', 'XI' ) ), true ) ) {
			$vat = substr( $vat, 2 );
		}

		return $vat;
	}

	/**
	 * Get IP address country for user.
	 *
	 * @return string
	 */
	public static function get_ip_country() {
		if ( false === self::$ip_country ) {
			$geoip            = WC_Geolocation::geolocate_ip();
			self::$ip_country = $geoip['country'];
		}
		return self::$ip_country;
	}

	/**
	 * Validate a number.
	 *
	 * @param  string $vat_number VAT Number.
	 * @param  string $country    CountryCode.
	 * @param  string $postcode   Postcode.
	 * @return bool|WP_Error if valid/not valid, WP_ERROR if validation failed
	 */
	public static function vat_number_is_valid( $vat_number, $country, $postcode = '' ) {
		// The StoreAPI will set $vat_number to null if the user does not enter it. We should show an error in this case.
		if ( null === $vat_number ) {
			return new WP_Error( 'api', __( 'VAT number is required.', 'woocommerce-eu-vat-number' ) );
		}

		// Replace unwanted chars on VAT Number.
		$vat_number = $vat_number ? $vat_number : '';
		$vat_number = strtoupper( str_replace( array( ' ', '.', '-', ',', ', ' ), '', $vat_number ) );

		$vat_prefix           = self::get_vat_number_prefix( $country );
		$vat_number_formatted = self::get_formatted_vat_number( $vat_number );
		$transient_name       = 'vat_number_' . $vat_prefix . $vat_number_formatted;
		$cached_result        = get_transient( $transient_name );

		// Keep supporting prefix 'XI' for Northern Ireland.
		if ( 'GB' === $country && ! empty( $postcode ) && preg_match( '/^(bt).*$/i', $postcode ) && 'XI' === substr( $vat_number, 0, 2 ) ) {
			$vat_prefix = 'XI';
		}

		// Return error if VAT Country Code doesn't match or exist.
		if ( ! isset( self::$country_codes_patterns[ $vat_prefix ] ) || ( $vat_prefix . $vat_number_formatted !== $vat_number ) ) {
			// translators: %1$s - VAT number field label, %2$s - VAT Number from user, %3$s - Billing country.
			return new WP_Error( 'api', sprintf( __( 'You have entered an invalid country code for %1$s (%2$s) for your country (%3$s).', 'woocommerce-eu-vat-number' ), get_option( 'woocommerce_eu_vat_number_field_label', 'VAT number' ), $vat_number, $country ) );
		}

		if ( ! empty( $cached_result ) ) {
			return 'yes' === $cached_result;
		}

		$is_valid = false;
		if ( in_array( $country, array( 'GB', 'IM' ), true ) ) {
			// For United Kingdom (UK) (Isle of Man included) check VAT number with UK VAT Number API.
			try {
				$uk_vat_api = new WC_EU_VAT_UK_Number_API();
				$is_valid   = $uk_vat_api->check_vat_number( $vat_number_formatted );
			} catch ( Exception $e ) {
				return new WP_Error( 'api', __( 'Error communicating with the VAT validation server - please try again.', 'woocommerce-eu-vat-number' ) );
			}
		} else {
			// Check rest of EU countries with VIES.
			$vies        = new VIES_Client();
			$soap_client = $vies->get_soap_client();

			// Return error if any error occurs in getting the SOAP client.
			if ( is_wp_error( $soap_client ) ) {
				return $soap_client;
			}

			if ( $soap_client ) {
				try {
					$vies_req = $vies->check_vat( $vat_prefix, $vat_number_formatted );
					$is_valid = $vies_req->is_valid();

				} catch ( SoapFault $e ) {
					return new WP_Error( 'api', __( 'Error communicating with the VAT validation server - please try again.', 'woocommerce-eu-vat-number' ) );
				}
			}
		}

		/**
		 * Filter whether the VAT number is valid or not.
		 *
		 * @since 2.4.2
		 * @hook woocommerce_eu_vat_number_is_valid
		 *
		 * @param {boolean} $is_valid    Whether the VAT number is valid or not.
		 * @param {string}  $vat_number  VAT number.
		 * @param {string}  $country     Country.
		 *
		 * @return {boolean}
		 */
		$is_valid = apply_filters( 'woocommerce_eu_vat_number_is_valid', $is_valid, $vat_number, $country );

		set_transient( $transient_name, $is_valid ? 'yes' : 'no', DAY_IN_SECONDS );
		return $is_valid;
	}

	/**
	 * Returns array of country code patterns.
	 *
	 * @since 2.9.0
	 *
	 * @return array
	 */
	public static function get_country_code_patterns() {
		return self::$country_codes_patterns;
	}

	/**
	 * Validate a number and store the result.
	 *
	 * @param string $vat_number VAT Number.
	 * @param string $country    Billing CountryCode.
	 * @param string $postcode   Billing PostCode.
	 * @return void
	 */
	public static function validate( $vat_number, $country, $postcode = '' ) {
		$valid                = self::vat_number_is_valid( $vat_number, $country, $postcode );
		$vat_number_formatted = self::get_formatted_vat_number( $vat_number );

		if ( is_wp_error( $valid ) ) {
			self::$data['vat_number'] = $vat_number;
			self::$data['validation'] = array(
				'valid' => null,
				'error' => $valid->get_error_message(),
			);
		} else {
			self::$data['vat_number'] = $valid ? self::get_vat_number_prefix( $country ) . $vat_number_formatted : $vat_number;
			self::$data['validation'] = array(
				'valid' => $valid,
				'error' => false,
			);
		}
	}

	/**
	 * Whether the base country match with the billing/shipping country.
	 *
	 * @param string $billing_country Billing country of customer.
	 * @param string $shipping_country Shipping country of customer.
	 * @return bool
	 */
	public static function is_base_country_match( $billing_country, $shipping_country ) {
		/*
		* Special handling needs to be done
		* for Isle of Man. Technically Isle of Man
		* is separate from UK however in the context
		* of VAT, it is considered within UK.
		* Ref: https://www.gov.im/categories/tax-vat-and-your-money/customs-and-excise/international-trade-and-the-isle-of-man-requirements-and-standards/
		*/
		$base_country       = WC()->countries->get_base_country();
		$tax_based_on       = get_option( 'woocommerce_tax_based_on', 'billing' );
		$base_country_is_uk = in_array( $base_country, array( 'GB', 'IM' ), true );

		if ( 'billing' === $tax_based_on ) {
			if ( $base_country_is_uk && in_array( $billing_country, array( 'GB', 'IM' ), true ) ) {
				return true;
			}
			return ( $base_country === $billing_country );
		} elseif ( 'shipping' === $tax_based_on ) {
			if ( $base_country_is_uk && in_array( $shipping_country, array( 'GB', 'IM' ), true ) ) {
				return true;
			}
			return ( $base_country === $shipping_country );
		}

		return in_array( $base_country, array( $billing_country, $shipping_country ), true );
	}

	/**
	 * Set tax exception based on countries.
	 *
	 * @param bool   $exempt Are they exempt?.
	 * @param string $billing_country Billing country of customer.
	 * @param string $shipping_country Shipping country of customer.
	 */
	public static function maybe_set_vat_exempt( $exempt, $billing_country, $shipping_country ) {
		$base_country_match = self::is_base_country_match( $billing_country, $shipping_country );

		if ( ( $base_country_match && 'yes' === get_option( 'woocommerce_eu_vat_number_deduct_in_base', 'yes' ) ) || ! $base_country_match ) {
			/**
			 * Filters the VAT exception.
			 *
			 * @since 2.3.6
			 *
			 * @param bool   $exempt             Are they exempt?.
			 * @param bool   $base_country_match Is Base coutry match?.
			 * @param string $billing_country    Billing country of customer.
			 * @param string $shipping_country   Shipping country of customer.
			 */
			$exempt = apply_filters( 'woocommerce_eu_vat_number_set_is_vat_exempt', $exempt, $base_country_match, $billing_country, $shipping_country );
			WC()->customer->set_is_vat_exempt( $exempt );
		}
	}

	/**
	 * Validate the VAT number when the checkout form is processed.
	 *
	 * For B2C transactions, validate the IP only if this is a digital order.
	 */
	public static function process_checkout() {
		self::reset();

		self::validate_checkout( $_POST, true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * See if we need the user to self-declare location.
	 *
	 * This is needed when:
	 *      The IP country cannot be detected
	 *      The IP country is inside the EU OR
	 *      The Billing country is inside the EU AND
	 *      The IP doesn't match the billing country.
	 *
	 * @param string $ip_country      IP Country of customer.
	 * @param string $billing_country Billig Country code.
	 * @return boolean
	 */
	public static function is_self_declaration_required( $ip_country = null, $billing_country = null ) {
		if ( is_null( $ip_country ) ) {
			$ip_country = self::get_ip_country();
		}
		if ( is_null( $billing_country ) ) {
			$billing_country = is_callable( array( WC()->customer, 'get_billing_country' ) ) ? WC()->customer->get_billing_country() : WC()->customer->get_country();
		}

		return ( empty( $ip_country ) || in_array( $ip_country, self::get_eu_countries(), true ) || in_array( $billing_country, self::get_eu_countries(), true ) ) && $ip_country !== $billing_country;
	}

	/**
	 * Show checkbox for customer to confirm their location (location evidence for B2C)
	 */
	public static function location_confirmation() {
		if ( 'yes' === get_option( 'woocommerce_eu_vat_number_validate_ip', 'no' ) && self::cart_has_digital_goods() ) {
			if ( false === self::$data['vat_number'] && self::is_self_declaration_required() ) {
				wc_get_template(
					'location-confirmation-field.php',
					array(
						'location_confirmation_is_checked' => isset( $_POST['location_confirmation'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
						'countries'                        => WC()->countries->get_countries(),
					),
					'woocommerce-eu-vat-number',
					untrailingslashit( plugin_dir_path( WC_EU_VAT_FILE ) ) . '/templates/'
				);
			}
		}
	}

	/**
	 * Support method for WooCommerce Deposits.
	 *
	 * Sets the VAT related meta whenever a new scheduled order is created.
	 *
	 * @param WC_Order $new_order      The scheduled order object.
	 * @param WC_Order $original_order The original order object.
	 */
	public static function set_vat_details_for_scheduled_orders( $new_order, $original_order ) {
		$vat_number       = $original_order->get_meta( '_billing_vat_number' );
		$is_vat_exempt    = $original_order->get_meta( 'is_vat_exempt' );
		$is_vat_validated = $original_order->get_meta( '_vat_number_is_validated' );
		$is_vat_valid     = $original_order->get_meta( '_vat_number_is_valid' );

		if ( ! empty( $vat_number ) ) {
			$new_order->update_meta_data( '_billing_vat_number', $vat_number );
		}

		if ( ! empty( $is_vat_exempt ) ) {
			$new_order->update_meta_data( 'is_vat_exempt', $is_vat_exempt );
		}

		if ( ! empty( $is_vat_validated ) ) {
			$new_order->update_meta_data( '_vat_number_is_validated', $is_vat_validated );
		}

		if ( ! empty( $is_vat_valid ) ) {
			$new_order->update_meta_data( '_vat_number_is_valid', $is_vat_valid );
		}
	}

	/**
	 * Triggered when the totals are updated on the checkout.
	 *
	 * @since 1.0.0
	 * @version 2.3.1
	 * @param array $form_data Checkout Form data.
	 */
	public static function ajax_update_checkout_totals( $form_data ) {
		parse_str( $form_data, $form_data );

		self::reset();

		if ( empty( $form_data['billing_country'] ) && empty( $form_data['shipping_country'] ) || ( empty( $form_data['billing_vat_number'] ) && empty( $form_data['shipping_vat_number'] ) ) ) {
			return;
		}

		self::validate_checkout( $form_data );
	}

	/**
	 * Sees if a cart contains anything non-shippable. Thanks EU, I hate you.
	 *
	 * @return bool
	 */
	public static function cart_has_digital_goods() {
		$has_digital_goods = false;

		if ( WC()->cart->get_cart() ) {
			foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
				$_product = $values['data'];
				if ( ! $_product->needs_shipping() ) {
					$has_digital_goods = true;
				}
			}
		}

		/**
		 * Filters if cart has digital goods.
		 *
		 * @since 2.1.2
		 *
		 * @param bool $has_digital_goods Is it Digital good?
		 */
		return apply_filters( 'woocommerce_cart_has_digital_goods', $has_digital_goods );
	}

	/**
	 * Add VAT ID to the formatted address array
	 *
	 * @param  array    $address Address Array.
	 * @param  WC_Order $order WC Order Object.
	 * @return array
	 */
	public static function formatted_billing_address( $address, $order ) {
		if ( $order->has_shipping_address() && wc_eu_vat_use_shipping_country() ) {
			return $address;
		}

		$vat_id = wc_eu_vat_get_vat_from_order( $order );

		if ( $vat_id ) {
			$address['vat_id'] = $vat_id;
		}
		return $address;
	}

	/**
	 * Add VAT ID to the formatted shipping address array
	 *
	 * @param  array    $address Address Array.
	 * @param  WC_Order $order   WC Order Object.
	 * @return array
	 */
	public static function formatted_shipping_address( $address, $order ) {
		if ( ! $order->has_shipping_address() || ! wc_eu_vat_use_shipping_country() ) {
			return $address;
		}

		$vat_id = wc_eu_vat_get_vat_from_order( $order );

		if ( $vat_id ) {
			$address['vat_id'] = $vat_id;
		}
		return $address;
	}



	/**
	 * Add {vat_id} placeholder
	 *
	 * @param  array $formats Address formats.
	 * @param  array $args    Arguments.
	 * @return array
	 */
	public static function output_company_vat_number( $formats, $args ) {
		if ( isset( $args['vat_id'] ) ) {
			/* translators: %s: VAT Number */
			$formats['{vat_id}'] = sprintf( __( 'VAT Number: %s', 'woocommerce-eu-vat-number' ), $args['vat_id'] );
		} else {
			$formats['{vat_id}'] = '';
		}
		return $formats;
	}

	/**
	 * Address formats.
	 *
	 * @param  array $formats Address formats.
	 * @return array
	 */
	public static function localisation_address_formats( $formats ) {
		foreach ( $formats as $key => $format ) {
			if ( 'default' === $key || in_array( $key, self::get_eu_countries(), true ) ) {
				$formats[ $key ] .= "\n{vat_id}";
			}
		}
		return $formats;
	}

	/**
	 * Force Digital Goods tax class to use billing address
	 *
	 * @param  array  $location  Location.
	 * @param  string $tax_class Tax Class.
	 * @return array
	 */
	public static function woocommerce_get_tax_location( $location, $tax_class = '' ) {
		if ( ! empty( WC()->customer ) && ! empty( $tax_class ) && in_array( sanitize_title( $tax_class ), get_option( 'woocommerce_eu_vat_number_digital_tax_classes', array() ), true ) ) {
			return array(
				WC()->customer->get_billing_country(),
				WC()->customer->get_billing_state(),
				WC()->customer->get_billing_postcode(),
				WC()->customer->get_billing_city(),
			);
		}
		return $location;
	}

	/**
	 * Add VAT Number to order endpoint response.
	 *
	 * @since 2.1.12
	 *
	 * @param WP_REST_Response $response The response object.
	 *
	 * @return WP_REST_Response The response object with VAT number
	 */
	public static function add_vat_number_to_order_response( $response ) {
		if ( is_a( $response, 'WP_REST_Response' ) ) {
			$order                        = wc_get_order( (int) $response->data['id'] );
			$response->data['vat_number'] = $order->get_meta( '_billing_vat_number', true );
		} elseif ( is_array( $response ) && ! empty( $response['id'] ) ) {
			// Legacy endpoint.
			$order                  = wc_get_order( (int) $response['id'] );
			$response['vat_number'] = $order->get_meta( '_billing_vat_number', true );
		}
		return $response;
	}

	/**
	 * Save VAT Number to the order during checkout (WC 2.7.x).
	 *
	 * @param  WC_Order $order WC Order.
	 */
	public static function set_order_data( $order ) {
		$order->update_meta_data( '_billing_vat_number', self::$data['vat_number'] );
		$order->update_meta_data( '_vat_number_is_validated', ! is_null( self::$data['validation']['valid'] ) ? 'true' : 'false' );
		$order->update_meta_data( '_vat_number_is_valid', true === self::$data['validation']['valid'] ? 'true' : 'false' );

		if ( false !== self::get_ip_country() ) {
			$order->update_meta_data( '_customer_ip_country', self::get_ip_country() );
			$order->update_meta_data( '_customer_self_declared_country', ! empty( $_POST['location_confirmation'] ) ? 'true' : 'false' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * Save VAT Number to the customer during checkout (WC 2.7.x).
	 *
	 * @param  WC_Customer $customer Customer Object.
	 */
	public static function set_customer_data( $customer ) {
		$customer->update_meta_data( 'vat_number', self::$data['vat_number'] );
	}

	/**
	 * Save VAT Number to the customer during checkout (WC 2.7.x).
	 *
	 * @param  WC_Order $refund Refund Order.
	 */
	public static function set_refund_data( $refund ) {
		$order = wc_get_order( $refund->get_parent_id() );
		$refund->update_meta_data( '_billing_vat_number', wc_eu_vat_get_vat_from_order( $order ) );
	}

	/**
	 * Validate AJAX Order Review / Checkout & add errors if any.
	 *
	 * @param array   $data Checkout field data.
	 * @param boolean $doing_checkout True if doing checkout. False if AJAX order review.
	 */
	public static function validate_checkout( $data, $doing_checkout = false ) {
		$use_shipping_country = wc_eu_vat_use_shipping_country();
		$b2b_vat_enabled      = get_option( 'woocommerce_eu_vat_number_b2b', 'no' );
		$fail_handler         = get_option( 'woocommerce_eu_vat_number_failure_handling', 'reject' );
		$billing_country      = wc_clean( $data['billing_country'] );
		$shipping_country     = wc_clean( ! empty( $data['shipping_country'] ) && ! empty( $data['ship_to_different_address'] ) ? $data['shipping_country'] : $data['billing_country'] );
		$billing_vat_number   = wc_clean( $data['billing_vat_number'] );
		$billing_postcode     = wc_clean( $data['billing_postcode'] );
		$shipping_postcode    = wc_clean( ( ! empty( $data['ship_to_different_address'] ) && isset( $data['shipping_postcode'] ) ) ? $data['shipping_postcode'] : $data['billing_postcode'] );
		$ship_to_different    = ! empty( $data['ship_to_different_address'] ) ? true : false;

		// If using shipping country, use shipping VAT number.
		if ( ! empty( $data['shipping_country'] ) && $use_shipping_country && $ship_to_different ) {
			$billing_vat_number = wc_clean( $data['shipping_vat_number'] );
		}
		$country  = $billing_country;
		$postcode = $billing_postcode;
		if ( $use_shipping_country && $ship_to_different ) {
			$country  = $shipping_country;
			$postcode = $shipping_postcode;
		}

		if ( in_array( $country, self::get_eu_countries(), true ) && ! empty( $billing_vat_number ) ) {
			self::validate( $billing_vat_number, $country, $postcode );

			if ( true === (bool) self::$data['validation']['valid'] ) {
				self::maybe_set_vat_exempt( true, $billing_country, $shipping_country );
			} else {
				switch ( $fail_handler ) {
					case 'accept_with_vat':
						self::maybe_set_vat_exempt( false, $billing_country, $shipping_country );
						break;
					case 'accept':
						self::maybe_set_vat_exempt( true, $billing_country, $shipping_country );
						break;
					default:
						if ( false === self::$data['validation']['valid'] ) {
							wc_add_notice(
								sprintf(
									/* translators: 1: VAT number field label, 2: VAT Number, 3: Address type, 4: Country */
									__( 'You have entered an invalid %1$s (%2$s) for your %3$s country (%4$s).', 'woocommerce-eu-vat-number' ),
									get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ),
									self::$data['vat_number'],
									( $use_shipping_country && $ship_to_different ) ? __( 'shipping', 'woocommerce-eu-vat-number' ) : __( 'billing', 'woocommerce-eu-vat-number' ),
									$country
								),
								'error'
							);
						} else {
							wc_add_notice( self::$data['validation']['error'], 'error' );
						}
						break;
				}
			}
		}

		// If doing checkout, check for additional conditions.
		if ( $doing_checkout ) {
			if ( in_array( $country, self::get_eu_countries(), true ) && empty( $billing_vat_number ) ) {
				if ( 'yes' === $b2b_vat_enabled ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: VAT number field label, 2: Address type, 3: Billing country */
							__( '%1$s is a required field for your %2$s country (%3$s).', 'woocommerce-eu-vat-number' ),
							'<strong>' . get_option( 'woocommerce_eu_vat_number_field_label', __( 'VAT number', 'woocommerce-eu-vat-number' ) ) . '</strong>',
							( $use_shipping_country && $ship_to_different ) ? __( 'shipping', 'woocommerce-eu-vat-number' ) : __( 'billing', 'woocommerce-eu-vat-number' ),
							$billing_country
						),
						'error'
					);
				}

				if ( 'yes' === get_option( 'woocommerce_eu_vat_number_validate_ip', 'no' ) && self::cart_has_digital_goods() ) {
					if ( self::is_self_declaration_required( self::get_ip_country(), $billing_country ) && empty( $data['location_confirmation'] ) ) {

						/**
						 * Filters the self declared IP address.
						 *
						 * @since 2.1.10
						 */
						$ip_address = apply_filters( 'wc_eu_vat_self_declared_ip_address', WC_Geolocation::get_ip_address() );
						/* translators: 1: Ip Address. */
						wc_add_notice( sprintf( __( 'Your IP Address (%1$s) does not match your billing country (%2$s). European VAT laws require your IP address to match your billing country when purchasing digital goods in the EU. Please confirm you are located within your billing country using the checkbox below.', 'woocommerce-eu-vat-number' ), $ip_address, $billing_country ), 'error' );
					}
				}
			}
		}
	}

	/**
	 * Load script with all required dependencies.
	 *
	 * @param string $handler Script handler.
	 * @param string $script Script name.
	 * @param array  $dependencies Additional dependencies.
	 *
	 * @return void
	 */
	public static function register_script_with_dependencies( string $handler, string $script, array $dependencies = array() ) {
		$script_file                  = $script . '.js';
		$script_src_url               = plugins_url( $script_file, __DIR__ );
		$script_asset_path            = WC_EU_ABSPATH . $script . '.asset.php';
		$script_asset                 = file_exists( $script_asset_path ) ? require $script_asset_path : array( 'dependencies' => array() );
		$script_asset['dependencies'] = array_merge( $script_asset['dependencies'], $dependencies );
		wp_register_script(
			$handler,
			$script_src_url,
			$script_asset['dependencies'],
			self::get_file_version( $script_file ),
			true
		);
	}

	/**
	 * Gets the file modified time as a cache buster if we're in dev mode, or the plugin version otherwise.
	 *
	 * @param string $file Local path to the file.
	 * @return string The cache buster value to use for the given file.
	 */
	public static function get_file_version( $file ): string {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( WC_EU_ABSPATH . $file ) ) {
			return (string) filemtime( WC_EU_ABSPATH . trim( $file, '/' ) );
		}
		return WC_EU_VAT_VERSION;
	}
}

WC_EU_VAT_Number::init();
