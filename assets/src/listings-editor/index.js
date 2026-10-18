/**
 * Listings editor script: the pegwork/field bindings source (editor preview),
 * the pegwork/conditional block, and Pegwork directories on core/query
 * (attribute, "<Plural> directory" variations, inspector panel).
 */

/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';
import {
	getBlockBindingsSource,
	registerBlockBindingsSource,
	registerBlockType,
	registerBlockVariation,
} from '@wordpress/blocks';
import { dateI18n } from '@wordpress/date';
import { addFilter } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/listings/blocks/conditional/block.json';
import { SOURCE_NAME, createSource } from './bindings';
import { createEdit } from './conditional-edit';
import { parseConfig } from './config';
import { addPegworkAttribute, directoryVariations } from './query';
import { createQueryControls } from './query-controls';

const config = parseConfig( window.pegworkListings );

// Added before core/query registers (editor init runs on domReady), so saving keeps the attribute.
addFilter(
	'blocks.registerBlockType',
	'pegwork/query-attribute',
	addPegworkAttribute
);
addFilter(
	'editor.BlockEdit',
	'pegwork/query-controls',
	createQueryControls( config )
);

for ( const variation of directoryVariations( config, {
	title: ( plural ) =>
		/* translators: %s: content type plural name. */
		sprintf( __( '%s directory', 'pegwork' ), plural ),
	description: ( plural ) =>
		sprintf(
			/* translators: %s: content type plural name. */
			__(
				'A grid of published %s with pagination, ready for filters.',
				'pegwork'
			),
			plural
		),
} ) ) {
	registerBlockVariation( 'core/query', variation );
}

// Editor registration of custom sources is public since WordPress 6.7.
if (
	typeof registerBlockBindingsSource === 'function' &&
	! getBlockBindingsSource?.( SOURCE_NAME )?.getValues
) {
	registerBlockBindingsSource(
		createSource( config, {
			dateI18n,
			label: __( 'Pegwork field', 'pegwork' ),
		} )
	);
}

registerBlockType( metadata, {
	title: __( 'Show if', 'pegwork' ),
	description: __(
		'Shows its inner blocks only when every condition on the current record holds.',
		'pegwork'
	),
	edit: createEdit( config ),
	save: () => <InnerBlocks.Content />,
} );
