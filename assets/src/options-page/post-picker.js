/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl, Spinner } from '@wordpress/components';
import { useDebounce, useInstanceId } from '@wordpress/compose';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { mergeOptions, searchPath, searchStatus, toOption } from './logic';

const SEARCH_DELAY = 300;

/**
 * Label of a record without a title.
 *
 * @param {number} id Post ID.
 * @return {string} Label.
 */
function untitled( id ) {
	/* translators: %d: post ID. */
	return sprintf( __( '(no title, #%d)', 'pegwork' ), id );
}

/**
 * Searchable picker for one post ID. Replaces the server-rendered select; the
 * value travels in a hidden input with the select's name, so the settings form
 * posts it unchanged.
 *
 * @param {Object}       props              Props.
 * @param {Object}       props.config       Parsed picker config.
 * @param {string}       props.name         Input name, such as `sifg_settings[featured_project]`.
 * @param {?HTMLElement} props.label        Row label, re-pointed at the combobox input.
 * @param {?string}      props.describedBy  IDs of the description and error.
 * @param {boolean}      props.invalid      Whether the last save rejected this field.
 * @param {boolean}      props.required     Whether the field is required.
 * @param {string}       props.initialValue Selected ID as the select had it ('' for none).
 */
export default function PostPicker( {
	config,
	name,
	label,
	describedBy,
	invalid,
	required,
	initialValue,
} ) {
	const [ value, setValue ] = useState( initialValue || null );
	const [ selected, setSelected ] = useState(
		config.current && String( config.current.id ) === initialValue
			? { value: initialValue, label: config.current.title }
			: null
	);
	const [ search, setSearch ] = useState( null );
	const [ results, setResults ] = useState( [] );
	const [ isSearching, setSearching ] = useState( false );
	const [ hasFailed, setFailed ] = useState( false );
	const wrapper = useRef();
	const request = useRef( 0 );
	const helpId = `${ useInstanceId( PostPicker, 'pegwork-post-picker' ) }-help`;

	// Stable, so useDebounce keeps one debounced function across renders.
	const run = useCallback(
		( text ) => {
			const id = ++request.current;
			setSearching( true );
			setFailed( false );
			apiFetch( { path: searchPath( config, text ) } )
				.then( ( records ) => {
					if ( id !== request.current ) {
						return;
					}
					setResults(
						( Array.isArray( records ) ? records : [] ).map(
							( record ) =>
								toOption( record, decodeEntities, untitled )
						)
					);
					setSearching( false );
				} )
				.catch( () => {
					if ( id === request.current ) {
						setSearching( false );
						setFailed( true );
					}
				} );
		},
		[ config ]
	);
	const debouncedRun = useDebounce( run, SEARCH_DELAY );

	// The combobox input is created by ComboboxControl: point the row label at
	// it and give it the field's description, error and required state.
	useEffect( () => {
		const input = wrapper.current?.querySelector( 'input' );
		if ( ! input ) {
			return;
		}
		if ( label && input.id ) {
			label.htmlFor = input.id;
		}
		const ids = [
			helpId,
			describedBy,
			input.getAttribute( 'aria-describedby' ),
		]
			.filter( Boolean )
			.join( ' ' )
			.split( ' ' );
		input.setAttribute(
			'aria-describedby',
			[ ...new Set( ids ) ].filter( Boolean ).join( ' ' )
		);
		if ( invalid ) {
			input.setAttribute( 'aria-invalid', 'true' );
		}
		if ( required ) {
			input.setAttribute( 'aria-required', 'true' );
		}
	} );

	const onFilterValueChange = ( text ) => {
		const first = search === null;
		setSearch( text );
		if ( first || text.trim() === '' ) {
			debouncedRun.cancel();
			run( text );
		} else {
			debouncedRun( text );
		}
	};

	const onChange = ( next ) => {
		setValue( next || null );
		const option = mergeOptions( selected, results ).find(
			( item ) => item.value === next
		);
		setSelected( option || null );
	};

	const options = mergeOptions( selected, results );
	const status = searchStatus( { search, isSearching, hasFailed } );

	let statusLine = null;
	if ( status === 'loading' ) {
		statusLine = (
			<>
				<Spinner />
				{ __( 'Searching', 'pegwork' ) }
			</>
		);
	} else if ( status === 'error' ) {
		statusLine = __( 'Search failed. Try again.', 'pegwork' );
	}

	return (
		<div ref={ wrapper }>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				value={ value }
				options={ options }
				onChange={ onChange }
				onFilterValueChange={ onFilterValueChange }
			/>
			<input type="hidden" name={ name } value={ value ?? '' } />
			<p className="description" id={ helpId }>
				{ sprintf(
					/* translators: %s: singular label of the content type, such as "Project". */
					__(
						'Type to search published items (%s). Clear to choose none.',
						'pegwork'
					),
					config.singular
				) }
			</p>
			<p className="pegwork-post-picker__status" aria-live="polite">
				{ statusLine }
			</p>
		</div>
	);
}
