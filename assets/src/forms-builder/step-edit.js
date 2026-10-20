/**
 * Edit UI of pegwork/form-step: an inline title and description over the step's fields.
 */

/**
 * WordPress dependencies
 */
import {
	InnerBlocks,
	InspectorControls,
	PlainText,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { Disabled, PanelBody, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import config from './config';
import { Errors } from './controls';
import { FIELD, keyForLabel } from './logic';
import { usePointer, useSaveErrors } from './store';

const ALLOWED = [ FIELD ];

/**
 * Block edit component.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.attributes    Attributes (the step without `fields`).
 * @param {Function} props.setAttributes Setter.
 * @param {string}   props.clientId      Block client ID.
 */
export default function StepEdit( { attributes, setAttributes, clientId } ) {
	const pointer = usePointer( clientId );
	const all = useSaveErrors();
	const errors = pointer
		? Object.keys( all )
				.filter(
					( key ) =>
						key === pointer ||
						( key.startsWith( `${ pointer }/` ) &&
							! key.startsWith( `${ pointer }/fields/` ) )
				)
				.map( ( key ) => all[ key ] )
		: [];
	const number = pointer ? Number( pointer.split( '/' )[ 2 ] ) + 1 : null;

	const blockProps = useBlockProps( {
		className: `pegwork-builder-step${ errors.length ? ' has-error' : '' }`,
	} );
	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'pegwork-builder-step__fields' },
		{
			allowedBlocks: ALLOWED,
			renderAppender: config.readOnly
				? false
				: InnerBlocks.ButtonBlockAppender,
		}
	);

	const setLabel = ( raw ) => {
		const label = raw.replace( /[\r\n]+/g, ' ' );
		const key = keyForLabel( attributes.key, attributes.label, label );
		setAttributes( {
			label: label === '' ? undefined : label,
			key: key === '' ? undefined : key,
		} );
	};

	const settings = (
		<PanelBody title={ __( 'Step settings', 'pegwork' ) }>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Key', 'pegwork' ) }
				help={ __(
					'Identifies the step. It follows the title until you change it.',
					'pegwork'
				) }
				value={ attributes.key ?? '' }
				onChange={ ( key ) =>
					setAttributes( { key: key === '' ? undefined : key } )
				}
			/>
		</PanelBody>
	);

	return (
		<div { ...blockProps }>
			<div className="pegwork-builder-step__head">
				{ number !== null && (
					<span className="pegwork-builder-step__number">
						{
							/* translators: %d: step number. */ sprintf(
								__( 'Step %d', 'pegwork' ),
								number
							)
						}
					</span>
				) }
				{ config.readOnly ? (
					<span className="pegwork-builder-step__label">
						{ attributes.label }
					</span>
				) : (
					<PlainText
						className="pegwork-builder-step__label"
						value={ attributes.label ?? '' }
						placeholder={ __( 'Step title', 'pegwork' ) }
						onChange={ setLabel }
						aria-label={ __( 'Step title', 'pegwork' ) }
					/>
				) }
			</div>
			{ config.readOnly ? (
				attributes.description && (
					<p className="pegwork-builder-step__description">
						{ attributes.description }
					</p>
				)
			) : (
				<PlainText
					className="pegwork-builder-step__description"
					value={ attributes.description ?? '' }
					placeholder={ __(
						'Step description (optional)',
						'pegwork'
					) }
					onChange={ ( description ) =>
						setAttributes( {
							description:
								description.trim() === ''
									? undefined
									: description,
						} )
					}
					aria-label={ __( 'Step description', 'pegwork' ) }
				/>
			) }
			<Errors messages={ errors } />
			<div { ...innerBlocksProps } />
			<InspectorControls>
				<Errors messages={ errors } />
				{ config.readOnly ? (
					<Disabled>{ settings }</Disabled>
				) : (
					settings
				) }
			</InspectorControls>
		</div>
	);
}
