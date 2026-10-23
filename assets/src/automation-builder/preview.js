/**
 * Merge tag preview (a step's templates rendered against the sample) and the Test run panel (a dry
 * run of the saved automation), over `POST pegwork/v1/automation-editor/preview` and `/test`.
 * Neither sends, writes or logs anything.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { SampleControl, sampleParams } from './controls';
import { reportTree } from './logic';
import { binding } from './scope';
import { config, useSample, useSettings } from './store';

const ROUTE = '/pegwork/v1/automation-editor';

/**
 * What the sample needs for a trigger: a record (and its type), or a pasted payload.
 *
 * @param {Object} trigger Trigger.
 * @return {{binds: boolean, type: ?string, payload: boolean}} Needs.
 */
export function sampleNeeds( trigger ) {
	const { binds, type } = binding( trigger, config );
	const event = String( trigger?.event || '' );
	const payload =
		trigger?.type === 'webhook' ||
		( trigger?.type === 'event' &&
			! event.startsWith( 'record.' ) &&
			! event.startsWith( 'form.' ) );
	return { binds, type, payload };
}

/**
 * The rendered templates of a step, refreshed after typing pauses. Not a live region, so editing
 * does not set off announcements; the region has a heading to find it.
 *
 * @param {Object} props           Props.
 * @param {Array}  props.templates `[label, template, html]` per field.
 */
export function PreviewPanel( { templates } ) {
	const [ settings ] = useSettings();
	const trigger = settings.trigger || {};
	const needs = sampleNeeds( trigger );
	const [ sample ] = useSample();
	const [ shown, setShown ] = useState( false );
	const [ result, setResult ] = useState( {
		busy: false,
		error: '',
		rendered: [],
	} );
	const key = JSON.stringify( [ templates, trigger, sample ] );

	useEffect( () => {
		if ( ! shown ) {
			return undefined;
		}
		const { params, error } = sampleParams(
			sample,
			needs.binds,
			needs.payload
		);
		if ( error || ( needs.binds && ! params.record_id ) ) {
			setResult( {
				busy: false,
				error:
					error ||
					__(
						'Choose a sample record to see the preview.',
						'pegwork'
					),
				rendered: [],
			} );
			return undefined;
		}
		let live = true;
		const timer = window.setTimeout( () => {
			setResult( ( prev ) => ( { ...prev, busy: true } ) );
			Promise.all(
				templates.map( ( [ , template, html ] ) =>
					template
						? apiFetch( {
								path: `${ ROUTE }/preview`,
								method: 'POST',
								data: {
									template,
									html: Boolean( html ),
									trigger,
									...params,
								},
							} ).then( ( response ) => response.rendered )
						: Promise.resolve( '' )
				)
			)
				.then(
					( rendered ) =>
						live &&
						setResult( { busy: false, error: '', rendered } )
				)
				.catch(
					( failure ) =>
						live &&
						setResult( {
							busy: false,
							error:
								failure?.message ||
								__(
									'The preview could not be shown.',
									'pegwork'
								),
							rendered: [],
						} )
				);
		}, 600 );
		return () => {
			live = false;
			window.clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- `key` holds every input.
	}, [ shown, key ] );

	return (
		<div className="pegwork-automation__preview">
			<SampleControl { ...needs } />
			<Button
				variant="secondary"
				aria-expanded={ shown }
				onClick={ () => setShown( ! shown ) }
			>
				{ shown
					? __( 'Hide preview', 'pegwork' )
					: __( 'Show preview', 'pegwork' ) }
			</Button>
			{ shown && (
				<section
					className="pegwork-automation__preview-body"
					aria-label={ __( 'Preview', 'pegwork' ) }
				>
					{ result.busy && <Spinner /> }
					{ result.error && (
						<p className="pegwork-automation__help">
							{ result.error }
						</p>
					) }
					{ ! result.error &&
						templates.map( ( [ label, template, html ], index ) =>
							template ? (
								<div
									key={ label }
									className="pegwork-automation__preview-item"
								>
									<p className="pegwork-automation__preview-label">
										{ label }
									</p>
									{ html ? (
										<iframe
											title={ label }
											sandbox=""
											srcDoc={
												result.rendered[ index ] || ''
											}
											className="pegwork-automation__preview-html"
										/>
									) : (
										<div className="pegwork-automation__preview-text">
											{ result.rendered[ index ] }
										</div>
									) }
								</div>
							) : null
						) }
				</section>
			) }
		</div>
	);
}

/**
 * One level of the report.
 *
 * @param {Object} props       Props.
 * @param {Array}  props.items Tree items.
 */
function ReportList( { items } ) {
	return (
		<ul className="pegwork-automation__report-list">
			{ items.map( ( item, index ) =>
				item.entry ? (
					<li key={ index } className={ `is-${ item.entry.result }` }>
						{ item.entry.message }
					</li>
				) : (
					<li
						key={ index }
						className="pegwork-automation__report-branch"
					>
						{ item.heading !== null && (
							<p className="pegwork-automation__report-heading">
								{ item.heading === 'otherwise'
									? __( 'Otherwise', 'pegwork' )
									: /* translators: %s: branch label. */ sprintf(
											__( 'If %s', 'pegwork' ),
											item.heading
										) }
							</p>
						) }
						<ReportList items={ item.children } />
					</li>
				)
			) }
		</ul>
	);
}

/**
 * The Test run panel: sample, Run test, and the report of the saved automation.
 */
export function TestRunPanel() {
	const [ settings ] = useSettings();
	const needs = sampleNeeds( settings.trigger || {} );
	const [ sample ] = useSample();
	const [ state, setState ] = useState( {
		busy: false,
		error: '',
		report: null,
	} );
	const heading = useRef();
	const { dirty, key } = useSelect(
		( select ) => {
			const editor = select( editorStore );
			return {
				dirty: editor.isEditedPostDirty(),
				key:
					editor.getCurrentPostAttribute( 'status' ) === 'auto-draft'
						? ''
						: config.lockedKey || settings.key || '',
			};
		},
		[ settings.key ]
	);

	useEffect( () => {
		if ( state.report || state.error ) {
			heading.current?.focus();
		}
	}, [ state ] );

	const run = () => {
		const { params, error } = sampleParams(
			sample,
			needs.binds,
			needs.payload
		);
		if ( error ) {
			setState( { busy: false, error, report: null } );
			return;
		}
		setState( { busy: true, error: '', report: null } );
		apiFetch( {
			path: `${ ROUTE }/test`,
			method: 'POST',
			data: { key, ...params },
		} )
			.then( ( report ) =>
				setState( { busy: false, error: '', report } )
			)
			.catch( ( failure ) =>
				setState( {
					busy: false,
					error:
						failure?.message ||
						__( 'The test run failed.', 'pegwork' ),
					report: null,
				} )
			);
	};

	return (
		<PluginDocumentSettingPanel
			name="pegwork-automation-test"
			title={ __( 'Test run', 'pegwork' ) }
			className="pegwork-automation-settings"
		>
			<p className="pegwork-automation__help">
				{ __(
					'Walks the saved automation with a sample and shows what each step would do. Nothing is sent, written or logged.',
					'pegwork'
				) }
			</p>
			{ ! key ? (
				<p className="pegwork-automation__help">
					{ __( 'Save the automation first.', 'pegwork' ) }
				</p>
			) : (
				<>
					<SampleControl { ...needs } />
					{ dirty && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'The test runs the saved version. Save to test your latest changes.',
								'pegwork'
							) }
						</Notice>
					) }
					<Button
						variant="secondary"
						onClick={ run }
						isBusy={ state.busy }
						disabled={ state.busy }
						accessibleWhenDisabled
					>
						{ __( 'Run test', 'pegwork' ) }
					</Button>
				</>
			) }
			{ ( state.report || state.error ) && (
				<section className="pegwork-automation__report">
					<h3 ref={ heading } tabIndex={ -1 }>
						{ __( 'Test run result', 'pegwork' ) }
					</h3>
					{ state.error && (
						<p className="pegwork-automation__error">
							{ state.error }
						</p>
					) }
					{ state.report && (
						<>
							<p>{ state.report.trigger?.message }</p>
							<ReportList
								items={ reportTree( state.report.steps ) }
							/>
							<p>
								<strong>
									{
										( state.report.lines || [] ).slice(
											-1
										)[ 0 ]
									}
								</strong>
							</p>
						</>
					) }
				</section>
			) }
		</PluginDocumentSettingPanel>
	);
}
