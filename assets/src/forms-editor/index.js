/**
 * Forms editor script: the pegwork/form block (pick a form, preview it server-rendered).
 */

/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	BlockControls,
} from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import {
	PanelBody,
	Placeholder,
	SelectControl,
	ToolbarButton,
	ToolbarGroup,
	Disabled,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
// eslint-disable-next-line import/no-unresolved -- WordPress script wp-server-side-render; the build externalizes it.
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/forms/blocks/form/block.json';
import { formOptions, parseConfig } from './options';

const config = parseConfig( window.pegworkForms );

const labels = {
	none: __( 'Choose a form', 'pegwork' ),
	/* translators: %s: form label and key. */
	later: ( label ) => sprintf( __( '%s (not shown yet)', 'pegwork' ), label ),
	/* translators: %s: form key. */
	gone: ( key ) => sprintf( __( '%s (not defined)', 'pegwork' ), key ),
};

function FormPicker( { value, onChange } ) {
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Form', 'pegwork' ) }
			value={ value }
			options={ formOptions( config.forms, value, labels ) }
			onChange={ onChange }
		/>
	);
}

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const onChange = ( form ) => setAttributes( { form } );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'pegwork' ) }>
					<FormPicker
						value={ attributes.form }
						onChange={ onChange }
					/>
				</PanelBody>
			</InspectorControls>
			{ attributes.form ? (
				<>
					<BlockControls>
						<ToolbarGroup>
							<ToolbarButton
								onClick={ () => onChange( '' ) }
								label={ __( 'Choose another form', 'pegwork' ) }
							>
								{ __( 'Change form', 'pegwork' ) }
							</ToolbarButton>
						</ToolbarGroup>
					</BlockControls>
					<Disabled>
						<ServerSideRender
							block={ metadata.name }
							attributes={ attributes }
						/>
					</Disabled>
				</>
			) : (
				<Placeholder
					icon="feedback"
					label={ __( 'Form', 'pegwork' ) }
					instructions={ __(
						'Choose the registry form to show here.',
						'pegwork'
					) }
				>
					<FormPicker value="" onChange={ onChange } />
				</Placeholder>
			) }
		</div>
	);
}

registerBlockType( metadata, {
	title: __( 'Form', 'pegwork' ),
	description: __( 'Places a Pegwork registry form by its key.', 'pegwork' ),
	edit: Edit,
	save: () => null,
} );
