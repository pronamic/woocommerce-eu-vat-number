/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import './style.scss';
import { withFilteredAttributes } from '../../utils';
import attributes from '../form-step-block/attributes';

const StepHeading = ( { title, stepHeadingContent } ) => (
	<div className="wc-block-components-checkout-step__heading">
		<h2
			aria-hidden="true"
			className="wc-block-components-checkout-step__title wc-block-components-title"
		>
			{ title }
		</h2>
		{ !! stepHeadingContent && (
			<span className="wc-block-components-checkout-step__heading-content">
				{ stepHeadingContent }
			</span>
		) }
	</div>
);
const FormStep = ( {
	id,
	className,
	title,
	legend,
	description,
	children,
	disabled = false,
	showStepNumber = true,
	stepHeadingContent = () => undefined,
} ) => {
	// If the form step doesn't have a legend or title, render a <div> instead
	// of a <fieldset>.
	const Element = legend || title ? 'fieldset' : 'div';

	return (
		<Element
			className={ classnames(
				className,
				'wc-block-components-checkout-step',
				{
					'wc-block-components-checkout-step--with-step-number':
						showStepNumber,
					'wc-block-components-checkout-step--disabled': disabled,
				}
			) }
			id={ id }
			disabled={ disabled }
		>
			{ !! ( legend || title ) && (
				<legend className="screen-reader-text">
					{ legend || title }
				</legend>
			) }
			{ !! title && (
				<StepHeading
					title={ title }
					stepHeadingContent={ stepHeadingContent() }
				/>
			) }
			<div className="wc-block-components-checkout-step__container">
				{ !! description && (
					<p className="wc-block-components-checkout-step__description">
						{ description }
					</p>
				) }
				<div className="wc-block-components-checkout-step__content">
					{ children }
				</div>
			</div>
		</Element>
	);
};

export default withFilteredAttributes( attributes( {} ) )( FormStep );
