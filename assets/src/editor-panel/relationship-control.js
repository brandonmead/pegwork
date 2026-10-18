/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { Button, ComboboxControl, Spinner } from '@wordpress/components';
import { useDebounce, useInstanceId } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { FieldError, FieldLabel } from './field-control';
import {
	addLink,
	focusAfterRemove,
	linkedIds,
	linkedQuery,
	removeLink,
	searchQuery,
	searchStatus,
	suggestionItems,
	toItem,
} from './relationship-logic';

const NO_RECORDS = [];
const SEARCH_DELAY = 300;

/**
 * Visible label of a non-published status.
 *
 * @param {string} status Post status.
 * @return {?string} Label, or null for published records.
 */
function statusLabel( status ) {
	switch ( status ) {
		case 'publish':
			return null;
		case 'draft':
			return __( 'Draft', 'pegwork' );
		case 'pending':
			return __( 'Pending', 'pegwork' );
		case 'future':
			return __( 'Scheduled', 'pegwork' );
		case 'private':
			return __( 'Private', 'pegwork' );
		case 'trash':
			return __( 'Trash', 'pegwork' );
		default:
			return status;
	}
}

/**
 * Display title of an item.
 *
 * @param {{title: string}} item Picker item.
 * @return {string} Title, or "(no title)".
 */
function itemTitle( item ) {
	return item.title !== '' ? item.title : __( '(no title)', 'pegwork' );
}

/**
 * Combobox option label: the title, plus the status when not published.
 *
 * @param {Object} item Picker item.
 * @return {string} Label.
 */
function optionLabel( item ) {
	const status = statusLabel( item.status );
	return status
		? sprintf(
				/* translators: 1: record title, 2: status such as "Draft". */
				__( '%1$s (%2$s)', 'pegwork' ),
				itemTitle( item ),
				status
			)
		: itemTitle( item );
}

/**
 * Records of a query, with whether it is still loading or failed. Returns the
 * store's own records array, so useSelect's shallow compare stays stable.
 *
 * @param {(store: Object) => Object} select   useSelect's select.
 * @param {string}                    postType "To" post type.
 * @param {Object}                    query    getEntityRecords query.
 * @return {{records: ?Object[], isResolving: boolean, hasFailed: boolean}} Result; records null until loaded.
 */
function selectRecords( select, postType, query ) {
	const store = select( coreStore );
	const args = [ 'postType', postType, query ];
	const hasFailed =
		typeof store.hasResolutionFailed === 'function' &&
		store.hasResolutionFailed( 'getEntityRecords', args );

	return {
		records: store.getEntityRecords( ...args ),
		isResolving:
			! hasFailed &&
			! store.hasFinishedResolution( 'getEntityRecords', args ),
		hasFailed,
	};
}

/**
 * Picker items of a query result.
 *
 * @param {?{records: ?Object[]}} result selectRecords() result, or null.
 * @return {?Object[]} Items, or null until loaded.
 */
function itemsOf( result ) {
	return result && result.records
		? result.records.map( ( record ) => toItem( record, decodeEntities ) )
		: null;
}

/**
 * One relationship: linked records with Remove buttons, and a searchable
 * combobox to add more. The value is the `_rel_{key}` meta, so links save in
 * the same REST request as the post.
 *
 * @param {Object}                  props              Props.
 * @param {Object}                  props.relationship Relationship entry from the config.
 * @param {unknown}                 props.value        Current meta value (array of IDs).
 * @param {(ids: number[]) => void} props.onChange     Receives the new list of IDs.
 * @param {string}                  [props.error]      Server validation message from the last save.
 */
export default function RelationshipControl( {
	relationship,
	value,
	onChange,
	error,
} ) {
	const ids = linkedIds( value );
	const idsKey = ids.join( ',' );
	const instanceId = useInstanceId(
		RelationshipControl,
		'pegwork-relationship'
	);
	const headingId = `${ instanceId }-heading`;

	// Search text as typed (null until the combobox is first focused), and as last queried.
	const [ search, setSearch ] = useState( null );
	const [ debounced, setDebounced ] = useState( null );
	const debouncedSetSearch = useDebounce( setDebounced, SEARCH_DELAY );

	// Titles seen in any query, so a row renders at once after it is added.
	const known = useRef( new Map() );
	const lastResults = useRef( NO_RECORDS );
	const removeButtons = useRef( new Map() );
	const comboboxWrapper = useRef();
	const pending = useRef( { focus: undefined, message: null } );

	const searchResult = useSelect(
		( select ) =>
			debounced === null
				? null
				: selectRecords(
						select,
						relationship.to,
						searchQuery( debounced )
					),
		[ relationship.to, debounced ]
	);
	const linkedResult = useSelect(
		( select ) =>
			idsKey === ''
				? null
				: selectRecords(
						select,
						relationship.to,
						linkedQuery( idsKey.split( ',' ).map( Number ) )
					),
		[ relationship.to, idsKey ]
	);
	const searchItems = itemsOf( searchResult );
	const linkedItems = itemsOf( linkedResult ) || NO_RECORDS;

	if ( searchItems ) {
		lastResults.current = searchItems;
	}
	[ lastResults.current, linkedItems ].forEach( ( items ) =>
		items.forEach( ( item ) => known.current.set( item.id, item ) )
	);

	// Focus and announcements wait for the render that shows the change. For an
	// add, this also lets ours follow ComboboxControl's own "selected" message.
	useEffect( () => {
		const { focus, message } = pending.current;
		pending.current = { focus: undefined, message: null };
		if ( message ) {
			speak( message, 'assertive' );
		}
		if ( focus === null ) {
			comboboxWrapper.current?.querySelector( 'input' )?.focus();
		} else if ( focus !== undefined ) {
			removeButtons.current.get( focus )?.focus();
		}
	} );

	const options = suggestionItems(
		lastResults.current,
		ids,
		search,
		optionLabel
	).map( ( item ) => ( {
		value: String( item.id ),
		label: optionLabel( item ),
	} ) );

	const status = searchStatus( {
		search,
		debounced,
		isSearching: !! searchResult?.isResolving,
		hasFailed: !! searchResult?.hasFailed,
		count: options.length,
	} );

	const onFilterValueChange = ( text ) => {
		setSearch( text );
		if ( text.trim() === '' ) {
			debouncedSetSearch.cancel();
			setDebounced( '' );
		} else {
			debouncedSetSearch( text );
		}
	};

	const add = ( selected ) => {
		const id = parseInt( selected, 10 );
		const next = addLink( ids, id );
		if ( next === ids ) {
			return;
		}
		const item = known.current.get( id );
		onChange( next );
		setSearch( '' );
		debouncedSetSearch.cancel();
		setDebounced( '' );
		pending.current.message = sprintf(
			/* translators: %s: record title. */
			__( 'Linked %s', 'pegwork' ),
			item ? itemTitle( item ) : `#${ id }`
		);
	};

	const remove = ( id, index, title ) => {
		const next = removeLink( ids, id );
		onChange( next );
		pending.current = {
			focus: focusAfterRemove( next, index ),
			message: sprintf(
				/* translators: %s: record title. */
				__( 'Removed %s', 'pegwork' ),
				title
			),
		};
	};

	let statusLine;
	if ( status === 'loading' ) {
		statusLine = (
			<span className="pegwork-editor-panel__searching">
				<Spinner />
				{ __( 'Searching', 'pegwork' ) }
			</span>
		);
	} else if ( status === 'error' ) {
		statusLine = (
			<FieldError
				message={ __( 'Search failed. Try again.', 'pegwork' ) }
			/>
		);
	} else if ( status === 'no-matches' ) {
		statusLine = sprintf(
			/* translators: %s: search text. */
			__( 'No matches for "%s".', 'pegwork' ),
			search.trim()
		);
	}

	const linkedLoading = !! linkedResult?.isResolving;
	const linkedFailed = !! linkedResult?.hasFailed;
	const fetched = new Set( linkedItems.map( ( item ) => item.id ) );

	return (
		<div className="pegwork-editor-panel__relationship">
			<h3 id={ headingId } className="pegwork-editor-panel__heading">
				<FieldLabel
					field={ {
						label: relationship.label,
						visibility: 'public',
					} }
					required={ relationship.required }
				/>
			</h3>
			{ ids.length === 0 ? (
				<p className="pegwork-editor-panel__help">
					{ __( 'None linked yet.', 'pegwork' ) }
					{ relationship.required &&
						' ' + __( 'Required to publish.', 'pegwork' ) }
				</p>
			) : (
				<ul
					className="pegwork-editor-panel__links"
					aria-labelledby={ headingId }
				>
					{ ids.map( ( id, index ) => {
						const item = known.current.get( id );
						const missing =
							! item &&
							! linkedLoading &&
							! linkedFailed &&
							! fetched.has( id );
						let title;
						if ( item ) {
							title = itemTitle( item );
						} else if ( missing ) {
							title = sprintf(
								/* translators: %d: record ID. */
								__( '(missing record #%d)', 'pegwork' ),
								id
							);
						} else {
							title = `#${ id }`;
						}
						const badge = item ? statusLabel( item.status ) : null;
						return (
							<li
								key={ id }
								className="pegwork-editor-panel__link"
							>
								<span className="pegwork-editor-panel__link-title">
									{ missing ? (
										title
									) : (
										<a
											href={ addQueryArgs( 'post.php', {
												post: id,
												action: 'edit',
											} ) }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ title }
											<span className="pegwork-editor-panel__new-tab">
												{ ' ' }
												{ __(
													'(opens in new tab)',
													'pegwork'
												) }
											</span>
										</a>
									) }
									{ badge && (
										<span className="pegwork-editor-panel__badge is-status">
											{ badge }
										</span>
									) }
								</span>
								<Button
									ref={ ( element ) => {
										if ( element ) {
											removeButtons.current.set(
												id,
												element
											);
										} else {
											removeButtons.current.delete( id );
										}
									} }
									variant="tertiary"
									size="compact"
									isDestructive
									aria-label={ sprintf(
										/* translators: %s: record title. */
										__( 'Remove %s', 'pegwork' ),
										title
									) }
									onClick={ () => remove( id, index, title ) }
								>
									{ __( 'Remove', 'pegwork' ) }
								</Button>
							</li>
						);
					} ) }
				</ul>
			) }
			{ linkedLoading &&
				ids.some( ( id ) => ! known.current.has( id ) ) && (
					<p className="pegwork-editor-panel__help">
						<Spinner />
						{ __( 'Loading linked records', 'pegwork' ) }
					</p>
				) }
			{ linkedFailed && (
				<p className="pegwork-editor-panel__help">
					<FieldError
						message={ __(
							'Could not load linked records.',
							'pegwork'
						) }
					/>
				</p>
			) }
			{ error && (
				<p className="pegwork-editor-panel__help">
					<FieldError message={ error } />
				</p>
			) }
			<div ref={ comboboxWrapper }>
				<ComboboxControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ sprintf(
						/* translators: %s: singular label of the linked type, such as "Project". */
						__( 'Add %s', 'pegwork' ),
						relationship.to_label_singular
					) }
					value={ null }
					options={ options }
					onChange={ ( selected ) => selected && add( selected ) }
					onFilterValueChange={ onFilterValueChange }
					allowReset={ false }
					// Focus lands here after a removal; typing or ArrowDown opens the list.
					expandOnFocus={ false }
					// Our own "Linked {title}" follows; an empty message avoids announcing twice.
					messages={ { selected: '' } }
					help={ statusLine }
				/>
			</div>
		</div>
	);
}
