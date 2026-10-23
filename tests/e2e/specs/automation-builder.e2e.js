/**
 * The automation builder (Phase 6 PR 5), by keyboard only (no mouse calls): a scheduled automation
 * with a three-way decision and a nested If built from the inserters, conditions set in the branch
 * inspector, saved, reloaded and read back through the admin e2e route; a bad merge tag refused at
 * its step and reached with "Go to first error"; a step moved with "Move to branch"; the Test run
 * report; a file automation read-only and duplicated. Axe on the Pegwork parts (canvas cards,
 * sidebar panels, block inspector) at 1280 and 782 px, no horizontal page scroll. Saves screenshots
 * to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (package e2e_automation, module toggle,
 * definition read, site reset).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const {
	adminCaller,
	canvas,
	checkPegworkA11y,
	choose,
	insertFrom,
	insertMain,
	openBlockTab,
	openEditor,
	openPanel,
	press,
	renameBranch,
	saveByKeyboard,
	selectBranch,
	selectCard,
	selectedId,
	setCondition,
	sidebar,
	typeInto,
} = require( '../utils/builder' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const LABEL = 'E2E built route';
const KEY = 'e2e_built_route';
const FILE_LABEL = 'E2E branching digest';

let admin;
let editUrl;
const adminCall = adminCaller( () => admin );

const definition = async ( key ) =>
	adminCall( 'GET', '/pegwork-e2e/v1/automation/definition', undefined, {
		key,
	} );

// Fills the selected email step's inspector.
async function fillEmail( page, subject ) {
	await openBlockTab( page );
	const panel = sidebar( page );
	const field = ( name ) =>
		panel.getByRole( 'textbox', { name, exact: true } );
	await typeInto( field( 'To' ), 'desk@example.org' );
	await typeInto( field( 'Subject' ), subject );
	await typeInto( field( 'Text body' ), 'Run {{run.id}}.' );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/site-reset' );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/automation/site-reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'build a three-way decision with a nested If by keyboard, save, reload', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press( page.getByRole( 'link', { name: 'Add automation' } ) );
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	const frame = canvas( page );

	await typeInto(
		frame.getByRole( 'textbox', { name: 'Add title' } ),
		LABEL
	);
	await openPanel( page, 'Trigger' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Starts on' } ),
		'schedule'
	);

	// A three-way decision (two paths and Otherwise) from the empty canvas's appender, then a step
	// after it from the main inserter.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Choose a path'
	);
	await expect( frame.locator( '.pegwork-automation-branch' ) ).toHaveCount(
		3
	);
	await insertMain( page, 'Send email' );
	await fillEmail( page, 'Done' );

	// Path 1: no site name.
	await renameBranch( page, 'Path 1', 'No site name' );
	await setCondition( page, 'site.name', 'empty' );
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add step to No site name' } ),
		'Send email'
	);
	await fillEmail( page, 'Unnamed site' );

	// Path 2: a named site, with a nested If on the site address.
	await renameBranch( page, 'Path 2', 'Named site' );
	await setCondition( page, 'site.name', 'not_empty' );
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add step to Named site' } ),
		'If / Otherwise'
	);
	await renameBranch( page, 'Path 1', 'Site URL set' );
	await setCondition( page, 'site.url', 'not_empty' );
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add step to Site URL set' } ),
		'Send email'
	);
	await fillEmail( page, 'Visit {{site.url}}' );

	// The outer Otherwise is the last "Add step to Otherwise" (the nested one comes first).
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add step to Otherwise' } ).last(),
		'Send email'
	);
	await fillEmail( page, 'Fallback' );

	// Headings in words, "Then continue below" after each decision.
	await expect(
		frame.locator( '.pegwork-automation-branch__heading' )
	).toHaveText( [
		'If Site name is empty',
		'If Site name is not empty',
		'If Site address is not empty',
		'Otherwise',
		'Otherwise',
	] );
	await expect( frame.getByText( 'Then continue below' ) ).toHaveCount( 2 );

	await saveByKeyboard( page );
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
	editUrl = page.url();

	const saved = await definition( KEY );
	expect( saved.source ).toBe( 'db' );
	expect( saved.definition.enabled ).toBe( false );
	expect( saved.definition.trigger ).toEqual( {
		type: 'schedule',
		every: 'day',
	} );
	const [ decision, done ] = saved.definition.steps;
	expect( saved.definition.steps ).toHaveLength( 2 );
	expect( done ).toMatchObject( {
		type: 'send_email',
		to: 'desk@example.org',
		subject: 'Done',
		text: 'Run {{run.id}}.',
	} );
	expect( decision.type ).toBe( 'decision' );
	expect( decision.branches.map( ( b ) => [ b.label, b.when ] ) ).toEqual( [
		[ 'No site name', { var: 'site.name', op: 'empty' } ],
		[ 'Named site', { var: 'site.name', op: 'not_empty' } ],
	] );
	expect( decision.branches[ 0 ].steps[ 0 ].subject ).toBe( 'Unnamed site' );
	const nested = decision.branches[ 1 ].steps[ 0 ];
	expect( nested.type ).toBe( 'decision' );
	expect( nested.branches ).toHaveLength( 1 );
	expect( nested.branches[ 0 ] ).toMatchObject( {
		label: 'Site URL set',
		when: { var: 'site.url', op: 'not_empty' },
		steps: [ { type: 'send_email', subject: 'Visit {{site.url}}' } ],
	} );
	expect( nested.otherwise ).toBeUndefined();
	expect( decision.otherwise.map( ( s ) => s.subject ) ).toEqual( [
		'Fallback',
	] );
	const ids = JSON.stringify( saved.definition ).match( /"id":"[^"]+"/g );
	expect( new Set( ids ).size ).toBe( ids.length );

	// Reloaded, the tree reads the same; the key is locked.
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await expect(
		frame.locator( '.pegwork-automation-branch__heading' )
	).toHaveText( [
		'If Site name is empty',
		'If Site name is not empty',
		'If Site address is not empty',
		'Otherwise',
		'Otherwise',
	] );
	await openPanel( page, 'Automation' );
	await expect(
		sidebar( page ).getByRole( 'textbox', { name: 'Key' } )
	).toBeDisabled();
	await expect(
		sidebar( page ).getByRole( 'textbox', { name: 'Key' } )
	).toHaveValue( KEY );

	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-canvas-1280.png' ),
	} );

	// The branch inspector with its condition builder.
	await selectBranch( page, 'Named site' );
	await expect(
		sidebar( page ).getByRole( 'group', { name: /^Condition 1:/ } )
	).toBeVisible();
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-condition-1280.png' ),
	} );

	// At 782 px the editor closes the sidebar; reopen it on the branch.
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-canvas-782.png' ),
	} );
	const settings = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );
	if ( ( await settings.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await press( settings );
	}
	await selectBranch( page, 'Named site' );
	await expect(
		sidebar( page ).getByRole( 'group', { name: /^Condition 1:/ } )
	).toBeVisible();
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-condition-782.png' ),
	} );
} );

test( 'a bad merge tag is refused at its step, reached with Go to first error', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( editUrl );
	await openEditor( page, /post\.php/ );
	const frame = canvas( page );

	const nestedId = ( await definition( KEY ) ).definition.steps[ 0 ]
		.branches[ 1 ].steps[ 0 ].branches[ 0 ].steps[ 0 ].id;
	await selectCard( page, nestedId );
	await openBlockTab( page );
	const subject = sidebar( page ).getByRole( 'textbox', { name: 'Subject' } );
	await typeInto( subject, 'Visit {{record.nope}}' );
	await saveByKeyboard( page );

	const goTo = page.getByRole( 'button', { name: 'Go to first error' } );
	await expect( goTo ).toBeVisible();
	// Move away first, so the button has somewhere to go.
	await frame.getByRole( 'textbox', { name: 'Add title' } ).focus();
	await expect.poll( () => selectedId( page ) ).toBeNull();
	await press( goTo );

	await expect.poll( () => selectedId( page ) ).toBe( nestedId );
	const card = frame
		.locator( '.pegwork-automation-step.has-error' )
		.filter( { hasText: nestedId } );
	await expect( card ).toHaveCount( 1 );
	await expect( card.locator( '.pegwork-automation__error' ) ).toContainText(
		'record'
	);
	// The control says it is invalid and points at its message.
	await expect( subject ).toHaveAttribute( 'aria-invalid', 'true' );
	const described = await subject.getAttribute( 'aria-describedby' );
	const errorId = described
		.split( ' ' )
		.find( ( id ) => id.endsWith( '-errors' ) );
	await expect( page.locator( `[id="${ errorId }"]` ) ).toContainText(
		'record'
	);
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-error-1280.png' ),
	} );

	// Fixed, it saves.
	await typeInto( subject, 'Visit {{site.url}}' );
	await saveByKeyboard( page );
	await expect( goTo ).toHaveCount( 0 );
	await expect( frame.locator( '.has-error' ) ).toHaveCount( 0 );
} );

test( 'Move to branch moves a step into a branch', async ( { page } ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( editUrl );
	await openEditor( page, /post\.php/ );

	const doneId = ( await definition( KEY ) ).definition.steps[ 1 ].id;
	await selectCard( page, doneId );
	await press( page.getByRole( 'button', { name: 'Move to branch' } ) );
	await expect(
		page.getByRole( 'menuitem', { name: 'Main steps' } )
	).toBeDisabled();
	await press(
		page.getByRole( 'menuitem', { name: /^decision\w*: No site name$/ } )
	);
	await expect.poll( () => selectedId( page ) ).toBe( doneId );
	await saveByKeyboard( page );

	const steps = ( await definition( KEY ) ).definition.steps;
	expect( steps ).toHaveLength( 1 );
	expect(
		steps[ 0 ].branches[ 0 ].steps.map( ( step ) => step.subject )
	).toEqual( [ 'Unnamed site', 'Done' ] );
} );

test( 'Test run shows the report of the saved automation', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( editUrl );
	await openEditor( page, /post\.php/ );
	// Only the Test run panel open, so its report shows in the screenshot.
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		const toggle = await openPanel( page, title );
		await press( toggle );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
	}
	await openPanel( page, 'Test run' );
	await press( sidebar( page ).getByRole( 'button', { name: 'Run test' } ) );
	const heading = sidebar( page ).getByRole( 'heading', {
		name: 'Test run result',
	} );
	await expect( heading ).toBeFocused();
	const report = sidebar( page ).locator( '.pegwork-automation__report' );
	// The site has a name and an address: Named site, then Site URL set.
	await expect(
		report.locator( '.pegwork-automation__report-heading' )
	).toHaveText( [ 'If Named site', 'If Site URL set' ] );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-test-run-1280.png' ),
	} );

	// The merge tag preview of a step renders its templates against the site.
	const nestedId = ( await definition( KEY ) ).definition.steps[ 0 ]
		.branches[ 1 ].steps[ 0 ].branches[ 0 ].steps[ 0 ].id;
	await selectCard( page, nestedId );
	await openBlockTab( page );
	const previewToggle = sidebar( page ).getByRole( 'button', {
		name: 'Preview',
		exact: true,
	} );
	if ( ( await previewToggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await press( previewToggle );
	}
	await press(
		sidebar( page ).getByRole( 'button', { name: 'Show preview' } )
	);
	const preview = sidebar( page ).getByRole( 'region', { name: 'Preview' } );
	await expect(
		preview.locator( '.pegwork-automation__preview-text' ).nth( 1 )
	).toHaveText( new RegExp( `^Visit ${ baseURL.replace( /\/$/, '' ) }/?$` ) );
	await checkPegworkA11y( page );
	await preview.scrollIntoViewIfNeeded();
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-preview-1280.png' ),
	} );
} );

test( 'a file automation is read-only and duplicates as a site automation', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	const row = page.getByRole( 'row', { name: new RegExp( FILE_LABEL ) } );
	await press( row.getByRole( 'link', { name: `View ${ FILE_LABEL }` } ) );
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	const frame = canvas( page );

	await expect( frame.locator( '.pegwork-automation-decision' ) ).toHaveCount(
		2
	);
	await expect(
		frame.getByRole( 'button', { name: /^Add (step|branch) to / } )
	).toHaveCount( 0 );
	// Panel controls sit in core's Disabled wrapper (inert), as in the form builder.
	const inert = ( locator ) =>
		locator.evaluate( ( el ) => Boolean( el.closest( '[inert]' ) ) );
	await openPanel( page, 'Automation' );
	expect(
		await inert(
			sidebar( page ).getByRole( 'textbox', {
				name: 'Label',
				exact: true,
			} )
		)
	).toBe( true );
	await openPanel( page, 'Trigger' );
	expect(
		await inert(
			sidebar( page ).getByRole( 'combobox', { name: 'Starts on' } )
		)
	).toBe( true );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-read-only-1280.png' ),
	} );
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-read-only-782.png' ),
	} );
	await page.setViewportSize( { width: 1280, height: 900 } );

	await press(
		sidebar( page ).getByRole( 'link', {
			name: 'Duplicate as site automation',
		} )
	);
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	await expect(
		canvas( page ).getByRole( 'textbox', { name: 'Add title' } )
	).toHaveText( `${ FILE_LABEL } (copy)` );
	await expect(
		canvas( page ).getByRole( 'button', { name: 'Add step to Named site' } )
	).toHaveCount( 1 );
	await saveByKeyboard( page );

	const copy = await definition( 'e2e_branch_copy' );
	expect( copy.source ).toBe( 'db' );
	expect( copy.definition.enabled ).toBe( false );
	const original = ( await definition( 'e2e_branch' ) ).definition;
	expect( copy.definition.steps ).toEqual( original.steps );
	expect( copy.definition.trigger ).toEqual( original.trigger );
} );
