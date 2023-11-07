/**
 * External dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';
import classnames from 'classnames';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './index.scss';
import block from '../block.json';
import { FormStepBlock } from './components/form-step-block';
import formStepAttributes from './components/form-step-block/attributes';

const Edit = ( props ) => {
	const blockProps = useBlockProps();
	return (
		<div { ...blockProps }>
			<FormStepBlock
				attributes={ props.attributes }
				setAttributes={ props.setAttributes }
				className={ classnames(
					'wc-block-checkout__vat-input',
					props.attributes?.className
				) }
			>
				<div className="wc-block-components-text-input">
					<input
						type="text"
						aria-label="VAT Number"
						id="woocommerce-eu-vat-number"
					/>
					<label htmlFor="woocommerce-eu-vat-number">
						{ __( 'VAT Number', 'woocommerce-eu-vat-number' ) }
					</label>
				</div>
			</FormStepBlock>
		</div>
	);
};
const Save = () => {
	return (
		<div { ...useBlockProps.save() }>
			<InnerBlocks.Content />
		</div>
	);
};

registerBlockType( block, {
	edit: Edit,
	attributes: {
		...block.attributes,
		...formStepAttributes( {
			defaultTitle: __( 'VAT Number', 'woocommerce-eu-vat-number' ),
			defaultDescription: '',
			defaultShowStepNumber: true,
		} ),
	},
	save: Save,
} );
