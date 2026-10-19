/**
 * Translated names of field types (variation titles, badges, rule summaries).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * The name of a field type as the inserter shows it.
 *
 * @param {string} type Field type.
 * @return {string} Name.
 */
export function typeLabel( type ) {
	const labels = {
		text: __( 'Text', 'pegwork' ),
		email: __( 'Email', 'pegwork' ),
		textarea: __( 'Paragraph text', 'pegwork' ),
		rich_text: __( 'Paragraph text', 'pegwork' ),
		url: __( 'URL', 'pegwork' ),
		number: __( 'Number', 'pegwork' ),
		date: __( 'Date', 'pegwork' ),
		boolean: __( 'Checkbox', 'pegwork' ),
		select: __( 'Dropdown', 'pegwork' ),
		file: __( 'File', 'pegwork' ),
	};
	return labels[ type ] || type;
}

/**
 * Inserter descriptions of the type variations.
 *
 * @param {string} type Field type.
 * @return {string} Description.
 */
export function typeDescription( type ) {
	const descriptions = {
		text: __( 'One line of text.', 'pegwork' ),
		email: __( 'An email address.', 'pegwork' ),
		textarea: __( 'Several lines of text.', 'pegwork' ),
		url: __( 'A web address (http or https).', 'pegwork' ),
		number: __( 'A number.', 'pegwork' ),
		date: __( 'A date.', 'pegwork' ),
		boolean: __( 'A single checkbox, such as consent.', 'pegwork' ),
		select: __( 'One choice from a list.', 'pegwork' ),
		file: __( 'A file upload.', 'pegwork' ),
	};
	return descriptions[ type ] || '';
}
