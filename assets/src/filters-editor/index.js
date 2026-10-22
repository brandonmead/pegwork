/**
 * Editor script of the pegwork/filters block: a server-side preview with disabled controls, and inspector
 * settings (content type, which filters, search box). Config: window.pegworkFilters (listing types only,
 * public filters only).
 */

/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	CheckboxControl,
	Disabled,
	Notice,
	PanelBody,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/filters/blocks/filters/block.json';
import { resolveType, toggleFilter, typeOptions } from './settings';

const config = window.pegworkFilters || { types: [] };

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const templateSlug = useSelect(
		( select ) =>
			select( 'core/editor' )?.getEditedPostAttribute?.( 'slug' ) || '',
		[]
	);
	const type = resolveType( config, attributes.postType, templateSlug );
	const definition = config.types.find( ( item ) => item.key === type );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Filters', 'pegwork' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Content type', 'pegwork' ) }
						value={ attributes.postType }
						options={ typeOptions(
							config,
							__( 'The archive being viewed', 'pegwork' )
						) }
						onChange={ ( postType ) =>
							setAttributes( { postType, filters: [] } )
						}
					/>
					{ definition && definition.search && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Search box', 'pegwork' ) }
							checked={ attributes.showSearch !== false }
							onChange={ ( showSearch ) =>
								setAttributes( { showSearch } )
							}
						/>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Collapse on small screens', 'pegwork' ) }
						help={ __(
							'Under 600 px wide, the filters sit in a "Filters" disclosure, closed until a filter is active.',
							'pegwork'
						) }
						checked={ attributes.collapseOnSmall === true }
						onChange={ ( collapseOnSmall ) =>
							setAttributes( { collapseOnSmall } )
						}
					/>
					{ definition &&
						definition.filters.map( ( filter ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ filter.key }
								label={ filter.label }
								checked={
									attributes.filters.length === 0 ||
									attributes.filters.includes( filter.key )
								}
								onChange={ ( on ) =>
									setAttributes( {
										filters: toggleFilter(
											definition,
											attributes.filters,
											filter.key,
											on
										),
									} )
								}
							/>
						) ) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ definition ? (
					<Disabled>
						<ServerSideRender
							block={ metadata.name }
							attributes={ { ...attributes, postType: type } }
						/>
					</Disabled>
				) : (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Choose a content type with a listing in the block settings.',
							'pegwork'
						) }
					</Notice>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	save: () => null,
} );
