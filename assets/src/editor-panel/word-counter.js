/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { FieldLabel } from './field-control';
import { countWords, wordCountStatus } from './logic';

/**
 * Message for a word count against its limits.
 *
 * @param {number}                       count  Word count.
 * @param {{min: ?number, max: ?number}} limits Limits.
 * @return {string} Message.
 */
function message( count, limits ) {
	if ( limits.min !== null && limits.max !== null ) {
		return sprintf(
			/* translators: 1: current word count, 2: minimum, 3: maximum. */
			_n(
				'%1$d of %2$d to %3$d words',
				'%1$d of %2$d to %3$d words',
				count,
				'pegwork'
			),
			count,
			limits.min,
			limits.max
		);
	}
	if ( limits.min !== null ) {
		return sprintf(
			/* translators: 1: current word count, 2: minimum. */
			_n(
				'%1$d word, at least %2$d needed',
				'%1$d words, at least %2$d needed',
				count,
				'pegwork'
			),
			count,
			limits.min
		);
	}
	return sprintf(
		/* translators: 1: current word count, 2: maximum. */
		_n(
			'%1$d of up to %2$d words',
			'%1$d of up to %2$d words',
			count,
			'pegwork'
		),
		count,
		limits.max
	);
}

/**
 * Live word counter for a field stored in post_content.
 *
 * @param {Object}                       props        Props.
 * @param {Object}                       props.field  The field.
 * @param {{min: ?number, max: ?number}} props.limits Word limits.
 */
export default function WordCounter( { field, limits } ) {
	const content = useSelect(
		( select ) => select( editorStore ).getEditedPostAttribute( 'content' ),
		[]
	);
	const count = countWords( content );
	const status = wordCountStatus( count, limits );

	return (
		<div className="pegwork-editor-panel__word-counter">
			<FieldLabel field={ field } required={ field.required } />
			<p
				className={ `pegwork-editor-panel__count is-${ status }` }
				aria-live="polite"
			>
				{ message( count, limits ) }
			</p>
			<p className="pegwork-editor-panel__help">
				{ __( 'Edit this in the main content area.', 'pegwork' ) }
			</p>
		</div>
	);
}
