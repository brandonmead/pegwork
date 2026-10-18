/**
 * Listings editor script: the pegwork/field bindings source (editor preview)
 * and the pegwork/conditional block.
 */

/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';
import {
	getBlockBindingsSource,
	registerBlockBindingsSource,
	registerBlockType,
} from '@wordpress/blocks';
import { dateI18n } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/listings/blocks/conditional/block.json';
import { SOURCE_NAME, createSource } from './bindings';
import { createEdit } from './conditional-edit';
import { parseConfig } from './config';

const config = parseConfig( window.pegworkListings );

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
