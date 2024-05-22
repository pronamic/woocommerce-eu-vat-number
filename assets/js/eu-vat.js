/* global wc_eu_vat_params, wc_address_i18n_params */
/* eslint-disable camelcase */
jQuery( function () {
	const useShippingCountry = wc_eu_vat_params.use_shipping_country;
	const billingVatNumber = '#woocommerce_eu_vat_number_field';
	const shippingVatNumber = '#woocommerce_eu_vat_number_shipping_field';

	function field_is_required( field, is_required ) {
		if ( is_required ) {
			field.find( 'label .optional' ).remove();
			field.addClass( 'validate-required' );

			if ( field.find( 'label .required' ).length === 0 ) {
				field
					.find( 'label' )
					.append(
						'<abbr class="required" title="' +
							wc_address_i18n_params.i18n_required_text +
							'">*</abbr>'
					);
			}
		} else {
			field.find( 'label .required' ).remove();
			field.removeClass(
				'validate-required woocommerce-invalid woocommerce-invalid-required-field'
			);

			if ( field.find( 'label .optional' ).length === 0 ) {
				field
					.find( 'label' )
					.append(
						'<span class="optional">(' +
							wc_address_i18n_params.i18n_optional_text +
							')</span>'
					);
			}
		}
	}

	// Handle change billing/shipping country.
	function changeCountry() {
		const billingCountry = jQuery( '#billing_country' ).val();
		const shippingCountry = jQuery( '#shipping_country' ).val();
		const shipToDifferent = jQuery(
			'#ship-to-different-address-checkbox'
		).is( ':checked' );
		const validateShippingCountry =
			useShippingCountry && shippingCountry && shipToDifferent;
		const country = validateShippingCountry
			? shippingCountry
			: billingCountry;
		const check_countries = wc_eu_vat_params.eu_countries;
		const b2b_enabled = wc_eu_vat_params.b2b_required;

		const vat_number_field = validateShippingCountry
			? jQuery( shippingVatNumber )
			: jQuery( billingVatNumber );

		field_is_required( vat_number_field, false );

		if ( country && jQuery.inArray( country, check_countries ) >= 0 ) {
			vat_number_field.fadeIn();
			if ( b2b_enabled === 'yes' ) {
				field_is_required( vat_number_field, true );
			}
		} else {
			vat_number_field.fadeOut();
		}
	}

	jQuery( 'form.checkout, form#order_review' ).on(
		'change',
		'#billing_country',
		changeCountry
	);
	jQuery( '#billing_country' ).trigger( 'change' );

	if ( useShippingCountry ) {
		jQuery( 'form.checkout, form#order_review' ).on(
			'change',
			'#shipping_country',
			changeCountry
		);
		jQuery( '#shipping_country' ).trigger( 'change' );
	}

	// Trigger country change on ship to different address checkbox change.
	jQuery( 'form.checkout, form#order_review' ).on(
		'change',
		'#ship-to-different-address-checkbox',
		function () {
			if ( ! useShippingCountry ) {
				return;
			}

			const isChecked = jQuery(
				'#ship-to-different-address-checkbox'
			).is( ':checked' );

			if ( isChecked ) {
				jQuery( billingVatNumber ).fadeOut();
				jQuery( '#shipping_country' ).trigger( 'change' );
			} else {
				jQuery( shippingVatNumber ).fadeOut();
				jQuery( '#billing_country' ).trigger( 'change' );
			}
		}
	);
	jQuery( '#ship-to-different-address-checkbox' ).trigger( 'change' );

	/* Validate EU VAT Number field only on change event */
	jQuery( 'form.checkout, form#order_review' ).on(
		'change',
		billingVatNumber,
		function () {
			jQuery( 'body' ).trigger( 'update_checkout' );
		}
	);

	if ( useShippingCountry ) {
		jQuery( 'form.checkout, form#order_review' ).on(
			'change',
			shippingVatNumber,
			function () {
				jQuery( 'body' ).trigger( 'update_checkout' );
			}
		);
	}

	/**
	 * Handles checkout field UI when VAT field validation fails.
	 */
	jQuery( document.body ).on( 'updated_checkout', function( e, data ) {
		const shippingCountry = jQuery( '#shipping_country' ).val();
		const shipToDifferent = jQuery(
			'#ship-to-different-address-checkbox'
		).is( ':checked' );
		const $vat_field =
			useShippingCountry && shippingCountry && shipToDifferent
				? jQuery( shippingVatNumber )
				: jQuery( billingVatNumber );

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
