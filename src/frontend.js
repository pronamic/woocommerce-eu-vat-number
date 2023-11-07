/**
 * External dependencies
 */
import { registerCheckoutBlock } from '@woocommerce/blocks-checkout';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import blockMetadata from '../block.json';
import VatInput from './components/vat-input';
import formStepAttributes from './components/form-step-block/attributes';

const options = {
	metadata: {
		...blockMetadata,
		attributes: {
			...blockMetadata.attributes,
			...formStepAttributes( {
				defaultTitle: __( 'VAT Number', 'woocommerce-eu-vat-number' ),
				defaultDescription: '',
				defaultShowStepNumber: true,
			} ),
		},
	},
	component: ( props ) => {
		return <VatInput isEditing={ false } { ...props } />;
	},
};

registerCheckoutBlock( options );
