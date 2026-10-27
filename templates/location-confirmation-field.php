<?php
/**
 * Location confirmation field template.
 *
 * Rendered by WC_EU_VAT_Number through wc_get_template(), which extracts the
 * arguments below into this scope.
 *
 * @package woocommerce-eu-vat-number/templates
 * @since 2.2.0
 *
 * @var bool     $location_confirmation_is_checked Whether the checkbox was submitted.
 * @var string[] $countries                        Country code => country name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<p class="form-row location_confirmation terms">
	<label for="location_confirmation" class="checkbox"><input type="checkbox" class="input-checkbox" name="location_confirmation" <?php checked( $location_confirmation_is_checked, true ); ?> id="location_confirmation" /> <span>
	<?php
		$billing_country = is_callable( array( WC()->customer, 'get_billing_country' ) ) ? WC()->customer->get_billing_country() : WC()->customer->get_country();
		/* translators: %s billing country */
		echo wp_kses_post( sprintf( __( 'I am established, have my permanent address, or usually reside within <strong>%s</strong>.', 'woocommerce-eu-vat-number' ), $countries[ $billing_country ] ) );
	?>
	</span>
	</label>
</p>
