/**
 * Field type to control mapping, and value conversion between meta and inputs.
 * Control names refer to `@wordpress/components` exports; field-control.js maps
 * them to components.
 */

const CONTROLS = {
	text: { control: 'TextControl', inputType: 'text' },
	textarea: { control: 'TextareaControl' },
	rich_text: { control: 'TextareaControl' },
	url: { control: 'TextControl', inputType: 'url' },
	email: { control: 'TextControl', inputType: 'email' },
	number: { control: 'TextControl', inputType: 'number' },
	boolean: { control: 'ToggleControl' },
	date: { control: 'TextControl', inputType: 'date' },
	select: { control: 'SelectControl' },
};

/**
 * Control for a field type. Unknown types fall back to a text input.
 *
 * @param {string} type Field type.
 * @return {{control: string, inputType?: string}} Control name and input type.
 */
export function controlFor( type ) {
	return Object.prototype.hasOwnProperty.call( CONTROLS, type )
		? CONTROLS[ type ]
		: CONTROLS.text;
}

/**
 * Converts a stored value to what the control expects.
 *
 * @param {string}  type  Field type.
 * @param {unknown} value Stored value (or default).
 * @return {string|boolean} Control value.
 */
export function toControlValue( type, value ) {
	if ( type === 'boolean' ) {
		return value === true;
	}
	return value === undefined || value === null ? '' : String( value );
}

/**
 * Converts a control value to what is stored in meta.
 *
 * An empty number becomes null, which deletes the meta value on save.
 *
 * @param {string}         type  Field type.
 * @param {string|boolean} input Control value.
 * @return {string|number|boolean|null} Meta value.
 */
export function fromControlValue( type, input ) {
	if ( type === 'boolean' ) {
		return input === true;
	}
	if ( type === 'number' ) {
		if ( input === '' || input === null || input === undefined ) {
			return null;
		}
		const number = Number( input );
		return Number.isFinite( number ) ? number : null;
	}
	return input === null || input === undefined ? '' : String( input );
}
