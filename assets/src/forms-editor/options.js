/**
 * Pure helpers for the pegwork/form edit UI.
 */

/**
 * Normalizes the inline config (window.pegworkForms).
 *
 * @param {unknown} raw Config from PHP.
 * @return {{forms: Array<{key: string, label: string, supported: boolean}>}} Config.
 */
export function parseConfig( raw ) {
	const forms = Array.isArray( raw?.forms ) ? raw.forms : [];
	return {
		forms: forms
			.filter(
				( form ) =>
					form &&
					typeof form.key === 'string' &&
					form.key !== '' &&
					typeof form.label === 'string'
			)
			.map( ( form ) => ( {
				key: form.key,
				label: form.label,
				supported: form.supported !== false,
			} ) ),
	};
}

/**
 * Options for the form picker: a placeholder, then each form as "Label (key)". Forms this release
 * cannot show are marked, and the current key stays listed even when it is no longer defined.
 *
 * @param {Array<{key: string, label: string, supported: boolean}>} forms        Forms.
 * @param {string}                                                  current      Selected key.
 * @param {Object}                                                  labels       Translated strings.
 * @param {string}                                                  labels.none  Placeholder label.
 * @param {(label: string) => string}                               labels.later Formats an unsupported form's label.
 * @param {(key: string) => string}                                 labels.gone  Formats a missing key's label.
 * @return {Array<{value: string, label: string}>} SelectControl options.
 */
export function formOptions( forms, current, labels ) {
	const options = [ { value: '', label: labels.none } ];
	for ( const form of forms ) {
		const label = `${ form.label } (${ form.key })`;
		options.push( {
			value: form.key,
			label: form.supported ? label : labels.later( label ),
		} );
	}
	if ( current && ! forms.some( ( form ) => form.key === current ) ) {
		options.push( { value: current, label: labels.gone( current ) } );
	}
	return options;
}
