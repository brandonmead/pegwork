/**
 * Builder fixes from the October 2026 QA pass, by keyboard only (no mouse calls):
 * B-06 missing fields shown at their controls in plain words, with one notice (no core "Updating
 * failed."); B-01 a step pasted before its original gets a new id and the automation saves;
 * B-02 and B-04 the merge tag picker has one Site group (with Run ID) and names trigger tags in
 * words; B-05 a site copy of a file automation with signed links does not point at a JSON file.
 * Axe on the Pegwork parts at 1280 and 782 px, no horizontal page scroll.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (module toggle, definition read, site
 * reset) and the examples/sifg package (sifg_staleness_check has signed links).
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
	saveByKeyboard,
	selectCard,
	sidebar,
	typeInto,
} = require( '../utils/builder' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const KEY = 'e2e_qa_builder';

let admin;
let editUrl;
const adminCall = adminCaller( () => admin );

const field = ( page, name ) =>
	sidebar( page ).getByRole( 'textbox', { name, exact: true } );

const stepIds = ( page ) =>
	page.evaluate( () =>
		window.wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.map( ( block ) => block.attributes.id )
	);

const noticeIds = ( page ) =>
	page.evaluate( () =>
		window.wp.data
			.select( 'core/notices' )
			.getNotices()
			.map( ( notice ) => notice.id )
	);

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

test( 'missing fields are shown at their controls, with one notice (B-06)', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press( page.getByRole( 'link', { name: 'Add automation' } ) );
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	const frame = canvas( page );
	await typeInto(
		frame.getByRole( 'textbox', { name: 'Add title' } ),
		'E2E QA builder'
	);
	await openPanel( page, 'Trigger' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Starts on' } ),
		'schedule'
	);
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Send email'
	);
	await saveByKeyboard( page );

	// One notice on screen: ours, not core's "Updating failed." with every message in one line.
	const notices = page.locator( '.components-notice' );
	const notice = notices.filter( {
		hasText:
			'3 problems stop this automation from saving. Each is shown at its step or setting.',
	} );
	await expect( notice ).toBeVisible();
	await expect( notices ).toHaveCount( 1 );
	expect( await noticeIds( page ) ).not.toContain( 'editor-save' );

	await press( page.getByRole( 'button', { name: 'Go to first error' } ) );
	await openBlockTab( page );
	for ( const [ name, words ] of [
		[ 'To', 'Required: To.' ],
		[ 'Subject', 'Required: Subject.' ],
		[ 'Text body', 'Required: Text.' ],
	] ) {
		const control = field( page, name );
		await expect( control ).toHaveAttribute( 'aria-invalid', 'true' );
		const errorId = ( await control.getAttribute( 'aria-describedby' ) )
			.split( ' ' )
			.find( ( id ) => id.endsWith( '-errors' ) );
		await expect( page.locator( `[id="${ errorId }"]` ) ).toHaveText(
			`Error: ${ words }`
		);
	}
	const card = frame.locator( '.pegwork-automation-step.has-error' );
	await expect( card ).toContainText( 'Required: Subject.' );
	await expect( card ).not.toContainText( 'Missing required property' );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-builder-required-1280.png' ),
	} );
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkPegworkA11y( page );
	await page.setViewportSize( { width: 1280, height: 900 } );

	// Filled, it saves and both notices go.
	await typeInto( field( page, 'To' ), 'desk@example.org' );
	await typeInto( field( page, 'Subject' ), 'First' );
	await typeInto( field( page, 'Text body' ), 'Run {{run.id}}.' );
	await saveByKeyboard( page );
	await expect( notice ).toHaveCount( 0 );
	await expect( frame.locator( '.has-error' ) ).toHaveCount( 0 );
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
	editUrl = page.url();
} );

test( 'a step pasted before its original gets a new id and saves (B-01)', async ( {
	page,
} ) => {
	await page
		.context()
		.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( editUrl );
	await openEditor( page, /post\.php/ );
	await selectCard( page, 'email' );
	await insertMain( page, 'Send email' );
	await openBlockTab( page );
	await typeInto( field( page, 'To' ), 'desk@example.org' );
	await typeInto( field( page, 'Subject' ), 'Second' );
	await typeInto( field( page, 'Text body' ), 'Again.' );
	expect( await stepIds( page ) ).toEqual( [ 'email', 'email_2' ] );

	// Copy the second step and paste it over the first (a selected block is replaced), so the
	// copy lands before its original. It takes the free id; the original keeps email_2.
	await selectCard( page, 'email_2' );
	await page.keyboard.press( 'ControlOrMeta+c' );
	await selectCard( page, 'email' );
	await page.keyboard.press( 'ControlOrMeta+v' );
	await expect
		.poll( () =>
			page.evaluate( () =>
				window.wp.data
					.select( 'core/block-editor' )
					.getBlocks()
					.map(
						( block ) =>
							`${ block.attributes.id } ${ block.attributes.subject }`
					)
			)
		)
		.toEqual( [ 'email Second', 'email_2 Second' ] );

	await saveByKeyboard( page );
	await expect( canvas( page ).locator( '.has-error' ) ).toHaveCount( 0 );
	const saved = await adminCall(
		'GET',
		'/pegwork-e2e/v1/automation/definition',
		undefined,
		{ key: KEY }
	);
	expect(
		saved.definition.steps.map(
			( step ) => `${ step.id } ${ step.subject }`
		)
	).toEqual( [ 'email Second', 'email_2 Second' ] );
} );

test( 'the merge tag picker has one Site group and names trigger tags in words (B-02, B-04)', async ( {
	page,
} ) => {
	const duplicateKeys = [];
	page.on( 'console', ( message ) => {
		if ( /same key/.test( message.text() ) ) {
			duplicateKeys.push( message.text() );
		}
	} );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( editUrl );
	await openEditor( page, /post\.php/ );
	await openPanel( page, 'Trigger' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Starts on' } ),
		'record'
	);
	await selectCard( page, 'email' );
	await openBlockTab( page );
	await press(
		sidebar( page ).getByRole( 'button', {
			name: 'Insert merge tag into Subject',
		} )
	);
	const picker = page.getByRole( 'combobox', { name: 'Merge tag' } );
	await expect( picker ).toBeFocused();
	const groups = await picker
		.locator( 'optgroup' )
		.evaluateAll( ( list ) => list.map( ( group ) => group.label ) );
	expect( groups.filter( ( label ) => label === 'Site' ) ).toHaveLength( 1 );
	expect( new Set( groups ).size ).toBe( groups.length );
	await expect(
		picker.locator( 'optgroup[label="Site"] option[value="run.id"]' )
	).toHaveCount( 1 );
	const trigger = await picker
		.locator( 'optgroup[label="Trigger"] option' )
		.allTextContents();
	expect( trigger ).toEqual( [
		'Record ID {{trigger.id}}',
		'Content type {{trigger.type}}',
		'Status {{trigger.status}}',
		'Changed by (user ID) {{trigger.user_id}}',
	] );
	await page.keyboard.press( 'Escape' );
	await checkPegworkA11y( page );
	expect( duplicateKeys ).toEqual( [] );
} );

test( 'a site copy of a file automation with signed links does not point at a JSON file (B-05)', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	const label =
		'Yearly still-active check: ask, wait 30 days, pause if no reply';
	const row = page.getByRole( 'row', { name: new RegExp( label ) } );
	await press( row.getByRole( 'link', { name: `View ${ label }` } ) );
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await openPanel( page, 'Automation' );
	await press(
		sidebar( page ).getByRole( 'link', {
			name: 'Duplicate as site automation',
		} )
	);
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	await openPanel( page, 'Trigger' );
	const links = page.locator( '.pegwork-automation__links' );
	await expect( links ).toContainText(
		'Signed links (read-only here, kept when you save)'
	);
	await expect( links ).not.toContainText( 'JSON file' );
	await checkPegworkA11y( page );
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkPegworkA11y( page );
} );
