/**
 * Document sidebar panels of an automation: Automation (label, key, description, Active, open runs)
 * and Trigger (the five trigger kinds, Only when, Run once per, links). They edit the settings
 * object (meta `_pegwork_automation_settings`) and the post title (the label).
 */

/**
 * WordPress dependencies
 */
import {
	CheckboxControl,
	Notice,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
	Disabled,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ConditionBuilder from './condition-builder';
import { Errors, TemplateControl, wire } from './controls';
import { durationText } from './labels';
import { errorsAt, slugify, toNumber, withValue } from './logic';
import { RECORD_EVENTS, conditionFields, mergeTags } from './scope';
import { config, useErrorOwners, useSettings } from './store';

export const PANEL = 'pegwork-automation-builder/pegwork-automation';
export const TRIGGER_PANEL =
	'pegwork-automation-builder/pegwork-automation-trigger';

const SWEEP = [ 'publish', 'future', 'draft', 'pending', 'private' ];

/**
 * Wraps panel content in Disabled for read-only (file) automations.
 *
 * @param {Object}  props          Props.
 * @param {Element} props.children Content.
 */
function MaybeDisabled( { children } ) {
	return config.readOnly ? <Disabled>{ children }</Disabled> : children;
}

/**
 * The trigger kind of a trigger.
 *
 * @param {Object} trigger Trigger.
 * @return {string} record, form, schedule, webhook, manual, other or ''.
 */
export function triggerKind( trigger ) {
	if (
		trigger?.type === 'schedule' ||
		trigger?.type === 'webhook' ||
		trigger?.type === 'manual'
	) {
		return trigger.type;
	}
	if ( trigger?.type !== 'event' ) {
		return '';
	}
	const event = String( trigger.event || '' );
	if ( event.startsWith( 'record.' ) ) {
		return 'record';
	}
	return event.startsWith( 'form.' ) ? 'form' : 'other';
}

/**
 * Label, key, description, Active, open runs.
 */
function AutomationPanel() {
	const [ settings, setSettings ] = useSettings();
	const errors = useErrorOwners().settings;
	const { title, status } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			title: editor.getEditedPostAttribute( 'title' ),
			status: editor.getCurrentPostAttribute( 'status' ),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );
	const locked = config.lockedKey !== '' || status !== 'auto-draft';
	const label = wire( errors, '/label' );
	const key = wire( errors, '/key', true );
	const general = [
		...errorsAt( errors, '/', false ),
		...errorsAt( errors, '/steps', false ),
		...errorsAt( errors, '/format' ),
	];
	// Unset means off until the first save stores it (AutomationPost::save()), on after that.
	const enabled =
		settings.enabled === undefined
			? config.lockedKey !== ''
			: settings.enabled !== false;

	return (
		<PluginDocumentSettingPanel
			name="pegwork-automation"
			title={ __( 'Automation', 'pegwork' ) }
			className="pegwork-automation-settings"
		>
			{ config.readOnly && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: package ID. */
						__(
							'This automation comes from the file package "%s" and is read-only here.',
							'pegwork'
						),
						config.package
					) }{ ' ' }
					{ config.duplicateUrl && (
						<a href={ config.duplicateUrl }>
							{ __( 'Duplicate as site automation', 'pegwork' ) }
						</a>
					) }
				</Notice>
			) }
			{ config.openRuns > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of runs. */
						_n(
							'%d run is in progress. It continues from its current step; a run at a step you removed will fail.',
							'%d runs are in progress. They continue from their current step; runs at a step you removed will fail.',
							config.openRuns,
							'pegwork'
						),
						config.openRuns
					) }
				</Notice>
			) }
			<Errors messages={ general } />
			<MaybeDisabled>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Label', 'pegwork' ) }
					value={ title ?? '' }
					onChange={ ( value ) => editPost( { title: value } ) }
					{ ...label.props }
				/>
				<Errors
					messages={ label.messages }
					id={ `${ label.id }-errors` }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Key', 'pegwork' ) }
					help={
						locked
							? __(
									'Locked: runs and the log use this key.',
									'pegwork'
								)
							: __(
									'Lowercase letters, digits and underscores. Empty uses the label. It cannot change after the first save.',
									'pegwork'
								)
					}
					value={
						locked
							? config.lockedKey || settings.key || ''
							: ( settings.key ?? '' )
					}
					placeholder={ locked ? undefined : slugify( title ) }
					onChange={ ( value ) =>
						setSettings( withValue( settings, 'key', value ) )
					}
					disabled={ locked }
					{ ...key.props }
				/>
				<Errors messages={ key.messages } id={ `${ key.id }-errors` } />
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Description (optional)', 'pegwork' ) }
					value={ settings.description ?? '' }
					onChange={ ( value ) =>
						setSettings(
							withValue( settings, 'description', value )
						)
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Active', 'pegwork' ) }
					help={
						enabled
							? __( 'Runs when its trigger fires.', 'pegwork' )
							: __(
									'Off: nothing starts a run. New automations start off.',
									'pegwork'
								)
					}
					checked={ enabled }
					onChange={ ( value ) =>
						setSettings( { ...settings, enabled: value } )
					}
				/>
				{ config.override && (
					<Notice status="info" isDismissible={ false }>
						{ config.override === 'on'
							? __(
									'This site turns it on in the Automations list, whatever this setting says.',
									'pegwork'
								)
							: __(
									'This site turns it off in the Automations list, whatever this setting says.',
									'pegwork'
								) }{ ' ' }
						<a href={ config.listUrl }>
							{ __( 'Open the Automations list', 'pegwork' ) }
						</a>
					</Notice>
				) }
			</MaybeDisabled>
		</PluginDocumentSettingPanel>
	);
}

/**
 * The controls of one trigger kind.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.trigger    Trigger.
 * @param {Function} props.setTrigger Setter.
 * @param {Object}   props.errors     Settings errors.
 */
function TriggerFields( { trigger, setTrigger, errors } ) {
	const kind = triggerKind( trigger );
	const set = ( name, value ) =>
		setTrigger( withValue( trigger, name, value ) );
	const select = ( name, label, options, value, onChange, extra = {} ) => {
		const w = wire( errors, `/trigger/${ name }` );
		return (
			<>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					value={ value ?? '' }
					options={ options }
					onChange={ onChange || ( ( next ) => set( name, next ) ) }
					{ ...extra }
					{ ...w.props }
				/>
				<Errors messages={ w.messages } id={ `${ w.id }-errors` } />
			</>
		);
	};
	const types = config.types.map( ( type ) => ( {
		value: type.key,
		label: type.label,
	} ) );

	if ( kind === 'record' ) {
		return (
			<>
				{ select(
					'content_type',
					__( 'Content type', 'pegwork' ),
					[
						{
							value: '',
							label: __( 'Any content type', 'pegwork' ),
						},
						...types,
					],
					trigger.content_type
				) }
				{ select(
					'event',
					__( 'When a record is', 'pegwork' ),
					Object.keys( RECORD_EVENTS ).map( ( event ) => ( {
						value: event,
						label: {
							'record.created': __( 'Created', 'pegwork' ),
							'record.updated': __( 'Updated', 'pegwork' ),
							'record.status_changed': __(
								'Changed status',
								'pegwork'
							),
							'record.deleted': __( 'Deleted', 'pegwork' ),
						}[ event ],
					} ) ),
					trigger.event
				) }
			</>
		);
	}
	if ( kind === 'form' ) {
		return (
			<>
				{ select(
					'form',
					__( 'Form', 'pegwork' ),
					[
						{ value: '', label: __( 'Choose a form', 'pegwork' ) },
						...config.forms.map( ( form ) => ( {
							value: form.key,
							label: form.label,
						} ) ),
					],
					trigger.form
				) }
				{ select(
					'event',
					__( 'When an entry is', 'pegwork' ),
					[
						{
							value: 'form.submitted',
							label: __( 'Submitted', 'pegwork' ),
						},
						{
							value: 'form.confirmed',
							label: __( 'Confirmed by email', 'pegwork' ),
						},
					],
					trigger.event
				) }
			</>
		);
	}
	if ( kind === 'schedule' ) {
		const forEach = trigger.for_each || null;
		const setForEach = ( name, value ) =>
			set( 'for_each', withValue( forEach, name, value ) );
		const statuses = forEach?.status || [ 'publish' ];
		const max = wire( errors, '/trigger/for_each/max_runs' );
		return (
			<>
				{ select(
					'every',
					__( 'Every', 'pegwork' ),
					[
						{ value: 'hour', label: __( 'Hour', 'pegwork' ) },
						{ value: 'day', label: __( 'Day', 'pegwork' ) },
						{ value: 'week', label: __( 'Week', 'pegwork' ) },
					],
					trigger.every
				) }
				{ trigger.every !== 'hour' && (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						type="time"
						label={ __( 'At (site time, optional)', 'pegwork' ) }
						value={ trigger.at ?? '' }
						onChange={ ( value ) => set( 'at', value ) }
						{ ...wire( errors, '/trigger/at' ).props }
					/>
				) }
				<Errors
					messages={ errorsAt( errors, '/trigger/at' ) }
					id={ `${ wire( errors, '/trigger/at' ).id }-errors` }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Once for each record of a type', 'pegwork' ) }
					checked={ Boolean( forEach ) }
					onChange={ ( on ) =>
						set(
							'for_each',
							on
								? { content_type: config.types[ 0 ]?.key || '' }
								: undefined
						)
					}
				/>
				{ forEach && (
					<>
						{ select(
							'for_each/content_type',
							__( 'Records of', 'pegwork' ),
							types,
							forEach.content_type,
							( value ) => setForEach( 'content_type', value )
						) }
						<fieldset className="pegwork-automation__fieldset pegwork-automation__choices">
							<legend>{ __( 'With status', 'pegwork' ) }</legend>
							{ SWEEP.map( ( value ) => (
								<CheckboxControl
									key={ value }
									__nextHasNoMarginBottom
									label={ value }
									checked={ statuses.includes( value ) }
									onChange={ ( checked ) => {
										const next = checked
											? [ ...statuses, value ]
											: statuses.filter(
													( item ) => item !== value
												);
										setForEach(
											'status',
											next.length ? next : undefined
										);
									} }
								/>
							) ) }
						</fieldset>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="number"
							min={ 1 }
							label={ __(
								'At most this many runs per firing (optional)',
								'pegwork'
							) }
							value={ forEach.max_runs ?? '' }
							onChange={ ( value ) =>
								setForEach( 'max_runs', toNumber( value ) )
							}
							{ ...max.props }
						/>
						<Errors
							messages={ max.messages }
							id={ `${ max.id }-errors` }
						/>
						<ConditionBuilder
							label={ __( 'Only records where', 'pegwork' ) }
							empty={ __(
								'Every record with those statuses.',
								'pegwork'
							) }
							value={ forEach.where }
							onChange={ ( where ) =>
								setForEach( 'where', where )
							}
							fields={ conditionFields(
								{ trigger, steps: [] },
								'/trigger/for_each/where',
								config
							) }
							event={ null }
							pointer="/trigger/for_each/where"
							errors={ errors }
						/>
					</>
				) }
			</>
		);
	}
	if ( kind === 'webhook' ) {
		return config.lockedKey && config.webhookUrl ? (
			<div className="pegwork-automation__webhook">
				<p>{ __( 'Senders post signed JSON to:', 'pegwork' ) }</p>
				<p>
					<code>{ config.webhookUrl }</code>
				</p>
				<p>
					{ __(
						'Show or rotate the signing secret with WP-CLI:',
						'pegwork'
					) }{ ' ' }
					<code>{ `wp pegwork automation secret ${ config.lockedKey }` }</code>
				</p>
			</div>
		) : (
			<p className="pegwork-automation__help">
				{ __(
					'The inbound URL appears here after the first save.',
					'pegwork'
				) }
			</p>
		);
	}
	if ( kind === 'manual' ) {
		const button = wire( errors, '/trigger/button', true );
		const allow = wire( errors, '/trigger/allow' );
		return (
			<>
				{ select(
					'content_type',
					__( 'Content type', 'pegwork' ),
					[
						{
							value: '',
							label: __(
								'None: Run now on the Automations list',
								'pegwork'
							),
						},
						...types,
					],
					trigger.content_type,
					( value ) =>
						// Editors can run it only on a record they can edit, so "editors" needs a type.
						setTrigger(
							withValue(
								value ? trigger : withValue( trigger, 'allow' ),
								'content_type',
								value
							)
						),
					{
						help: trigger.content_type
							? __(
									'A button on the edit screen of these records runs it for that record.',
									'pegwork'
								)
							: __(
									'The run has no record. Start it with Run now on the Automations list.',
									'pegwork'
								),
					}
				) }
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Button text', 'pegwork' ) }
					help={ __(
						'Such as "Resend thank-you". At most 40 characters.',
						'pegwork'
					) }
					maxLength={ 40 }
					value={ trigger.button ?? '' }
					onChange={ ( value ) => set( 'button', value ) }
					{ ...button.props }
				/>
				<Errors
					messages={ button.messages }
					id={ `${ button.id }-errors` }
				/>
				<RadioControl
					label={ __( 'Who can run it', 'pegwork' ) }
					help={
						trigger.allow === 'editors'
							? __(
									"It runs with this automation's full access: it may email people and read other records.",
									'pegwork'
								)
							: undefined
					}
					selected={
						trigger.allow === 'editors' ? 'editors' : 'admins'
					}
					options={ [
						{
							value: 'admins',
							label: __( 'Administrators', 'pegwork' ),
						},
						...( trigger.content_type || trigger.allow === 'editors'
							? [
									{
										value: 'editors',
										label: __(
											'Anyone who can edit the record',
											'pegwork'
										),
									},
								]
							: [] ),
					] }
					onChange={ ( value ) =>
						set(
							'allow',
							value === 'editors' ? 'editors' : undefined
						)
					}
				/>
				<Errors messages={ allow.messages } />
			</>
		);
	}
	if ( kind === 'other' ) {
		const w = wire( errors, '/trigger/event', true );
		return (
			<>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Event name', 'pegwork' ) }
					help={ __(
						'Dot-separated, such as shop.order_paid. Its payload is trigger.*.',
						'pegwork'
					) }
					value={ trigger.event ?? '' }
					onChange={ ( value ) => set( 'event', value ) }
					{ ...w.props }
				/>
				<Errors messages={ w.messages } id={ `${ w.id }-errors` } />
			</>
		);
	}
	return null;
}

/**
 * Trigger, Only when, Run once per, links.
 */
function TriggerPanel() {
	const [ settings, setSettings ] = useSettings();
	const errors = useErrorOwners().settings;
	const trigger = settings.trigger || {};
	const kind = triggerKind( trigger );
	const setTrigger = ( next ) =>
		setSettings( withValue( settings, 'trigger', next ) );
	const kindWire = wire( errors, '/trigger' );
	const definition = { trigger, steps: [], links: settings.links || {} };
	const links = Object.entries( settings.links || {} );

	const setKind = ( next ) => {
		const keep = trigger.when ? { when: trigger.when } : {};
		const fresh = {
			record: { type: 'event', event: 'record.created', ...keep },
			form: {
				type: 'event',
				event: 'form.submitted',
				form: config.forms[ 0 ]?.key,
				...keep,
			},
			schedule: { type: 'schedule', every: 'day' },
			webhook: { type: 'webhook' },
			manual: { type: 'manual', ...keep },
			other: { type: 'event', event: '', ...keep },
		}[ next ];
		setTrigger( fresh );
	};

	return (
		<PluginDocumentSettingPanel
			name="pegwork-automation-trigger"
			title={ __( 'Trigger', 'pegwork' ) }
			className="pegwork-automation-settings"
		>
			<MaybeDisabled>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Starts on', 'pegwork' ) }
					value={ kind }
					options={ [
						...( kind
							? []
							: [
									{
										value: '',
										label: __(
											'Choose a trigger',
											'pegwork'
										),
									},
								] ),
						{
							value: 'record',
							label: __( 'Record event', 'pegwork' ),
						},
						{ value: 'form', label: __( 'Form event', 'pegwork' ) },
						{
							value: 'schedule',
							label: __( 'Schedule', 'pegwork' ),
						},
						{ value: 'webhook', label: __( 'Webhook', 'pegwork' ) },
						{
							value: 'manual',
							label: __( 'Started by staff', 'pegwork' ),
						},
						{
							value: 'other',
							label: __( 'Other event', 'pegwork' ),
						},
					] }
					onChange={ setKind }
					{ ...kindWire.props }
				/>
				<Errors
					messages={ errorsAt( errors, '/trigger', false ).concat(
						errorsAt( errors, '/trigger/type' )
					) }
					id={ `${ kindWire.id }-errors` }
				/>
				<TriggerFields
					trigger={ trigger }
					setTrigger={ setTrigger }
					errors={ errors }
				/>
				{ ( trigger.type === 'event' || trigger.type === 'manual' ) && (
					<ConditionBuilder
						label={ __( 'Only when', 'pegwork' ) }
						empty={
							trigger.type === 'manual'
								? __(
										'Staff can always run it. When this condition is false, the button says the automation does not apply.',
										'pegwork'
									)
								: __( 'Every event starts a run.', 'pegwork' )
						}
						value={ trigger.when }
						onChange={ ( when ) =>
							setTrigger( withValue( trigger, 'when', when ) )
						}
						fields={ conditionFields(
							definition,
							'/trigger/when',
							config
						) }
						event={ trigger.event ?? null }
						pointer="/trigger/when"
						errors={ errors }
					/>
				) }
				<TemplateControl
					label={ __( 'Run once per (optional)', 'pegwork' ) }
					help={ __(
						'A run starts only once for each value, such as {{record.id}}.',
						'pegwork'
					) }
					value={ settings.idempotency ?? '' }
					onChange={ ( value ) =>
						setSettings(
							withValue( settings, 'idempotency', value )
						)
					}
					pointer="/idempotency"
					errors={ errors }
					tags={ mergeTags(
						definition,
						'/idempotency',
						config,
						'private'
					) }
				/>
			</MaybeDisabled>
			{ links.length > 0 && (
				<div className="pegwork-automation__links">
					<p>
						<strong>
							{ __(
								'Signed links (edit them in the JSON file)',
								'pegwork'
							) }
						</strong>
					</p>
					<ul>
						{ links.map( ( [ name, link ] ) => (
							<li key={ name }>
								<code>{ `{{link.${ name }}}` }</code>{ ' ' }
								{ sprintf(
									/* translators: 1: duration, 2: number of steps. */
									_n(
										'expires after %1$s, %2$d step',
										'expires after %1$s, %2$d steps',
										( link.steps || [] ).length,
										'pegwork'
									),
									durationText( link.expires ),
									( link.steps || [] ).length
								) }
							</li>
						) ) }
					</ul>
					<Errors messages={ errorsAt( errors, '/links' ) } />
				</div>
			) }
		</PluginDocumentSettingPanel>
	);
}

export default function SettingsPanels() {
	return (
		<>
			<AutomationPanel />
			<TriggerPanel />
		</>
	);
}
