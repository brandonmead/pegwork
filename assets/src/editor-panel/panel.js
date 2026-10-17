/**
 * WordPress dependencies
 */
import { useEntityProp } from '@wordpress/core-data';
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
import FieldControl from './field-control';
import { fieldValue, isRequired, isVisible, wordLimits } from './logic';
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
	const { fields, taxonomies } = typeConfig;
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const { removeEditorPanel } = useDispatch( editorStore );

	useEffect( () => {
		taxonomies.forEach( ( taxonomy ) =>
			removeEditorPanel( `taxonomy-panel-${ taxonomy.key }` )
		);
	}, [ taxonomies, removeEditorPanel ] );

	const values = meta || {};

	return (
		<PluginDocumentSettingPanel
			name="pegwork-fields"
			title={ __( 'Details', 'pegwork' ) }
			className="pegwork-editor-panel"
		>
			{ fields.map( ( field ) => {
				if ( field.storage === 'post_content' ) {
					const limits = wordLimits( field );
					return limits ? (
						<WordCounter
							key={ field.key }
							field={ field }
							limits={ limits }
						/>
					) : null;
				}
				if (
					field.storage !== 'meta' ||
					! isVisible( field, fields, values )
				) {
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
					/>
				);
			} ) }
			{ taxonomies.map( ( taxonomy ) => (
				<TaxonomyControl key={ taxonomy.key } taxonomy={ taxonomy } />
			) ) }
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
