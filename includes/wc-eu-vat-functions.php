<?php
/**
 * General functions.
 *
 * @package woocommerce-eu-vat-number
 */

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
