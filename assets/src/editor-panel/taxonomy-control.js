/**
 * WordPress dependencies
 */
import { CheckboxControl, RadioControl, Spinner } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { FieldLabel } from './field-control';

const NO_TERMS = [];

const TERM_QUERY = {
	per_page: -1,
	orderby: 'id',
	order: 'asc',
	_fields: 'id,name',
	context: 'view',
};

/**
 * Term picker for one taxonomy: radios for single-select, checkboxes for multi-select.
 *
 * @param {Object} props          Props.
 * @param {Object} props.taxonomy Taxonomy entry from the config.
 */
export default function TaxonomyControl( { taxonomy } ) {
	const { terms, selected } = useSelect(
		( select ) => ( {
			terms: select( coreStore ).getEntityRecords(
				'taxonomy',
				taxonomy.key,
				TERM_QUERY
			),
			selected:
				select( editorStore ).getEditedPostAttribute(
					taxonomy.rest_base
				) || NO_TERMS,
		} ),
		[ taxonomy.key, taxonomy.rest_base ]
	);
	const { editPost } = useDispatch( editorStore );
	const setTerms = ( ids ) => editPost( { [ taxonomy.rest_base ]: ids } );
	const isMissing = taxonomy.required && selected.length === 0;
	const label = (
		<FieldLabel
			field={ { label: taxonomy.label, visibility: 'public' } }
			required={ taxonomy.required }
		/>
	);
	const help = isMissing
		? __( 'Required to publish.', 'pegwork' )
		: undefined;

	if ( terms === null ) {
		return (
			<div className="pegwork-editor-panel__taxonomy">
				{ label }
				<Spinner />
			</div>
		);
	}

	if ( taxonomy.select === 'single' ) {
		const options = terms.map( ( term ) => ( {
			label: term.name,
			value: String( term.id ),
		} ) );
		if ( ! taxonomy.required ) {
			options.unshift( { label: __( 'None', 'pegwork' ), value: '' } );
		}
		return (
			<RadioControl
				className="pegwork-editor-panel__taxonomy"
				label={ label }
				help={ help }
				options={ options }
				selected={ selected.length ? String( selected[ 0 ] ) : '' }
				onChange={ ( value ) =>
					setTerms( value === '' ? [] : [ parseInt( value, 10 ) ] )
				}
			/>
		);
	}

	return (
		<fieldset className="pegwork-editor-panel__taxonomy">
			<legend>{ label }</legend>
			{ terms.map( ( term ) => (
				<CheckboxControl
					key={ term.id }
					__nextHasNoMarginBottom
					label={ term.name }
					checked={ selected.includes( term.id ) }
					onChange={ ( checked ) =>
						setTerms(
							checked
								? [ ...selected, term.id ]
								: selected.filter( ( id ) => id !== term.id )
						)
					}
				/>
			) ) }
			{ help && <p className="pegwork-editor-panel__help">{ help }</p> }
		</fieldset>
	);
}
