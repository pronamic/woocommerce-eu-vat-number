export const validateCountryVatNumberFormat = ( country, vatNumber ) => {
	const regexExpressions = {
		AT: 'U[A-Z\\d]{8}',
		BE: '0\\d{9}',
		BG: '\\d{9,10}',
		CY: '\\d{8}[A-Z]',
		CZ: '\\d{8,10}',
		DE: '\\d{9}',
		DK: '(\\d{2} ?){3}\\d{2}',
		EE: '\\d{9}',
		EL: '\\d{9}',
		ES: '[A-Z]\\d{7}[A-Z]|\\d{8}[A-Z]|[A-Z]\\d{8}',
		FI: '\\d{8}',
		FR: '([A-Z]{2}|[A-Z0-9]{2})\\d{9}',
		XI: '\\d{9}|\\d{12}|(GD|HA)\\d{3}',
		HR: '\\d{11}',
		HU: '\\d{8}',
		IE: '[A-Z\\d]{8,10}',
		IT: '\\d{11}',
		LT: '(\\d{9}|\\d{12})',
		LU: '\\d{8}',
		LV: '\\d{11}',
		MT: '\\d{8}',
		NL: '\\d{9}B\\d{2}',
		PL: '\\d{10}',
		PT: '\\d{9}',
		RO: '\\d{2,10}',
		SE: '\\d{12}',
		SI: '\\d{8}',
		SK: '\\d{10}',
	};

	if ( regexExpressions[ country ] ) {
		const regex = new RegExp( regexExpressions[ country ] );
		const match = regex.exec( vatNumber );
		return match !== null;
	}
	return false;
};

/**
 * Given some block attributes, gets attributes from the dataset or uses defaults.
 *
 * @param {Object} blockAttributes Object containing block attributes.
 * @param {Array}  rawAttributes   Dataset from DOM.
 * @return {Array} Array of parsed attributes.
 */
export const getValidBlockAttributes = ( blockAttributes, rawAttributes ) => {
	const attributes = [];

	Object.keys( blockAttributes ).forEach( ( key ) => {
		if ( typeof rawAttributes[ key ] !== 'undefined' ) {
			switch ( blockAttributes[ key ].type ) {
				case 'boolean':
					attributes[ key ] =
						rawAttributes[ key ] !== 'false' &&
						rawAttributes[ key ] !== false;
					break;
				case 'number':
					attributes[ key ] = parseInt( rawAttributes[ key ], 10 );
					break;
				case 'array':
				case 'object':
					attributes[ key ] = JSON.parse( rawAttributes[ key ] );
					break;
				default:
					attributes[ key ] = rawAttributes[ key ];
					break;
			}
		} else {
			attributes[ key ] = blockAttributes[ key ].default;
		}
	} );

	return attributes;
};

/**
 * HOC that filters given attributes by valid block attribute values, or uses defaults if undefined.
 *
 * @param {Object} blockAttributes Component being wrapped.
 */
export const withFilteredAttributes =
	( blockAttributes ) => ( OriginalComponent ) => {
		return ( ownProps ) => {
			const validBlockAttributes = getValidBlockAttributes(
				blockAttributes,
				ownProps
			);

			return (
				<OriginalComponent
					{ ...ownProps }
					{ ...validBlockAttributes }
				/>
			);
		};
	};
