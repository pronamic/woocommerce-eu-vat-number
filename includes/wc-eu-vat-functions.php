<?php
/**
 * General functions.
 *
 * @package woocommerce-eu-vat-number
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data payload for wc_add_notice() so session notices can be identified as from this plugin.
 *
 * @since 3.2.0
 * @return array
 */
function wc_eu_vat_notice_data() {
	return array( WC_EU_VAT_NOTICE_DATA_KEY => true );
}

/**
 * Whether a stored notice was added by this plugin (third parameter to wc_add_notice).
 *
 * @since 3.2.0
 * @param array $notice Notice entry from wc_notices session.
 * @return bool
 */
function wc_eu_vat_is_tagged_notice( $notice ) {
	if ( ! is_array( $notice ) || empty( $notice['data'] ) || ! is_array( $notice['data'] ) ) {
		return false;
	}

	return ! empty( $notice['data'][ WC_EU_VAT_NOTICE_DATA_KEY ] );
}

/**
 * Removes EU VAT–tagged error notices from the session (see WC_EU_VAT_NOTICE_DATA_KEY / wc_eu_vat_notice_data()).
 *
 * Used when validation succeeds, when using accept/accept_with_vat handlers, and before
 * adding a fresh reject notice so stale Store API 409 errors do not persist.
 * Legacy notices without tagged data are not cleared.
 *
 * @since 3.2.0
 * @return void
 */
function wc_eu_vat_clear_tagged_error_notices_from_session() {
	if ( ! WC()->session ) {
		return;
	}

	$notices = WC()->session->get( 'wc_notices', array() );
	if ( empty( $notices['error'] ) || ! is_array( $notices['error'] ) ) {
		return;
	}

	$kept = array();
	foreach ( $notices['error'] as $notice ) {
		if ( ! wc_eu_vat_is_tagged_notice( $notice ) ) {
			$kept[] = $notice;
		}
	}

	if ( count( $kept ) === count( $notices['error'] ) ) {
		return;
	}

	$notices['error'] = $kept;
	if ( empty( $notices['error'] ) ) {
		unset( $notices['error'] );
	}

	WC()->session->set( 'wc_notices', $notices );
}

/**
 * Gets the VAT ID from order.
 *
 * @since 2.3.21
 * @param object $order The order in context.
 * @return string $vat;
 */
function wc_eu_vat_get_vat_from_order( $order ) {
	if ( ! $order ) {
		return '';
	}

	$vat = $order->get_meta( '_billing_vat_number', true ) ? $order->get_meta( '_billing_vat_number', true ) : '';

	if ( ! $vat ) {
		$vat = $order->get_meta( '_vat_number', true ) ? $order->get_meta( '_vat_number', true ) : '';
	}

	return strtoupper( $vat );
}

/**
 * Display 0.00% VAT line item and reason.
 *
 * @param array    $total_rows  Order item totals array.
 * @param WC_Order $order       WC_Order object.
 * @param string   $tax_display Tax display (incl or excl).
 */
function wc_eu_vat_maybe_add_zero_tax_display( $total_rows, $order, $tax_display ) {
	// Display in Email and Invoice only.
	if ( is_account_page() ) {
		return $total_rows;
	}

	$is_vat_exempt = ( 'yes' === $order->get_meta( 'is_vat_exempt' ) );
	$is_valid      = wc_string_to_bool( $order->get_meta( '_vat_number_is_valid', true ) );

	// Check if VAT number is valid and tax is exempted.
	if ( wc_tax_enabled() && $is_vat_exempt && $is_valid && empty( $order->get_tax_totals() ) ) {
		/**
		 * Filters the reason for zero tax.
		 *
		 * @since 2.8.1
		 */
		$zero_tax_reason    = apply_filters( 'wc_eu_vat_number_zero_tax_reason', __( 'Supply of services subject to reverse charge', 'woocommerce-eu-vat-number' ) );
		$display_tax_reason = '<br/><small>' . esc_html( $zero_tax_reason ) . '</small>';

		if ( 'excl' === $tax_display ) {
			if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
				$tax_line_item = array(
					'tax' => array(
						'label' => WC()->countries->tax_or_vat() . ':',
						'value' => wc_price( $order->get_total_tax(), array( 'currency' => $order->get_currency() ) ) . $display_tax_reason,
					),
				);

				// Add zero tax line item before grand total.
				array_splice( $total_rows, count( $total_rows ) - 1, 0, $tax_line_item );
			} elseif ( isset( $total_rows['tax'] ) && isset( $total_rows['tax']['value'] ) ) {
				$total_rows['tax']['value'] = $total_rows['tax']['value'] . $display_tax_reason;
			}
		} elseif ( 'incl' === $tax_display ) {
			// translators: %1$s: Tax label (VAT or Tax).
			$append_zero_tax = sprintf( esc_html__( ' (inc. 0.00%% %1$s) ', 'woocommerce-eu-vat-number' ), WC()->countries->tax_or_vat() ) . $display_tax_reason;

			// Append zero tax details to Grand Total.
			$total_rows['order_total']['value'] = $total_rows['order_total']['value'] . $append_zero_tax;
		}
	}

	return $total_rows;
}

/**
 * Get whether to use shipping country for VAT validation.
 *
 * @since 2.9.4
 *
 * @return bool
 */
function wc_eu_vat_use_shipping_country() {
	return 'yes' === get_option( 'woocommerce_eu_vat_number_use_shipping_country', 'yes' );
}

/**
 * Whether checkout must collect a company name when a VAT number is provided.
 *
 * @since 3.2.0
 *
 * @return bool
 */
function wc_eu_vat_require_company_with_vat() {
	return 'yes' === get_option( 'woocommerce_eu_vat_number_require_company_with_vat', 'no' );
}

/**
 * Resolve checkout company field visibility from Blocks when available, else the WooCommerce option.
 *
 * @since 3.2.0
 *
 * @return string optional|required|hidden (values match WC checkout company field setting).
 */
function wc_eu_vat_resolve_checkout_company_field_visibility() {
	$class = \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class;
	if ( class_exists( $class ) && is_callable( array( $class, 'get_company_field_visibility' ) ) ) {
		return call_user_func( array( $class, 'get_company_field_visibility' ) );
	}

	return get_option( 'woocommerce_checkout_company_field', 'hidden' );
}

/**
 * Whether the WooCommerce checkout company field is shown (classic, blocks, and Store API use the same setting).
 *
 * When hidden, customers cannot enter a company name; require-company-with-VAT must not block checkout.
 *
 * @since 3.2.0
 *
 * @return bool False when WooCommerce checkout company field visibility is "hidden".
 */
function wc_eu_vat_checkout_company_field_is_visible() {
	return 'hidden' !== wc_eu_vat_resolve_checkout_company_field_visibility();
}

/**
 * WooCommerce checkout company field visibility: optional, required, or hidden.
 *
 * @since 3.2.0
 *
 * @return string
 */
function wc_eu_vat_get_checkout_company_field_visibility_option() {
	return wc_eu_vat_resolve_checkout_company_field_visibility();
}

/**
 * Validate company name when required for B2B-style checkout (non-empty when required).
 *
 * @since 3.2.0
 *
 * @param string      $company              Company string (will be trimmed).
 * @param bool        $required             If true, empty input is an error.
 * @param string|null $company_address_type Which address the company belongs to: 'billing', 'shipping', or null for generic wording.
 * @return true|WP_Error True when valid; WP_Error with message when invalid.
 */
function wc_eu_vat_validate_company_name( $company, $required = true, $company_address_type = null ) {
	$company = trim( (string) $company );

	switch ( $company_address_type ) {
		case 'shipping':
			$subject = __( 'Shipping company name', 'woocommerce-eu-vat-number' );
			break;
		case 'billing':
			$subject = __( 'Billing company name', 'woocommerce-eu-vat-number' );
			break;
		default:
			$subject = __( 'Company name', 'woocommerce-eu-vat-number' );
			break;
	}

	if ( '' === $company ) {
		if ( $required ) {
			return new \WP_Error(
				'wc_eu_vat_company_required',
				sprintf(
					/* translators: %s: Label such as "Billing company name" or "Company name". */
					__( '%s is required when providing a VAT number.', 'woocommerce-eu-vat-number' ),
					$subject
				)
			);
		}
	}

	return true;
}

/**
 * Whether VAT/company validation should use the shipping address for this checkout context.
 *
 * Classic checkout: shipping address when the setting is on and the customer ships elsewhere.
 * Block checkout: also when the Store API session used shipping for VAT (`wc_eu_vat_used_shipping_country`),
 * including "use same address for billing" and ship-to-different flows.
 *
 * @since 3.2.0
 *
 * @param bool $use_shipping_country Use shipping address for VAT validation (setting).
 * @param bool $ship_to_different    Customer ships to a different address.
 * @param bool $needs_shipping       Cart/order needs a shipping address.
 * @return bool
 */
function wc_eu_vat_use_shipping_address_for_vat_context( $use_shipping_country, $ship_to_different, $needs_shipping = true ) {
	if ( ! $use_shipping_country || ! $needs_shipping ) {
		return false;
	}

	if ( $ship_to_different ) {
		return true;
	}

	if ( WC()->session ) {
		$used_shipping_for_vat = WC()->session->get( 'wc_eu_vat_used_shipping_country', null );
		if ( null !== $used_shipping_for_vat ) {
			return (bool) $used_shipping_for_vat;
		}
	}

	return false;
}

/**
 * Resolve which company field aligns with VAT input context (billing vs shipping).
 *
 * @since 3.2.0
 *
 * @param bool      $use_shipping_country       Use shipping address for VAT validation (setting).
 * @param bool      $ship_to_different          Customer ships to a different address.
 * @param string    $billing_company            Billing company value.
 * @param string    $shipping_company           Shipping company value.
 * @param bool|null $use_shipping_address       Optional. VAT/shipping-address context for company field.
 * @param bool|null $use_shipping_company_field Optional. Overrides which company field to read/highlight.
 * @return array{company: string, company_address: string} company_address is 'billing' or 'shipping'. Only the targeted field value is used (no cross-address fallback).
 */
function wc_eu_vat_resolve_company_for_vat_context(
	$use_shipping_country,
	$ship_to_different,
	$billing_company,
	$shipping_company,
	$use_shipping_address = null,
	$use_shipping_company_field = null
) {
	if ( null === $use_shipping_address ) {
		$use_shipping_address = wc_eu_vat_use_shipping_address_for_vat_context(
			$use_shipping_country,
			$ship_to_different,
			true
		);
	}

	if ( null === $use_shipping_company_field ) {
		$use_shipping_company_field = $use_shipping_address;
	}

	$billing_company  = trim( (string) $billing_company );
	$shipping_company = trim( (string) $shipping_company );

	if ( $use_shipping_company_field ) {
		return array(
			'company'         => $shipping_company,
			'company_address' => 'shipping',
		);
	}

	return array(
		'company'         => $billing_company,
		'company_address' => 'billing',
	);
}

/**
 * Block checkout (Store API): use shipping company when VAT uses billing but the shopper uses same address for billing.
 *
 * @since 3.2.0
 *
 * @param WC_Order $order                  Order.
 * @param bool     $use_shipping_country   Setting enabled.
 * @param bool     $ship_to_different      Ship to a different address.
 * @return bool
 */
function wc_eu_vat_use_shipping_company_for_block_same_address_order( $order, $use_shipping_country, $ship_to_different ) {
	return ! $use_shipping_country
		&& $order->needs_shipping_address()
		&& ! $ship_to_different
		&& 'store-api' === $order->get_created_via();
}

/**
 * Resolve billing vs shipping company for company+VAT validation on an order (mirrors checkout field logic).
 *
 * @since 3.2.0
 *
 * @param WC_Order $order Order object.
 * @return array{company: string, vat_country: string, vat: string, company_address: string}|null Null when this validation does not apply.
 */
function wc_eu_vat_get_company_validation_context_for_order( $order ) {
	if ( ! $order instanceof WC_Order || ! wc_eu_vat_require_company_with_vat() ) {
		return null;
	}

	$vat = wc_eu_vat_get_vat_from_order( $order );
	if ( '' === $vat ) {
		return null;
	}

	$use_shipping_country = wc_eu_vat_use_shipping_country();
	$billing_country      = $order->get_billing_country();
	$shipping_country     = $order->get_shipping_country();
	$ship_to_different    = false;

	if ( $order->has_shipping_address() ) {
		$ship_to_different = ( $shipping_country && $shipping_country !== $billing_country )
			|| (
				trim( (string) $order->get_shipping_address_1() ) !== ''
				&& trim( (string) $order->get_shipping_address_1() ) !== trim( (string) $order->get_billing_address_1() )
			);
	}

	$use_shipping_address = wc_eu_vat_use_shipping_address_for_vat_context(
		$use_shipping_country,
		$ship_to_different,
		$order->needs_shipping_address()
	);

	$vat_country = $billing_country;
	if ( $use_shipping_address ) {
		$vat_country = $shipping_country ? $shipping_country : $billing_country;
	}

	if ( ! in_array( $vat_country, WC_EU_VAT_Number::get_eu_countries(), true ) ) {
		return null;
	}

	$use_shipping_company_field = $use_shipping_address
		|| wc_eu_vat_use_shipping_company_for_block_same_address_order(
			$order,
			$use_shipping_country,
			$ship_to_different
		);

	$resolved        = wc_eu_vat_resolve_company_for_vat_context(
		$use_shipping_country,
		$ship_to_different,
		$order->get_billing_company(),
		$order->get_shipping_company(),
		$use_shipping_address,
		$use_shipping_company_field
	);
	$company         = $resolved['company'];
	$company_address = $resolved['company_address'];

	return array(
		'company'         => $company,
		'vat_country'     => $vat_country,
		'vat'             => $vat,
		'company_address' => $company_address,
	);
}

/**
 * Add an order note with details about VAT validation result.
 *
 * @since 3.1.0
 *
 * @param WC_Order $order       WC Order.
 * @param string   $vat_number  VAT number.
 * @param bool     $is_valid    Whether VAT number is valid.
 * @return void
 */
function wc_eu_vat_maybe_add_order_note( $order, $vat_number, $is_valid ) {
	// No VAT number provided, no note needed.
	if ( empty( $vat_number ) ) {
		return;
	}

	// VAT number was validated successfully.
	if ( true === $is_valid ) {
		$order->add_order_note(
			sprintf(
				/* translators: %s: VAT number */
				__( 'VAT number %s was validated successfully.', 'woocommerce-eu-vat-number' ),
				$vat_number
			)
		);
		return;
	}
}
