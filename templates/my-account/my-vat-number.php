<?php
/**
 * My Vat Number
 *
 * @package woocommerce-eu-vat-number/templates
 * @version 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php if ( ! empty( $messages ) ) { ?>
	<ul class="woocommerce-<?php echo esc_attr( $messages['status'] ); ?>">
		<li><?php echo wp_kses_post( $messages['message'] ); ?></li>
	</ul>
<?php } ?>
<form method="post">
	<p class="form-row form-row form-row-first">
		<label for="vat_number">
			<?php esc_html_e( 'VAT number', 'woocommerce-eu-vat-number' ); ?>
		</label>
		<input type="text" value="<?php echo esc_attr( $vat_number ); ?>" id="vat_number" name="vat_number" class="input-text" />
	</p>
	<div class="clear"></div>
	<p>
		<input type="submit" value="<?php echo esc_attr( __( 'Save', 'woocommerce-eu-vat-number' ) ); ?>" class="button wp-element-button" />
		<?php wp_nonce_field( 'woocommerce-edit_vat_number' ); ?>
		<input type="hidden" name="action" value="edit_vat_number" />
	</p>
</form>
