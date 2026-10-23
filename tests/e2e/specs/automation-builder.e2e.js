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
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const LABEL = 'E2E built route';
const KEY = 'e2e_built_route';
const FILE_LABEL = 'E2E branching digest';

let admin;
let editUrl;

async function adminCall( method, route, data, params ) {
	const res = await admin.fetch( restUrl( route ), {
		method,
		headers: { 'X-WP-Nonce': await restNonce( admin ) },
		data,
		params,
	} );
	if ( ! res.ok() ) {
		throw new Error(
			`${ method } ${ route } failed: ${ res.status() } ${ await res.text() }`
		);
	}
	return res.json();
}

const definition = async ( key ) =>
	adminCall( 'GET', '/pegwork-e2e/v1/automation/definition', undefined, {
		key,
	} );

// Moves focus to a control and activates it with the keyboard.
async function press( locator, key = 'Enter' ) {
	await locator.focus();
	await expect( locator ).toBeFocused();
	await locator.page().keyboard.press( key );
}

// Focuses a text field, selects what it holds and types over it.
async function typeInto( locator, text ) {
	await locator.focus();
	await expect( locator ).toBeFocused();
	await locator.page().keyboard.press( 'ControlOrMeta+a' );
	await locator.page().keyboard.type( text );
}

// Focuses a select and picks an option by value (no pointer: the change event a key press makes).
async function choose( locator, value ) {
	await locator.focus();
	await expect( locator ).toBeFocused();
	await locator.selectOption( value );
}

function canvas( page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

function sidebar( page ) {
	return page.getByRole( 'region', { name: 'Editor settings' } );
}

async function openEditor( page, pattern ) {
	await page.waitForURL( pattern );
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);
	await page.evaluate( () => {
		const prefs = window.wp.data.dispatch( 'core/preferences' );
		prefs.set( 'core/edit-post', 'welcomeGuide', false );
		prefs.set( 'core', 'editorMode', 'visual' );
	} );
}

// Opens the settings sidebar on the document tab, and a document panel by its title.
async function openPanel( page, title ) {
	const settings = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );
	if ( ( await settings.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await press( settings );
	}
	const tab = sidebar( page ).getByRole( 'tab' ).first();
	if ( ( await tab.getAttribute( 'aria-selected' ) ) !== 'true' ) {
		await press( tab );
	}
	const toggle = sidebar( page ).getByRole( 'button', {
		name: title,
		exact: true,
	} );
	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await press( toggle );
	}
	return toggle;
}

// Opens the Block tab of the sidebar (the selected block's inspector).
async function openBlockTab( page ) {
	const tab = sidebar( page ).getByRole( 'tab', { name: 'Block' } );
	if ( ( await tab.getAttribute( 'aria-selected' ) ) !== 'true' ) {
		await press( tab );
	}
}

async function saveByKeyboard( page ) {
	await page.keyboard.press( 'ControlOrMeta+s' );
	await page.waitForFunction(
		() => ! window.wp.data.select( 'core/editor' ).isSavingPost()
	);
}

// A block in inserter results (its accessible name starts with the icon).
function blockOption( page, name ) {
	return page
		.getByRole( 'option', { name: new RegExp( `^[^A-Za-z]*${ name }$` ) } )
		.first();
}

// Opens a quick inserter from an appender button, searches and picks a block, all by keyboard.
async function insertFrom( page, appender, search ) {
	await press( appender );
	const box = page.getByRole( 'searchbox' ).last();
	await expect( box ).toBeFocused();
	await page.keyboard.type( search );
	await press( blockOption( page, search ) );
	await expect( page.getByRole( 'searchbox' ) ).toHaveCount( 0 );
}

// Inserts a block after the selected one from the main inserter, by keyboard.
async function insertMain( page, search ) {
	const toggle = page.getByRole( 'button', {
		name: /^(Block Inserter|Toggle block inserter)$/,
	} );
	await press( toggle );
	const box = page.getByRole( 'searchbox', {
		name: /Search for blocks and patterns|Search/,
	} );
	// Focus lands on the search box (WordPress 6.7) or the Blocks tab before it (later versions).
	for ( let i = 0; i < 3; i++ ) {
		if ( await box.evaluate( ( el ) => el === document.activeElement ) ) {
			break;
		}
		await page.keyboard.press( 'Tab' );
	}
	await expect( box ).toBeFocused();
	await page.keyboard.type( search );
	await press( blockOption( page, search ) );
	if ( ( await toggle.getAttribute( 'aria-pressed' ) ) === 'true' ) {
		await press( toggle );
	}
}

const selectedId = ( page ) =>
	page.evaluate( () => {
		const editor = window.wp.data.select( 'core/block-editor' );
		const id = editor.getSelectedBlockClientId();
		return id ? editor.getBlockAttributes( id ).id ?? null : null;
	} );

// Selects a step or decision card by focusing its wrapper.
async function selectCard( page, id ) {
	// The nearest block wrapper around the card's id (a decision holds its branches' cards too).
	const card = canvas( page )
		.locator( 'code', { hasText: new RegExp( `^${ id }$` ) } )
		.locator(
			'xpath=ancestor::*[contains(concat(" ", normalize-space(@class), " "), " block-editor-block-list__block ")][1]'
		);
	await card.focus();
	await expect.poll( () => selectedId( page ) ).toBe( id );
	return card;
}

// Selects a branch by focusing its name field in the canvas, and opens its inspector.
async function selectBranch( page, name, nth = 0 ) {
	const field = canvas( page )
		.getByRole( 'textbox', { name: 'Name:' } )
		.filter( { hasText: name } )
		.nth( nth );
	// Held by ID: a rename makes the text filter miss.
	const stable = canvas( page ).locator(
		`[id="${ await field.getAttribute( 'id' ) }"]`
	);
	await stable.focus();
	await expect( stable ).toBeFocused();
	await openBlockTab( page );
	await stable.focus();
	return stable;
}

async function renameBranch( page, from, to ) {
	const field = await selectBranch( page, from );
	await page.keyboard.press( 'ControlOrMeta+a' );
	await page.keyboard.type( to );
	await expect( field ).toHaveValue( to );
}

// Gives the selected branch one condition: field and operator.
async function setCondition( page, field, op ) {
	const panel = sidebar( page );
	await press( panel.getByRole( 'button', { name: 'Add condition' } ) );
	const fieldSelect = panel.getByRole( 'combobox', { name: 'Field' } );
	if ( field ) {
		await choose( fieldSelect, field );
	}
	await choose( panel.getByRole( 'combobox', { name: 'Operator' } ), op );
}

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

// Axe on the Pegwork parts: canvas cards, the document panels and the block inspector.
async function checkPegworkA11y( page ) {
	let builder = new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
		.include( {
			fromFrames: [
				'iframe[name="editor-canvas"]',
				'.pegwork-automation-step, .pegwork-automation-decision',
			],
		} );
	for ( const selector of [
		'.pegwork-automation-settings',
		'.block-editor-block-inspector',
	] ) {
		if ( await page.locator( selector ).count() ) {
			builder = builder.include( selector );
		}
	}
	const { violations } = await builder.analyze();
	const serious = violations
		.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' )
		.map(
			( v ) =>
				`${ v.impact } ${ v.id }: ${ v.help } (${ v.nodes.length } nodes, first: ${ v.nodes[ 0 ]?.target })`
		);
	expect( serious, 'serious or critical axe violations' ).toEqual( [] );
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth - window.innerWidth
	);
	expect( overflow, 'horizontal page scroll' ).toBeLessThanOrEqual( 0 );
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

	await typeInto( frame.getByRole( 'textbox', { name: 'Add title' } ), LABEL );
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
	await press( sidebar( page ).getByRole( 'button', { name: 'Show preview' } ) );
	const preview = sidebar( page ).getByRole( 'region', { name: 'Preview' } );
	await expect(
		preview.locator( '.pegwork-automation__preview-text' ).nth( 1 )
	).toHaveText(
		new RegExp( `^Visit ${ baseURL.replace( /\/$/, '' ) }/?$` )
	);
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
			sidebar( page ).getByRole( 'textbox', { name: 'Label', exact: true } )
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
