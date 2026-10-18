/**
 * Inspector panel "Pegwork directory" on Query Loops of Pegwork content types.
 */

/**
 * WordPress dependencies
 */
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	ATTRIBUTE,
	OPTION_KEY,
	isPegworkQuery,
	presetFields,
	setWhere,
	whereValue,
} from './query';

function PegworkQueryPanel( { type, attributes, setAttributes } ) {
	const pegwork = attributes[ ATTRIBUTE ];
	const enabled = isPegworkQuery( attributes );
	const update = ( next ) => setAttributes( { [ ATTRIBUTE ]: next } );
	const yesNo = [
		{ value: '1', label: __( 'Yes', 'pegwork' ) },
		{ value: '0', label: __( 'No', 'pegwork' ) },
	];

	return (
		<PanelBody title={ __( 'Pegwork directory', 'pegwork' ) }>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Pegwork directory', 'pegwork' ) }
				help={ __(
					'Lists published records only, with the presets below and, when connected, the page filters.',
					'pegwork'
				) }
				checked={ enabled }
				onChange={ ( on ) =>
					setAttributes( {
						[ ATTRIBUTE ]: on ? { filters: true } : undefined,
					} )
				}
			/>
			{ enabled && (
				<>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Connect to filters', 'pegwork' ) }
						checked={ !! pegwork.filters }
						onChange={ ( on ) =>
							update( { ...pegwork, filters: on } )
						}
					/>
					{ presetFields( type ).map( ( field ) => (
						<SelectControl
							key={ field.key }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ sprintf(
								/* translators: %s: field label. */
								__( 'Only: %s', 'pegwork' ),
								field.label
							) }
							value={ whereValue( pegwork, field ) }
							options={ [
								{ value: '', label: __( 'Any', 'pegwork' ) },
								...( field.type === 'boolean'
									? yesNo
									: field.options ),
							] }
							onChange={ ( value ) =>
								update(
									setWhere(
										pegwork,
										field.key,
										value,
										field.type
									)
								)
							}
						/>
					) ) }
					{ type.optionPosts.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Show the record chosen in',
								'pegwork'
							) }
							help={ __(
								'When nothing is chosen there, or it is not published, the newest published record shows.',
								'pegwork'
							) }
							value={ pegwork.where?.[ OPTION_KEY ] ?? '' }
							options={ [
								{
									value: '',
									label: __(
										'No option (all records)',
										'pegwork'
									),
								},
								...type.optionPosts,
							] }
							onChange={ ( value ) =>
								update(
									setWhere(
										pegwork,
										OPTION_KEY,
										value,
										'option'
									)
								)
							}
						/>
					) }
				</>
			) }
		</PanelBody>
	);
}

/**
 * editor.BlockEdit filter factory.
 *
 * @param {Object} config Parsed config.
 * @return {(BlockEdit: Object) => Object} Higher-order component.
 */
export function createQueryControls( config ) {
	return createHigherOrderComponent(
		( BlockEdit ) => ( props ) => {
			const type =
				props.name === 'core/query' && ! props.attributes.query?.inherit
					? config.types[ props.attributes.query?.postType ]
					: null;
			if ( ! type ) {
				return <BlockEdit { ...props } />;
			}
			return (
				<>
					<BlockEdit { ...props } />
					<InspectorControls>
						<PegworkQueryPanel
							type={ type }
							attributes={ props.attributes }
							setAttributes={ props.setAttributes }
						/>
					</InspectorControls>
				</>
			);
		},
		'withPegworkQueryControls'
	);
}
