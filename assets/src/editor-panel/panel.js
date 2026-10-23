/**
 * WordPress dependencies
 */
import { Slot } from '@wordpress/components';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getTypeConfig } from './config';
import FieldControl, { FieldError } from './field-control';
import {
	fieldValue,
	isRequired,
	isVisible,
	saveErrors,
	wordLimits,
} from './logic';
import RelationshipControl from './relationship-control';
import TaxonomyControl from './taxonomy-control';
import WordCounter from './word-counter';

/**
 * Panel body for a post type that has a config.
 *
 * @param {Object} props            Props.
 * @param {string} props.postType   Current post type.
 * @param {Object} props.typeConfig Config for the post type.
 */
function TypePanel( { postType, typeConfig } ) {
	const { fields, taxonomies, relationships } = typeConfig;
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const { removeEditorPanel } = useDispatch( editorStore );

	useEffect( () => {
		taxonomies.forEach( ( taxonomy ) =>
			removeEditorPanel( `taxonomy-panel-${ taxonomy.key }` )
		);
	}, [ taxonomies, removeEditorPanel ] );

	const saveError = useSelect(
		( select ) =>
			select( coreStore ).getLastEntitySaveError(
				'postType',
				postType,
				select( editorStore ).getCurrentPostId()
			),
		[ postType ]
	);
	const errors = saveErrors( saveError );
	const values = meta || {};

	return (
		<PluginDocumentSettingPanel
			name="pegwork-fields"
			title={ __( 'Details', 'pegwork' ) }
			className="pegwork-editor-panel"
		>
			{ fields.map( ( field ) => {
				const error = errors[ field.key ];
				const limits =
					field.storage === 'post_content'
						? wordLimits( field )
						: null;
				if ( limits ) {
					return (
						<WordCounter
							key={ field.key }
							field={ field }
							limits={ limits }
							error={ error }
						/>
					);
				}
				if ( field.storage !== 'meta' ) {
					return error ? (
						<p
							key={ field.key }
							className="pegwork-editor-panel__help"
						>
							<FieldError
								message={ `${ field.label }: ${ error }` }
							/>
						</p>
					) : null;
				}
				if ( ! isVisible( field, fields, values ) ) {
					return null;
				}
				return (
					<FieldControl
						key={ field.key }
						field={ field }
						fields={ fields }
						value={ fieldValue( fields, values, field.key ) }
						required={ isRequired( field, fields, values ) }
						onChange={ ( value ) =>
							setMeta( { ...values, [ field.meta_key ]: value } )
						}
						error={ error }
					/>
				);
			} ) }
			{ relationships.map( ( relationship ) => (
				<RelationshipControl
					key={ relationship.key }
					relationship={ relationship }
					value={ values[ relationship.meta_key ] }
					onChange={ ( ids ) =>
						setMeta( { ...values, [ relationship.meta_key ]: ids } )
					}
					error={ errors[ relationship.key ] }
				/>
			) ) }
			{ taxonomies.map( ( taxonomy ) => (
				<TaxonomyControl
					key={ taxonomy.key }
					taxonomy={ taxonomy }
					error={ errors[ taxonomy.key ] }
				/>
			) ) }
			{ /* Modules add sections here (the automation module's "Run an automation"). */ }
			<Slot name="pegwork-editor-panel-after" />
		</PluginDocumentSettingPanel>
	);
}

/**
 * Pegwork fields panel. Renders nothing for post types not in the config.
 *
 * @param {Object} props        Props.
 * @param {Object} props.config Parsed config.
 */
export default function Panel( { config } ) {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const typeConfig = getTypeConfig( config, postType );

	if ( ! typeConfig ) {
		return null;
	}

	return <TypePanel postType={ postType } typeConfig={ typeConfig } />;
}
