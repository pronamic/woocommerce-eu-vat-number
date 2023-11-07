jQuery(function(){
	function field_is_required( field, is_required ) {
		if ( is_required ) {
			field.find( 'label .optional' ).remove();
			field.addClass( 'validate-required' );

			if ( field.find( 'label .required' ).length === 0 ) {
				field.find( 'label' ).append(
					'&nbsp;<abbr class="required" title="' +
					wc_address_i18n_params.i18n_required_text +
					'">*</abbr>'
				);
			}
		} else {
			field.find( 'label .required' ).remove();
			field.removeClass( 'validate-required woocommerce-invalid woocommerce-invalid-required-field' );

			if ( field.find( 'label .optional' ).length === 0 ) {
				field.find( 'label' ).append( '&nbsp;<span class="optional">(' + wc_address_i18n_params.i18n_optional_text + ')</span>' );
			}
		}
	}

	jQuery( 'form.checkout, form#order_review').on( 'change', '#billing_country', function() {
		var country         = jQuery( '#billing_country' ).val();
		var check_countries = wc_eu_vat_params.eu_countries;
		var b2b_enabled     = wc_eu_vat_params.b2b_required;

		field_is_required( jQuery( '#woocommerce_eu_vat_number_field' ), false );

		if ( country && jQuery.inArray( country, check_countries ) >= 0 ) {
			jQuery( '#woocommerce_eu_vat_number_field' ).fadeIn();
			if ( 'yes' === b2b_enabled ) {
				field_is_required( jQuery( '#woocommerce_eu_vat_number_field' ), true );
			}
		} else {
			jQuery( '#woocommerce_eu_vat_number_field' ).fadeOut();
		}
	});
	jQuery( '#billing_country' ).trigger( 'change' );

	/* Validate EU VAT Number field only on change event */
	jQuery( 'form.checkout, form#order_review' ).on( 'change', '#woocommerce_eu_vat_number', function() {
		jQuery( 'body' ).trigger( 'update_checkout' );
	} );

	/**
	 * Handles checkout field UI when VAT field validation fails.
	 */
	jQuery( document.body ).on( 'updated_checkout', function( e, data ) {
		$vat_field = jQuery( '#woocommerce_eu_vat_number' );

		if ( ! $vat_field.is( ':visible' ) ) {
			return;
		}

		$vat_code = $vat_field.val();
		$vat_field_wrapper = $vat_field.closest( '.form-row' );

		if ( 'success' === data.result ) {
			if ( ! $vat_code.length ) {
				$vat_field_wrapper.removeClass( 'woocommerce-validated' );
			}

			return;
		}

		/** If the message includes the VAT number, then highlight the VAT field in red. */
		if ( data.messages.length && data.messages.includes( $vat_code.toUpperCase() ) ) {
			$vat_field_wrapper.removeClass( 'woocommerce-validated' );
			$vat_field_wrapper.addClass( 'woocommerce-invalid' );
		}
	} )
});
