/* eslint-disable camelcase */
/**
 * External dependencies
 */
import { useState, useEffect } from '@wordpress/element';

import { withInstanceId } from '@wordpress/compose';
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';
import classnames from 'classnames';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useStoreCart } from '../../hooks';
import { validateCountryVatNumberFormat } from '../../utils';
import { IpAddressNotice } from '../IpAddressNotice';
import FormStep from '../form-step';

const VatInput = ( props ) => {
	const { setValidationErrors, clearValidationError, getValidationError } =
		props.validation;
	const { checkoutExtensionData } = props;

	const { billingAddress, shippingAddress, needsShipping, extensions } =
		useStoreCart();
	const {
		b2b_required,
		eu_countries,
		uk_ni_notice,
		input_label,
		input_description,
		failure_handler,
		use_shipping_country,
	} = window.wc_eu_vat_params;

	// Get the country from the shipping address if use_shipping_country is true and shipping is required.
	const country =
		use_shipping_country && needsShipping
			? shippingAddress.country
			: billingAddress.country;

	const {
		title = __( 'VAT Number', 'woocommerce-eu-vat-number' ),
		description = '',
		showStepNumber = true,
	} = props;

	const {
		woocommerce_eu_vat_number_validate_ip,
		ip_address: ipAddress,
		ip_country: ipCountry,
	} = getSetting( 'woocommerce-eu-vat-number_data' );
	const shouldValidateIp = woocommerce_eu_vat_number_validate_ip === 'yes';

	const [ isActive, setIsActive ] = useState( false );
	const [ vat, setVat ] = useState(
		extensions[ 'woocommerce-eu-vat-number' ]?.vat_number
	);
	const [ previousVat, setPreviousVat ] = useState(
		extensions[ 'woocommerce-eu-vat-number' ]?.vat_number
	);

	const [ required, setRequired ] = useState(
		b2b_required === 'yes' && eu_countries.indexOf( country ) !== -1
	);
	const [ available, setAvailable ] = useState(
		eu_countries.indexOf( country ) !== -1
	);
	const [ showGBNotice, setShowGBNotice ] = useState( country === 'GB' );

	const textInputId = 'billing_vat_number';
	const validationErrorId = 'billing_vat_number_error';
	const className = 'eu-vat-extra-css';

	const error = getValidationError( validationErrorId );
	const hasError = error?.hidden === false && error?.message !== '';

	/**
	 * Sets the VAT field on load if it is already set in the session.
	 */
	useEffect( () => {
		setVat( extensions[ 'woocommerce-eu-vat-number' ]?.vat_number );
	}, [ extensions[ 'woocommerce-eu-vat-number' ]?.vat_number ] );

	/**
	 * Sets the VAT field required if the country is in the EU and b2b_required is set to yes.
	 */
	useEffect( () => {
		setRequired(
			b2b_required === 'yes' && eu_countries.indexOf( country ) !== -1
		);
	}, [ b2b_required, country, eu_countries ] );

	/**
	 * This effect sets location_confirmation, it is required regardless of shouldValidateIp, or else the API will give
	 * us an error due to missing parameters.
	 */
	useEffect( () => {
		checkoutExtensionData.setExtensionData(
			'woocommerce-eu-vat-number',
			'location_confirmation',
			false
		);
	}, [ checkoutExtensionData.setExtensionData ] );

	/**
	 * On country change, check if the country is in the EU, if not then set the VAT Input to not available.
	 * Also update whether the GB Notice should show.
	 */
	useEffect( () => {
		setAvailable( eu_countries.indexOf( country ) !== -1 );
		setShowGBNotice( country === 'GB' );
	}, [ eu_countries, country ] );

	/**
	 * This effect handles setting the validation error immediately.
	 */
	useEffect( () => {
		if ( ! required || ( typeof vat === 'string' && vat.length > 0 ) ) {
			return;
		}
		// Instantly set the validation error when loading the component to ensure submissions without touching the
		// field cause an error to show when submitting. It is hidden at first because we don't want to show the error
		// until the user has touched the field.
		setValidationErrors( {
			[ validationErrorId ]: {
				message: __(
					'VAT number is required.',
					'woocommerce-eu-vat-number'
				),
				hidden: true,
			},
		} );
	}, [ required, setValidationErrors, validationErrorId, vat ] );

	const verifyVat = () => {
		if ( vat === previousVat ) {
			return;
		}
		clearValidationError( validationErrorId );
		// If required is true,and vat is null, undefined, or an empty string.
		if (
			required &&
			( vat === null ||
				typeof vat === 'undefined' ||
				( typeof vat === 'string' && vat.length === 0 ) )
		) {
			setValidationErrors( {
				[ validationErrorId ]: {
					message: __(
						'VAT number is required.',
						'woocommerce-eu-vat-number'
					),
					hidden: false,
				},
			} );
		}
		extensionCartUpdate( {
			namespace: 'woocommerce-eu-vat-number',
			data: {
				vat_number: vat,
			},
			cartPropsToReceive: [ 'extensions' ],
		} ).then( () => {
			setPreviousVat( vat );
			// If we get here and VAT is empty and not required then remove the error message. This is because an empty
			// VAT Number still causes an erorr when we try to update the server, but we need to update the server
			// to tell it the VAT Number is empty...
			if ( ! required && ! vat ) {
				clearValidationError( validationErrorId );
			}
		} );
	};

	const init = () => {
		if (
			typeof vat === 'string' &&
			vat.length > 0 &&
			( ( ! validateCountryVatNumberFormat( country, vat ) &&
				failure_handler === 'reject' ) ||
				( ! extensions[ 'woocommerce-eu-vat-number' ]?.validation
					?.valid &&
					vat ===
						extensions[ 'woocommerce-eu-vat-number' ]?.vat_number &&
					failure_handler === 'reject' ) )
		) {
			setValidationErrors( {
				[ validationErrorId ]: {
					message:
						extensions[ 'woocommerce-eu-vat-number' ]?.validation
							.error,
					hidden: false,
				},
			} );
		}
	};

	/**
	 * This effect runs when extensions[ 'woocommerce-eu-vat-number' ]?.validation.error changes. We can set the error
	 * on the front-end based on this, or clear it if it's empty.
	 */
	useEffect( () => {
		// If vat number is empty AND this is the first render, skip showing the error. The error will have hidden: true
		// if it's the first render. Subsequent interactions will unhide the error if the state is invalid.
		// If vat is not empty, then the state can be considered 'dirty' and we continue to show the error.
		const validationError = getValidationError( validationErrorId );
		if ( ! vat && validationError?.hidden ) {
			return;
		}

		if ( ! required && ! vat ) {
			clearValidationError( validationErrorId );
			return;
		}
		if ( extensions[ 'woocommerce-eu-vat-number' ]?.validation?.error ) {
			setValidationErrors( {
				[ validationErrorId ]: {
					message:
						extensions[ 'woocommerce-eu-vat-number' ]?.validation
							.error,
					hidden: false,
				},
			} );
			return;
		}
		clearValidationError( validationErrorId );
	}, [ extensions[ 'woocommerce-eu-vat-number' ]?.validation.error ] );

	/**
	 * This effect kicks off the init function when the component mounts for the first time.
	 */
	useEffect( init, [] );

	const onChange = ( event ) => {
		const { value: nextValue } = event.target;
		clearValidationError( validationErrorId );
		if (
			typeof nextValue === 'string' &&
			nextValue.length === 0 &&
			required
		) {
			setValidationErrors( {
				[ validationErrorId ]: {
					message: __(
						'VAT Number is required.',
						'woocommerce-eu-vat-number'
					),
					hidden: false,
				},
			} );
		}
		setVat( nextValue );
	};

	const HasError = () => {
		if ( ! hasError ) return null;
		return (
			<div className="wc-block-components-validation-error" role="alert">
				<p id={ validationErrorId }>
					{ getValidationError( validationErrorId )?.message }
				</p>
			</div>
		);
	};

	if ( ! available ) {
		return <></>;
	}

	return (
		<FormStep
			id="shipping-fields"
			className={ classnames(
				'wc-block-checkout__shipping-fields',
				className
			) }
			title={ title }
			description={ description }
			showStepNumber={ showStepNumber }
		>
			<div>
				<div
					className={ classnames(
						'wc-block-components-text-input',
						className,
						{
							'is-active': isActive || !! vat,
						},
						{
							'has-error': hasError,
						}
					) }
				>
					<input
						type="text"
						// eslint-disable-next-line camelcase
						aria-label={ input_label }
						id={ textInputId }
						value={ vat || '' }
						onChange={ ( event ) => {
							onChange( event );
						} }
						onFocus={ () => setIsActive( true ) }
						onBlur={ () => {
							setIsActive( false );
							verifyVat();
						} }
						aria-invalid={ hasError === true }
						disabled={ false }
						required={ required }
					/>

					<label htmlFor={ textInputId }>
						{ input_label }
						{ required === true ? null : ' (optional)' }
					</label>
					<HasError />
					<div className="wc-eu-vat-checkout-uk-notice">
						<div>
							<span>{ input_description }</span>
						</div>
						<span>{ showGBNotice ? uk_ni_notice : null }</span>
					</div>
				</div>
				{ ( ! vat && b2b_required === 'no' ) ||
				( ! vat && ! b2b_required ) ? (
					<IpAddressNotice
						validation={ props.validation }
						ipAddress={ ipAddress }
						ipCountry={ ipCountry }
						billingCountry={ billingAddress.country }
						shouldValidateIp={ shouldValidateIp }
						checkoutExtensionData={ checkoutExtensionData }
					/>
				) : null }
			</div>
		</FormStep>
	);
};

export default withInstanceId( VatInput );
