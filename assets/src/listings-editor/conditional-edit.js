/**
 * Edit UI for pegwork/conditional ("Show if").
 */

/**
 * WordPress dependencies
 */
import {
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import {
	Button,
	CheckboxControl,
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { findField, findTaxonomy } from './config';
import {
	FIELD_OPS,
	TAXONOMY_OPS,
	conditionFor,
	conditionProblem,
	describeCondition,
	opLabel,
	resolvePostType,
	targetOf,
} from './conditions';

const toggle = ( list, item, on ) => {
	const rest = list.filter( ( entry ) => entry !== item );
	return on ? [ ...rest, item ] : rest;
};

function ValueControl( { condition, type, onChange } ) {
	if ( condition.taxonomy ) {
		const taxonomy = findTaxonomy( type, condition.taxonomy );
		const terms = condition.terms ?? [];
		return (
			<fieldset>
				<legend>{ __( 'Terms', 'pegwork' ) }</legend>
				{ taxonomy.terms.map( ( term ) => (
					<CheckboxControl
						key={ term.slug }
						__nextHasNoMarginBottom
						label={ term.name }
						checked={ terms.includes( term.slug ) }
						onChange={ ( checked ) =>
							onChange( {
								...condition,
								terms: toggle( terms, term.slug, checked ),
							} )
						}
					/>
				) ) }
			</fieldset>
		);
	}

	if ( condition.op === 'empty' || condition.op === 'not_empty' ) {
		return null;
	}

	const field = findField( type, condition.field );
	if ( field.type === 'boolean' ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'On', 'pegwork' ) }
				checked={ condition.value === true }
				onChange={ ( value ) => onChange( { ...condition, value } ) }
			/>
		);
	}
	if ( field.type === 'select' ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Value', 'pegwork' ) }
				value={ String( condition.value ?? '' ) }
				options={ field.options }
				onChange={ ( value ) => onChange( { ...condition, value } ) }
			/>
		);
	}
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Value', 'pegwork' ) }
			value={ String( condition.value ?? '' ) }
			onChange={ ( value ) => onChange( { ...condition, value } ) }
		/>
	);
}

function ConditionRow( { condition, index, type, onChange, onRemove } ) {
	const problem = conditionProblem( condition, type );
	const targets = [
		...type.fields.map( ( field ) => ( {
			value: `field:${ field.key }`,
			label: field.label,
		} ) ),
		...type.taxonomies.map( ( taxonomy ) => ( {
			value: `taxonomy:${ taxonomy.key }`,
			label: taxonomy.label,
		} ) ),
	];
	const target = targetOf( condition );
	if ( problem === 'unknown' ) {
		targets.unshift( {
			value: target,
			label: __( '(unavailable field)', 'pegwork' ),
		} );
	}
	const ops = condition.taxonomy ? TAXONOMY_OPS : FIELD_OPS;

	return (
		<div
			className="pegwork-conditional__row"
			style={ {
				borderBottom: '1px solid #ddd',
				display: 'grid',
				gap: '12px',
				marginBottom: '16px',
				paddingBottom: '16px',
			} }
		>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				/* translators: %d: condition number */
				label={ sprintf( __( 'Condition %d', 'pegwork' ), index + 1 ) }
				value={ target }
				options={ targets }
				onChange={ ( value ) => {
					const next = conditionFor( value, type );
					if ( next ) {
						onChange( next );
					}
				} }
			/>
			{ ! problem && (
				<>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Operator', 'pegwork' ) }
						value={ condition.op }
						options={ ops.map( ( op ) => ( {
							value: op,
							label: opLabel( op ),
						} ) ) }
						onChange={ ( op ) => onChange( { ...condition, op } ) }
					/>
					<ValueControl
						condition={ condition }
						type={ type }
						onChange={ onChange }
					/>
				</>
			) }
			<Button variant="link" isDestructive onClick={ onRemove }>
				{ __( 'Remove condition', 'pegwork' ) }
			</Button>
		</div>
	);
}

/**
 * Builds the edit component for a parsed config.
 *
 * @param {Object} config Parsed config.
 * @return {(props: Object) => Object} Edit component.
 */
export function createEdit( config ) {
	return function Edit( { attributes, setAttributes, context } ) {
		const conditions = Array.isArray( attributes.conditions )
			? attributes.conditions
			: [];
		const templateSlug = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return editor?.getCurrentPostType?.() === 'wp_template'
				? String( editor.getCurrentPostId?.() ?? '' )
				: null;
		}, [] );
		const resolved = resolvePostType(
			config,
			context?.postType,
			templateSlug
		);
		const typeKeys = Object.keys( config.types );
		const [ chosen, setChosen ] = useState( typeKeys[ 0 ] ?? null );
		const postType = resolved ?? chosen;
		const type = config.types[ postType ] ?? null;

		const blockProps = useBlockProps( {
			className: 'pegwork-conditional',
		} );
		const innerBlocksProps = useInnerBlocksProps( blockProps );
		const problems = conditions.filter(
			( condition ) => ! type || conditionProblem( condition, type )
		);

		const update = ( index, next ) =>
			setAttributes( {
				conditions: conditions.map( ( condition, i ) =>
					i === index ? next : condition
				),
			} );

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Conditions', 'pegwork' ) }>
						{ ! resolved && typeKeys.length > 0 && (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'Content type', 'pegwork' ) }
								help={ __(
									'Used only to list fields here; conditions apply to the record being shown.',
									'pegwork'
								) }
								value={ postType ?? '' }
								options={ typeKeys.map( ( key ) => ( {
									value: key,
									label: config.types[ key ].label,
								} ) ) }
								onChange={ setChosen }
							/>
						) }
						{ type &&
							conditions.map( ( condition, index ) => (
								<ConditionRow
									key={ index }
									condition={ condition }
									index={ index }
									type={ type }
									onChange={ ( next ) =>
										update( index, next )
									}
									onRemove={ () =>
										setAttributes( {
											conditions: conditions.filter(
												( _, i ) => i !== index
											),
										} )
									}
								/>
							) ) }
						{ type && (
							<Button
								variant="secondary"
								onClick={ () => {
									const first =
										type.fields[ 0 ] &&
										conditionFor(
											`field:${ type.fields[ 0 ].key }`,
											type
										);
									const next =
										first ??
										( type.taxonomies[ 0 ] &&
											conditionFor(
												`taxonomy:${ type.taxonomies[ 0 ].key }`,
												type
											) );
									if ( next ) {
										setAttributes( {
											conditions: [ ...conditions, next ],
										} );
									}
								} }
							>
								{ __( 'Add condition', 'pegwork' ) }
							</Button>
						) }
					</PanelBody>
				</InspectorControls>
				<div { ...innerBlocksProps }>
					{ problems.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'A condition uses an unknown or private field, or a taxonomy this content type does not use. This block renders nothing on the site.',
								'pegwork'
							) }
						</Notice>
					) }
					{ type && conditions.length > 0 && (
						<p
							className="pegwork-conditional__summary"
							style={ {
								fontSize: '12px',
								margin: '0 0 8px',
								opacity: 0.75,
							} }
						>
							{ sprintf(
								/* translators: %s: list of conditions */
								__( 'Show if: %s', 'pegwork' ),
								conditions
									.map( ( condition ) =>
										describeCondition( condition, type )
									)
									.join( '; ' )
							) }
						</p>
					) }
					{ innerBlocksProps.children }
				</div>
			</>
		);
	};
}
