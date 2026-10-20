/**
 * `show_when` rules, mirroring PegworkForms\Definition::shown() and ::visible() in PHP. Both sides run
 * the rules table tests/fixtures/forms/conditions.json, so the browser shows and hides exactly what
 * the server keeps and drops. Values are sanitized values: booleans for checkboxes, strings, numbers,
 * lists of option values for multi-choice items, undefined or null when missing.
 */

/**
 * How deep a chain of conditions may go (PHP `$depth < 10`).
 */
const MAX_DEPTH = 10;

/**
 * PHP is_numeric() for a value: numbers, and strings such as " 3", "3.", ".5", "+3", "3e2" with
 * optional surrounding whitespace (PHP 8). Booleans, hex and empty strings are not numeric.
 *
 * @param {unknown} value Value.
 * @return {boolean} Whether PHP would call it numeric.
 */
export function isNumeric( value ) {
	if ( typeof value === 'number' ) {
		return Number.isFinite( value );
	}
	if ( typeof value !== 'string' ) {
		return false;
	}
	return /^[ \t\n\r\v\f]*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?[ \t\n\r\v\f]*$/.test(
		value
	);
}

/**
 * PHP (bool) cast for a scalar or null: "", "0", 0 and null are false.
 *
 * @param {unknown} value Value.
 * @return {boolean} PHP truthiness.
 */
export function phpBool( value ) {
	if ( value === undefined || value === null ) {
		return false;
	}
	if ( typeof value === 'string' ) {
		return value !== '' && value !== '0';
	}
	if ( typeof value === 'number' ) {
		return value !== 0;
	}
	return Boolean( value );
}

/**
 * PHP (string) cast for a scalar or null: true is "1", false and null are "".
 *
 * @param {unknown} value Value.
 * @return {string} PHP string form.
 */
export function phpString( value ) {
	if ( value === undefined || value === null || value === false ) {
		return '';
	}
	if ( value === true ) {
		return '1';
	}
	return String( value );
}

/**
 * Whether an item's condition holds for these values (true without a condition).
 *
 * @param {?Object}                 condition `{ field, equals }`, or null.
 * @param {Object<string, unknown>} values    Values by item key.
 * @return {boolean} Whether the item is shown.
 */
export function shown( condition, values ) {
	if (
		! condition ||
		typeof condition !== 'object' ||
		typeof condition.field !== 'string'
	) {
		return true;
	}
	const has = Object.prototype.hasOwnProperty.call(
		values || {},
		condition.field
	);
	const value = has ? values[ condition.field ] : null;
	const equals = condition.equals === undefined ? null : condition.equals;

	if ( Array.isArray( value ) ) {
		if ( typeof equals === 'boolean' ) {
			return value.length > 0 === equals;
		}
		return value.map( phpString ).includes( phpString( equals ) );
	}
	if ( typeof equals === 'boolean' ) {
		return equals === phpBool( value );
	}
	if ( typeof equals === 'number' ) {
		return isNumeric( value ) && Number( value ) === equals;
	}
	return value !== null && phpString( value ) === phpString( equals );
}

/**
 * Which items are shown: an item with a condition is shown when the item it names exists, is itself
 * shown, and the condition holds. Same evaluation order and depth limit as PHP.
 *
 * @param {Array<{key: string, show_when?: ?Object}>} items  Items in form order.
 * @param {Object<string, unknown>}                   values Values by key (every item).
 * @return {Object<string, boolean>} Shown flag by key, in form order.
 */
export function visible( items, values ) {
	const byKey = new Map( items.map( ( item ) => [ item.key, item ] ) );
	const memo = new Map();

	const check = ( key, depth ) => {
		if ( memo.has( key ) ) {
			return memo.get( key );
		}
		const condition = byKey.get( key ).show_when;
		let result = true;
		if ( condition && typeof condition.field === 'string' ) {
			result =
				depth < MAX_DEPTH &&
				byKey.has( condition.field ) &&
				check( condition.field, depth + 1 ) &&
				shown( condition, values );
		}
		memo.set( key, result );
		return result;
	};

	const result = {};
	for ( const key of byKey.keys() ) {
		result[ key ] = check( key, 0 );
	}
	return result;
}
