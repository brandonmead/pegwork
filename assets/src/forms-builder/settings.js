/**
 * Document settings panels of a form: key, target, access, confirmation, notifications, messages,
 * Turnstile and retention. They edit the settings object (meta `_pegwork_form_settings`).
 */

/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	Button,
	Disabled,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
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
import config from './config';
import { Errors } from './controls';
import {
	FIELD,
	STEP,
	emailItems,
	errorsAt,
	formItems,
	generalErrors,
	missingBound,
	slugify,
	toInt,
	withValue,
} from './logic';
import { useBlocks, useSaveErrors, useSettings, useTargetType } from './store';

const DEFAULT_SUCCESS = __(
	'Thank you. Your message has been sent.',
	'pegwork'
);

// Same default as PegworkForms\Confirm::review_message().
const DEFAULT_CONFIRM_FAILED = __(
	'We got your confirmation, but something in your submission needs a person to check it. We will be in touch.',
	'pegwork'
);

/**
 * Wraps panel content in Disabled for read-only (file) forms.
 *
 * @param {Object}  props          Props.
 * @param {Element} props.children Content.
 */
function MaybeDisabled( { children } ) {
	return config.readOnly ? <Disabled>{ children }</Disabled> : children;
}

/**
 * Key, target, "Add all fields", access.
 */
function FormPanel() {
	const [ settings, setSettings ] = useSettings();
	const errors = useSaveErrors();
	const blocks = useBlocks();
	const type = useTargetType( config );
	const { title, status } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			title: editor.getEditedPostAttribute( 'title' ),
			status: editor.getCurrentPostAttribute( 'status' ),
		};
	}, [] );
	const { insertBlocks } = useDispatch( blockEditorStore );

	const locked = config.lockedKey !== '' || status !== 'auto-draft';
	const target = settings.target || {};
	const access =
		settings.access && typeof settings.access === 'object'
			? settings.access
			: null;
	const missing = missingBound( type, blocks );

	const setTarget = ( next ) =>
		setSettings(
			withValue(
				settings,
				'target',
				next && next.content_type ? next : undefined
			)
		);

	const addAll = () => {
		const created = missing.map( ( attributes ) =>
			createBlock( FIELD, attributes )
		);
		const steps = blocks.filter( ( block ) => block.name === STEP );
		if ( steps.length ) {
			const last = steps[ steps.length - 1 ];
			insertBlocks( created, last.innerBlocks.length, last.clientId );
		} else {
			insertBlocks( created, blocks.length );
		}
	};

	return (
		<PluginDocumentSettingPanel
			name="pegwork-form"
			title={ __( 'Form settings', 'pegwork' ) }
			className="pegwork-builder-settings"
		>
			{ config.readOnly && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: package ID. */
						__(
							'This form comes from the file package "%s" and is read-only here.',
							'pegwork'
						),
						config.package
					) }{ ' ' }
					{ config.duplicateUrl && (
						<a href={ config.duplicateUrl }>
							{ __( 'Duplicate as site form', 'pegwork' ) }
						</a>
					) }
				</Notice>
			) }
			<Errors
				messages={ [
					...generalErrors( errors ),
					...errorsAt( errors, '/label', false ),
				] }
			/>
			<MaybeDisabled>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Form key', 'pegwork' ) }
					help={
						locked
							? __(
									'Locked: pages place the form by this key.',
									'pegwork'
								)
							: __(
									'Pages place the form by this key. Empty uses the title. It cannot change after the first save.',
									'pegwork'
								)
					}
					value={
						locked
							? config.lockedKey || settings.key || ''
							: ( settings.key ?? '' )
					}
					placeholder={ locked ? undefined : slugify( title ) }
					onChange={ ( key ) =>
						setSettings( withValue( settings, 'key', key ) )
					}
					disabled={ locked }
				/>
				<Errors messages={ errorsAt( errors, '/key' ) } />

				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Creates or updates', 'pegwork' ) }
					value={ target.content_type || '' }
					options={ [
						{
							value: '',
							label: __(
								'Nothing: store entries only',
								'pegwork'
							),
						},
						...config.types.map( ( candidate ) => ( {
							value: candidate.key,
							label: candidate.label,
						} ) ),
					] }
					onChange={ ( contentType ) =>
						setTarget(
							contentType
								? { ...target, content_type: contentType }
								: undefined
						)
					}
				/>
				{ target.content_type && (
					<>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Action', 'pegwork' ) }
							value={ target.action || 'create' }
							options={ [
								{
									value: 'create',
									label: __( 'Create a record', 'pegwork' ),
								},
								{
									value: 'update',
									label: __(
										'Update the record in the page URL (?record=ID)',
										'pegwork'
									),
								},
							] }
							onChange={ ( action ) =>
								setTarget(
									withValue(
										action === 'update'
											? withValue(
													target,
													'status',
													undefined
												)
											: target,
										'action',
										action
									)
								)
							}
						/>
						{ ( target.action || 'create' ) === 'create' && (
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __( 'New records are', 'pegwork' ) }
								value={ target.status || 'pending' }
								options={ [
									{
										value: 'pending',
										label: __(
											'Pending review',
											'pegwork'
										),
									},
									{
										value: 'draft',
										label: __( 'Drafts', 'pegwork' ),
									},
									{
										value: 'publish',
										label: __( 'Published', 'pegwork' ),
									},
								] }
								onChange={ ( next ) =>
									setTarget(
										withValue( target, 'status', next )
									)
								}
							/>
						) }
						{ type && (
							<div className="pegwork-builder-settings__add-all">
								<Button
									variant="secondary"
									onClick={ addAll }
									disabled={ missing.length === 0 }
								>
									{ sprintf(
										/* translators: %s: content type label. */
										__(
											'Add all fields from %s',
											'pegwork'
										),
										type.label
									) }
								</Button>
								<p className="pegwork-builder__help">
									{ missing.length === 0
										? __(
												'Every field and taxonomy is in the form.',
												'pegwork'
											)
										: sprintf(
												/* translators: %d: number of fields. */
												_n(
													'Adds %d field not yet in the form.',
													'Adds %d fields not yet in the form.',
													missing.length,
													'pegwork'
												),
												missing.length
											) }
								</p>
							</div>
						) }
					</>
				) }
				<Errors messages={ errorsAt( errors, '/target' ) } />

				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Who can use it', 'pegwork' ) }
					value={ access ? 'capability' : 'anyone' }
					options={ [
						{ value: 'anyone', label: __( 'Anyone', 'pegwork' ) },
						{
							value: 'capability',
							label: __(
								'Logged-in users with a capability',
								'pegwork'
							),
						},
					] }
					onChange={ ( next ) =>
						setSettings(
							withValue(
								settings,
								'access',
								next === 'capability'
									? {
											capability:
												access?.capability || 'read',
										}
									: undefined
							)
						)
					}
				/>
				{ access && (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Capability', 'pegwork' ) }
						help={ __(
							'Such as read (any logged-in user) or edit_posts. Forms that publish or update records need one.',
							'pegwork'
						) }
						value={ access.capability || '' }
						onChange={ ( capability ) =>
							setSettings( {
								...settings,
								access: { capability },
							} )
						}
					/>
				) }
				<Errors messages={ errorsAt( errors, '/access' ) } />
			</MaybeDisabled>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Merge tags and recipients help.
 *
 * @param {Object}   props        Props.
 * @param {Object[]} props.emails Email items of the form.
 */
function MergeTagHelp( { emails } ) {
	return (
		<div className="pegwork-builder__help pegwork-builder-settings__tags">
			<p>
				{ __(
					'Merge tags: {field:key} (a field’s value), {all_fields}, {form:label}, {entry:id}, {entry:admin_url}, {post:edit_url}, {site:name}, {admin_email}, {option:page.field}.',
					'pegwork'
				) }
			</p>
			<p>
				{ __(
					'Send to: an address, {admin_email}, {option:page.field}, or an email field of the form:',
					'pegwork'
				) }{ ' ' }
				{ emails.length
					? emails
							.map( ( item ) => `{field:${ item.key }}` )
							.join( ', ' )
					: __( '(none yet)', 'pegwork' ) }
			</p>
		</div>
	);
}

/**
 * Email confirmation.
 */
function ConfirmPanel() {
	const [ settings, setSettings ] = useSettings();
	const errors = useSaveErrors();
	const blocks = useBlocks();
	const type = useTargetType( config );
	const emails = emailItems( formItems( blocks, type ) );
	const confirm = settings.confirm || null;
	const set = ( name, value ) =>
		setSettings( {
			...settings,
			confirm: withValue( confirm, name, value ),
		} );

	return (
		<PluginDocumentSettingPanel
			name="pegwork-form-confirm"
			title={ __( 'Email confirmation', 'pegwork' ) }
			className="pegwork-builder-settings"
		>
			<MaybeDisabled>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Hold entries until the email address is confirmed',
						'pegwork'
					) }
					help={ __(
						'Submitters get a link by email; records are created only after they confirm.',
						'pegwork'
					) }
					checked={ Boolean( confirm ) }
					onChange={ ( on ) =>
						setSettings(
							withValue(
								settings,
								'confirm',
								on
									? {
											field:
												emails.find(
													( item ) => item.required
												)?.key ||
												emails[ 0 ]?.key ||
												'',
											subject: __(
												'Confirm your submission',
												'pegwork'
											),
											body:
												__(
													'Confirm your submission within 72 hours:',
													'pegwork'
												) + '\n{confirm_url}',
										}
									: undefined
							)
						)
					}
				/>
				{ confirm && (
					<>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Email field', 'pegwork' ) }
							help={ __(
								'A required email field of this form.',
								'pegwork'
							) }
							value={ confirm.field || '' }
							options={ [
								{
									value: '',
									label: __( 'Choose a field', 'pegwork' ),
								},
								...emails.map( ( item ) => ( {
									value: item.key,
									label: item.label,
								} ) ),
							] }
							onChange={ ( field ) => set( 'field', field ) }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Subject', 'pegwork' ) }
							value={ confirm.subject || '' }
							onChange={ ( subject ) =>
								set( 'subject', subject )
							}
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Message', 'pegwork' ) }
							help={ __(
								'Must contain {confirm_url}, the confirmation link.',
								'pegwork'
							) }
							value={ confirm.body || '' }
							onChange={ ( body ) => set( 'body', body ) }
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __(
								'Link expires after (hours)',
								'pegwork'
							) }
							type="number"
							min={ 1 }
							placeholder="72"
							value={ confirm.expires_hours ?? '' }
							onChange={ ( value ) =>
								set( 'expires_hours', toInt( value ) )
							}
						/>
					</>
				) }
				<Errors messages={ errorsAt( errors, '/confirm' ) } />
			</MaybeDisabled>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Notifications repeater.
 */
function NotificationsPanel() {
	const [ settings, setSettings ] = useSettings();
	const errors = useSaveErrors();
	const blocks = useBlocks();
	const type = useTargetType( config );
	const emails = emailItems( formItems( blocks, type ) );
	const list = Array.isArray( settings.notifications )
		? settings.notifications
		: [];
	const setList = ( next ) =>
		setSettings( { ...settings, notifications: next } );
	const update = ( index, name, value ) =>
		setList(
			list.map( ( item, position ) =>
				position === index ? withValue( item, name, value ) : item
			)
		);
	const add = () => {
		const used = new Set( list.map( ( item ) => item.key ) );
		let n = list.length + 1;
		while ( used.has( `notification_${ n }` ) ) {
			n++;
		}
		setList( [
			...list,
			{
				key: list.length === 0 ? 'admin' : `notification_${ n }`,
				when: settings.confirm ? 'confirmed' : 'submitted',
				to: '{admin_email}',
				subject: __( 'New entry: {form:label}', 'pegwork' ),
				body: '{all_fields}',
			},
		] );
	};

	return (
		<PluginDocumentSettingPanel
			name="pegwork-form-notifications"
			title={ __( 'Notifications', 'pegwork' ) }
			className="pegwork-builder-settings"
		>
			<MaybeDisabled>
				{ list.length === 0 && (
					<p className="pegwork-builder__help">
						{ __(
							'No emails are sent for entries yet.',
							'pegwork'
						) }
					</p>
				) }
				{ list.map( ( item, index ) => (
					<fieldset
						key={ index }
						className="pegwork-builder__fieldset"
					>
						<legend>
							{ sprintf(
								/* translators: 1: number, 2: notification key. */
								__( 'Notification %1$d: %2$s', 'pegwork' ),
								index + 1,
								item.key || ''
							) }
						</legend>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Key', 'pegwork' ) }
							value={ item.key || '' }
							onChange={ ( value ) =>
								update( index, 'key', value )
							}
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Send when', 'pegwork' ) }
							value={ item.when || 'submitted' }
							options={ [
								{
									value: 'submitted',
									label: __( 'Submitted', 'pegwork' ),
								},
								{
									value: 'confirmed',
									label: __(
										'Confirmed by email',
										'pegwork'
									),
								},
							] }
							onChange={ ( value ) =>
								update( index, 'when', value )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'To', 'pegwork' ) }
							value={ item.to || '' }
							onChange={ ( value ) =>
								update( index, 'to', value )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Reply to (optional)', 'pegwork' ) }
							value={ item.reply_to || '' }
							onChange={ ( value ) =>
								update( index, 'reply_to', value )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Subject', 'pegwork' ) }
							value={ item.subject || '' }
							onChange={ ( value ) =>
								update( index, 'subject', value )
							}
						/>
						<TextareaControl
							__nextHasNoMarginBottom
							label={ __( 'Message', 'pegwork' ) }
							value={ item.body || '' }
							onChange={ ( value ) =>
								update( index, 'body', value )
							}
						/>
						<Errors
							messages={ errorsAt(
								errors,
								`/notifications/${ index }`
							) }
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								setList(
									list.filter(
										( other, position ) =>
											position !== index
									)
								)
							}
						>
							{ sprintf(
								/* translators: %d: notification number. */
								__( 'Remove notification %d', 'pegwork' ),
								index + 1
							) }
						</Button>
					</fieldset>
				) ) }
				<Errors
					messages={ errorsAt( errors, '/notifications', false ) }
				/>
				<Button variant="secondary" onClick={ add }>
					{ __( 'Add notification', 'pegwork' ) }
				</Button>
				<MergeTagHelp emails={ emails } />
			</MaybeDisabled>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Messages, Turnstile and retention.
 */
function MessagesPanel() {
	const [ settings, setSettings ] = useSettings();
	const errors = useSaveErrors();
	const messages = settings.messages || {};
	const turnstile = settings.captcha === 'turnstile';
	const setMessage = ( name, value ) =>
		setSettings(
			withValue(
				settings,
				'messages',
				withValue( messages, name, value )
			)
		);

	return (
		<PluginDocumentSettingPanel
			name="pegwork-form-messages"
			title={ __( 'Messages, spam and privacy', 'pegwork' ) }
			className="pegwork-builder-settings"
		>
			<MaybeDisabled>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Success message', 'pegwork' ) }
					placeholder={ DEFAULT_SUCCESS }
					value={ messages.success || '' }
					onChange={ ( value ) => setMessage( 'success', value ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Submit button label', 'pegwork' ) }
					placeholder={ __( 'Send', 'pegwork' ) }
					value={ messages.submit_label || '' }
					onChange={ ( value ) =>
						setMessage( 'submit_label', value )
					}
				/>
				{ settings.confirm && (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Message when a confirmed submission needs review',
							'pegwork'
						) }
						help={ __(
							'Shown after the submitter confirms if their values no longer pass the form rules. The entry is kept for you to check.',
							'pegwork'
						) }
						placeholder={ DEFAULT_CONFIRM_FAILED }
						value={ messages.confirm_failed || '' }
						onChange={ ( value ) =>
							setMessage( 'confirm_failed', value )
						}
					/>
				) }
				<Errors messages={ errorsAt( errors, '/messages' ) } />
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Cloudflare Turnstile check', 'pegwork' ) }
					help={
						config.turnstile
							? __(
									'Visitors pass a Cloudflare check before sending. The form then needs JavaScript.',
									'pegwork'
								)
							: __(
									'Unavailable: add PEGWORK_TURNSTILE_SITE_KEY and PEGWORK_TURNSTILE_SECRET_KEY to wp-config.php first.',
									'pegwork'
								)
					}
					checked={ turnstile }
					disabled={ ! config.turnstile && ! turnstile }
					onChange={ ( on ) =>
						setSettings(
							withValue(
								settings,
								'captcha',
								on ? 'turnstile' : undefined
							)
						)
					}
				/>
				<Errors messages={ errorsAt( errors, '/captcha' ) } />
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Delete entries after (days)', 'pegwork' ) }
					help={ __(
						'Received and failed entries older than this are deleted daily. Empty keeps them.',
						'pegwork'
					) }
					type="number"
					min={ 1 }
					value={ settings.retention_days ?? '' }
					onChange={ ( value ) =>
						setSettings(
							withValue(
								settings,
								'retention_days',
								toInt( value )
							)
						)
					}
				/>
				<Errors messages={ errorsAt( errors, '/retention_days' ) } />
				{ config.formsUrl && (
					<p>
						<a href={ config.formsUrl }>
							{ __( 'All forms', 'pegwork' ) }
						</a>
					</p>
				) }
			</MaybeDisabled>
		</PluginDocumentSettingPanel>
	);
}

/**
 * Every settings panel.
 */
export default function SettingsPanels() {
	return (
		<>
			<FormPanel />
			<ConfirmPanel />
			<NotificationsPanel />
			<MessagesPanel />
		</>
	);
}
