/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

const attributes = ( {
	defaultTitle = __( 'Step', 'woocommerce-eu-vat-number' ),
	defaultDescription = __(
		'Step description text.',
		'woocommerce-eu-vat-number'
	),
	defaultShowStepNumber = true,
} ) => ( {
	title: {
		type: 'string',
		default: defaultTitle,
	},
	description: {
		type: 'string',
		default: defaultDescription,
	},
	showStepNumber: {
		type: 'boolean',
		default: defaultShowStepNumber,
	},
} );

export default attributes;
