/**
 * External dependencies
 */
import { sprintf, __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { CheckboxControl } from '@woocommerce/blocks-checkout';
import {
	useState,
	createInterpolateElement,
	useEffect,
} from '@wordpress/element';
import { getSetting } from '@woocommerce/settings';

/**
 * Renders a warning when the customer's IP address does not match the billing country they chose.
 *
 * @param {Object} props Incoming props for the component.
 * @param {boolean} props.shouldValidateIp Whether WC EU VAT Number is set up to validate the customer's IP address.
 * @param {string} props.billingCountry The customer's billing country.
 * @param {string} props.ipAddress The customer's IP address.
 * @param {string} props.ipCountry The country that the customer's IP is from.
 * @param {Object} props.validation Object containing WooCommerce Blocks validation methods.
 * @param {Object} props.checkoutExtensionData Object containing setCheckoutData to allow us to pass data to the checkout endpoint.
 * @return {JSX.Element|null} The component to render, or null if there's nothing to render.
 */
export const IpAddressNotice = ( {
	shouldValidateIp,
	billingCountry,
	ipAddress,
	ipCountry,
	validation,
	checkoutExtensionData,
} ) => {
	const countryLocales = getSetting( 'allowedCountries', {} );
	const { setExtensionData } = checkoutExtensionData;
	let countryName = billingCountry;
	if ( !! countryLocales[ billingCountry ] ) {
		countryName = countryLocales[ billingCountry ];
	}
	const { extensions } = useSelect( ( select ) =>
		select( 'wc/store/cart' ).getCartData()
	);
	const { cart_has_digital_goods: cartHasDigitalGoods } =
		extensions[ 'woocommerce-eu-vat-number' ];

	const { setValidationErrors, clearValidationError, getValidationError } =
		validation;

	const [ isChecked, setIsChecked ] = useState( false );
	const [ isDirty, setIsDirty ] = useState( false );
	const validationErrorId = 'billing_vat_number_ip_address_notice';

	const validationErrorMessage = sprintf(
		/* translators: %1$s is the user's IP address, %2$s is the billing country name */
		__(
			'Your IP Address (%1$s) does not match your billing country (%2$s). European VAT laws require your IP address to match your billing country when purchasing digital goods in the EU. Please confirm you are located within your billing country using the checkbox above.',
			'woocommerce-eu-vat-number'
		),
		ipAddress,
		countryName
	);

	const isSelfDeclarationRequired =
		shouldValidateIp && cartHasDigitalGoods && billingCountry !== ipCountry;

	useEffect( () => {
		if ( ! isChecked && isSelfDeclarationRequired ) {
			setValidationErrors( {
				[ validationErrorId ]: {
					message: validationErrorMessage,
					hidden: ! isDirty,
				},
			} );
		}
		if ( isChecked ) {
			clearValidationError( validationErrorId );
		}

		// When unmounting we need to clear the error to allow checkout to continue.
		return () => {
			clearValidationError( validationErrorId );
		};
	}, [ setValidationErrors, isChecked, isDirty ] );

	if ( ! isSelfDeclarationRequired ) {
		return null;
	}
	const validationError = getValidationError( validationErrorId );

	return (
		<div className="wc-eu-vat-checkout-ip-notice">
			<div className="wc-eu-vat-checkout-ip-notice__checkbox-container">
				<CheckboxControl
					name={ 'location_confirmation' }
					className={
						validationError?.hidden === false ? 'has-error' : ''
					}
					label={ createInterpolateElement(
						sprintf(
							/* translators: %s is the billing country full name, e.g. Finland, France, or Spain. */
							__(
								'I am established, have my permanent address, or usually reside within <strong>%s</strong>.',
								'woocommerce-eu-vat-number'
							),
							countryName
						),
						{ strong: <strong /> }
					) }
					checked={ isChecked }
					onChange={ ( checked ) => {
						if ( ! isDirty ) {
							setIsDirty( true );
						}
						setIsChecked( checked );
						setExtensionData(
							'woocommerce-eu-vat-number',
							'location_confirmation',
							checked
						);
					} }
				/>
			</div>
			{ validationError?.message && ! validationError?.hidden ? (
				<div className="wc-eu-vat-checkout-ip-notice__error wc-block-components-validation-error">
					{ validationError.message }
				</div>
			) : null }
		</div>
	);
};
