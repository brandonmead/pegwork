/**
 * Pure field logic: values, conditions, word counts. No WordPress imports, so
 * it runs in unit tests as is.
 */

/**
 * Current value of a meta-stored field, falling back to its default.
 *
 * @param {Object[]} fields All fields of the type.
 * @param {Object}   meta   Current post meta, keyed by meta key.
 * @param {string}   key    Field key.
 * @return {unknown} The value, or undefined for an unknown or non-meta field.
 */
export function fieldValue( fields, meta, key ) {
	const field = fields.find( ( candidate ) => candidate.key === key );
	if ( ! field || field.storage !== 'meta' ) {
		return undefined;
	}
	const value = meta ? meta[ field.meta_key ] : undefined;
	return value === undefined || value === null ? field.default : value;
}

/**
 * Whether a `{field, equals}` condition holds.
 *
 * @param {?Object}  condition Condition, or null.
 * @param {Object[]} fields    All fields of the type.
 * @param {Object}   meta      Current post meta.
 * @return {boolean} True when the referenced field equals the value.
 */
export function conditionMet( condition, fields, meta ) {
	if ( ! condition ) {
		return false;
	}
	return fieldValue( fields, meta, condition.field ) === condition.equals;
}

/**
 * Whether a field shows, given its `show_when`.
 *
 * @param {Object}   field  The field.
 * @param {Object[]} fields All fields of the type.
 * @param {Object}   meta   Current post meta.
 * @return {boolean} True when the field has no condition or the condition holds.
 */
export function isVisible( field, fields, meta ) {
	return ! field.show_when || conditionMet( field.show_when, fields, meta );
}

/**
 * Whether a field is currently required, from `required` or `required_when`.
 *
 * @param {Object}   field  The field.
 * @param {Object[]} fields All fields of the type.
 * @param {Object}   meta   Current post meta.
 * @return {boolean} True when the field is required now.
 */
export function isRequired( field, fields, meta ) {
	return field.required || conditionMet( field.required_when, fields, meta );
}

/**
 * Whether a value counts as empty for the required hint.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for undefined, null, empty string or empty array.
 */
export function isEmpty( value ) {
	return (
		value === undefined ||
		value === null ||
		value === '' ||
		( Array.isArray( value ) && value.length === 0 )
	);
}

/**
 * Counts words in post content: block comments and tags are stripped, the rest
 * is split on whitespace.
 *
 * @param {unknown} content Post content (HTML with block delimiters).
 * @return {number} Word count.
 */
export function countWords( content ) {
	if ( typeof content !== 'string' ) {
		return 0;
	}
	const text = content
		.replace( /<!--[\s\S]*?-->/g, ' ' )
		.replace( /<[^>]*>/g, ' ' )
		.replace( /&nbsp;|&#160;/gi, ' ' )
		.trim();
	return text === '' ? 0 : text.split( /\s+/ ).length;
}

/**
 * Word limits from a field's `validate` rules.
 *
 * @param {Object} field The field.
 * @return {?{min: ?number, max: ?number}} Limits, or null when the field has none.
 */
export function wordLimits( field ) {
	const rules = field.validate || {};
	const min = Number.isInteger( rules.min_words ) ? rules.min_words : null;
	const max = Number.isInteger( rules.max_words ) ? rules.max_words : null;
	return min === null && max === null ? null : { min, max };
}

/**
 * Where a word count sits against its limits.
 *
 * @param {number}                       count  Word count.
 * @param {{min: ?number, max: ?number}} limits Limits.
 * @return {'under'|'over'|'ok'} Status.
 */
export function wordCountStatus( count, limits ) {
	if ( limits.min !== null && count < limits.min ) {
		return 'under';
	}
	if ( limits.max !== null && count > limits.max ) {
		return 'over';
	}
	return 'ok';
}
