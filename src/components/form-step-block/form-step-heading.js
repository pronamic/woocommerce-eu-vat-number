/* eslint-disable jsdoc/require-param */
/**
 * External dependencies
 */

/**
 * Step Heading Component
 */
const FormStepHeading = ( { children, stepHeadingContent } ) => (
	<div className="wc-block-components-checkout-step__heading">
		<h2
			aria-hidden="true"
			className="wc-block-components-checkout-step__title wc-block-components-title"
		>
			{ children }
		</h2>
		{ !! stepHeadingContent && (
			<span className="wc-block-components-checkout-step__heading-content">
				{ stepHeadingContent }
			</span>
		) }
	</div>
);

export default FormStepHeading;
