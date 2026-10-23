/**
 * Loops and lists in the automation editor (Automation v3 PR 7), by keyboard only (no mouse calls):
 * barn_tasks_digest (design worked example c) is built control by control from Pegwork >
 * Automations > Add automation, saved, and read back through the admin e2e route; its canonical JSON
 * must equal tests/fixtures/valid/reads/automations/barn_tasks_digest.json byte for byte, except
 * `enabled`. On the way: the "Repeat for each" block and its "For each" list, the loop body's
 * appender offering neither Wait nor another loop, Move to naming the loop body, the picker's "Each
 * Task (in each_task)" group, and "Insert list of records" in the summary email. Then a Wait pasted
 * into the loop body is refused at its card with focus on the message, and Test run over two sample
 * tasks shows the item headings. Axe on the Pegwork parts and no horizontal scroll at 1280 and 782
 * px. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-barn.php (switched to package barn-reads: the reads
 * fixture's types and options page; sample tasks; canonical compare; reset) and
 * pegwork-e2e-automation.php (module toggle).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const {
	adminCaller,
	blockOption,
	canvas,
	checkPegworkA11y,
	choose,
	closePanel,
	focus,
	insertFrom,
	insertInto,
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

const def = require(
	path.join(
		__dirname,
		'..',
		'..',
		'fixtures',
		'valid',
		'reads',
		'automations',
		'barn_tasks_digest.json'
	)
);

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';

let admin;
const adminCall = adminCaller( () => admin );
let savedUrl;

const step = ( id ) =>
	[ ...def.steps, ...def.steps[ 1 ].steps ].find(
		( item ) => item.id === id
	);

// The selected block's inspector field by its exact name.
const field = ( page, name ) =>
	sidebar( page ).getByRole( 'textbox', { name, exact: true } );

// Opens the selected block's Advanced panel.
async function openAdvanced( page ) {
	await openBlockTab( page );
	const advanced = sidebar( page ).getByRole( 'button', {
		name: 'Advanced',
		exact: true,
	} );
	if ( ( await advanced.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await press( advanced );
	}
}

// Selects the card with id `from` and renames it to `to` in Advanced.
async function setStepId( page, from, to ) {
	await selectCard( page, from );
	await openAdvanced( page );
	await typeInto( field( page, 'Step ID' ), to );
	await expect(
		canvas( page ).locator( 'code', { hasText: new RegExp( `^${ to }$` ) } )
	).toHaveCount( 1 );
}

// A condition row of the selected read step's filter.
const row = ( page, n ) =>
	sidebar( page ).getByRole( 'group', {
		name: new RegExp( `^Condition ${ n }` ),
	} );

// Sends an email step's templates in from the fixture.
async function emailFields( page, email ) {
	await openBlockTab( page );
	await insertInto( field( page, 'To' ), email.to );
	await insertInto( field( page, 'Subject' ), email.subject );
	await insertInto( field( page, 'Text body' ), email.text );
}

// A screenshot at 1280 px, tall enough to show the inspector.
async function shot( page, name, height = 1100 ) {
	await page.setViewportSize( { width: 1280, height } );
	await page.screenshot( { path: path.join( SHOTS, name ) } );
	await page.setViewportSize( { width: 1280, height: 900 } );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reads', { reads: true } );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reads', { reads: false } );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'build barn_tasks_digest by keyboard; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const frame = canvas( page );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press( page.getByRole( 'link', { name: 'Add automation' } ) );
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	await typeInto(
		frame.getByRole( 'textbox', { name: 'Add title' } ),
		def.label
	);
	await openPanel( page, 'Automation' );
	await typeInto(
		sidebar( page ).getByRole( 'textbox', { name: 'Key' } ),
		def.key
	);

	// Every Monday (the site's week start) at 08:00, once per day.
	await openPanel( page, 'Trigger' );
	const panel = sidebar( page );
	await choose(
		panel.getByRole( 'combobox', { name: 'Starts on' } ),
		'schedule'
	);
	await choose( panel.getByRole( 'combobox', { name: 'Every' } ), 'week' );
	const at = panel.getByLabel( 'At (site time, optional)' );
	await focus( at );
	await page.keyboard.type( '0800AM' );
	await expect( at ).toHaveValue( def.trigger.at );
	await insertInto(
		panel.getByRole( 'textbox', { name: 'Run once per (optional)' } ),
		def.idempotency
	);
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}

	// due: open published tasks due within a week, by due date, up to 50.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Find records'
	);
	await setStepId( page, 'find', 'due' );
	await openBlockTab( page );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Content type' } ),
		'barn_task'
	);
	const matching = sidebar( page ).getByRole( 'radio', {
		name: 'Matching fields',
		exact: true,
	} );
	await press( matching, 'Space' );
	await expect( matching ).toBeChecked();
	// Matching fields opens with one filter row; the second comes from Add filter.
	await choose(
		row( page, 1 ).getByRole( 'combobox', { name: 'Field' } ),
		'done'
	);
	await choose(
		row( page, 1 ).getByRole( 'combobox', { name: 'Operator' } ),
		'ne'
	);
	await choose(
		row( page, 1 ).getByRole( 'combobox', { name: 'Value' } ),
		'true'
	);
	await press(
		sidebar( page ).getByRole( 'button', { name: 'Add filter' } )
	);
	await choose(
		row( page, 2 ).getByRole( 'combobox', { name: 'Field' } ),
		'due_on'
	);
	await choose(
		row( page, 2 ).getByRole( 'combobox', { name: 'Operator' } ),
		'lte'
	);
	await choose(
		row( page, 2 ).getByRole( 'combobox', { name: 'Compare with' } ),
		'date'
	);
	await typeInto(
		row( page, 2 ).getByRole( 'spinbutton', { name: 'Amount' } ),
		'7'
	);
	await typeInto(
		sidebar( page ).getByRole( 'spinbutton', { name: 'Limit' } ),
		'50'
	);
	const more = sidebar( page ).getByRole( 'button', {
		name: 'More options',
	} );
	if ( ( await more.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await press( more );
	}
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Sort by' } ),
		'due_on'
	);
	const draftsToo = sidebar( page ).getByRole( 'checkbox', {
		name: 'Private',
		exact: true,
	} );
	await press( draftsToo, 'Space' );
	await expect( draftsToo ).not.toBeChecked();

	// each_task: Repeat for each over due's records.
	await insertMain( page, 'Repeat for each' );
	await setStepId( page, 'each', 'each_task' );
	await openBlockTab( page );
	const forEach = sidebar( page ).getByRole( 'combobox', {
		name: 'For each',
	} );
	await expect( forEach.locator( 'option' ) ).toContainText( [
		'Choose a list of records',
		'Tasks from due',
	] );
	await choose( forEach, def.steps[ 1 ].over );
	await expect(
		frame
			.locator(
				'.pegwork-automation-loop .pegwork-automation-step__summary'
			)
			.first()
	).toHaveText( 'For each Task from due (at most 50)' );

	// The body's appender offers neither Wait nor another loop.
	const appender = frame.getByRole( 'button', {
		name: 'Add step for each item',
	} );
	await press( appender );
	const box = page.getByRole( 'searchbox' ).last();
	await focus( box );
	for ( const name of [ 'Wait', 'Repeat for each' ] ) {
		await page.keyboard.press( 'ControlOrMeta+a' );
		await page.keyboard.type( name );
		// Results or "No results found." for this search, once it has run.
		await expect(
			page
				.locator(
					'.block-editor-inserter__no-results, .block-editor-inserter__quick-inserter [role="option"]'
				)
				.first()
		).toBeVisible();
		await expect( blockOption( page, name ) ).toHaveCount( 0 );
	}
	await page.keyboard.press( 'ControlOrMeta+a' );
	await page.keyboard.type( 'Send email' );
	await press( blockOption( page, 'Send email' ) );
	await expect( page.getByRole( 'searchbox' ) ).toHaveCount( 0 );

	// remind: to each task's assignee, continuing if it fails.
	await setStepId( page, 'email', 'remind' );
	const go = sidebar( page ).getByRole( 'radio', {
		name: 'Continue',
		exact: true,
	} );
	await press( go, 'Space' );
	await expect( go ).toBeChecked();
	await openBlockTab( page );
	await press(
		sidebar( page ).getByRole( 'button', {
			name: 'Insert merge tag into Subject',
		} )
	);
	const tag = page.getByRole( 'combobox', { name: 'Merge tag' } );
	await expect( tag ).toBeVisible();
	const groups = await tag
		.locator( 'optgroup' )
		.evaluateAll( ( list ) => list.map( ( item ) => item.label ) );
	expect( groups ).toContain( 'Each Task (in each_task)' );
	await choose( tag, 'steps.each_task.item.name' );
	await press( page.getByRole( 'button', { name: 'Insert', exact: true } ) );
	await expect( field( page, 'Subject' ) ).toHaveValue(
		'{{steps.each_task.item.name}}'
	);
	await emailFields( page, step( 'remind' ) );

	// stamp: the item's reminded_on, picked from the Task fields ({{steps.each_task.item.id}}).
	await insertMain( page, 'Update record' );
	await setStepId( page, 'update', 'stamp' );
	await openBlockTab( page );
	const stamp = step( 'stamp' );
	await insertInto( field( page, 'Record (optional)' ), stamp.record );
	await press( sidebar( page ).getByRole( 'button', { name: 'Add value' } ) );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Field 1' } ),
		'reminded_on'
	);
	await insertInto(
		field( page, 'Value of Reminded on' ),
		stamp.values.reminded_on
	);

	// Move to names the loop body; the body is where stamp is now.
	await selectCard( page, 'stamp' );
	// Typing hides the block toolbar; Alt+F10 brings it back with focus.
	await page.keyboard.press( 'Alt+F10' );
	await press( page.getByRole( 'button', { name: 'Move to', exact: true } ) );
	await expect(
		page.getByRole( 'menuitem', { name: 'Repeat each_task' } )
	).toBeDisabled();
	await expect(
		page.getByRole( 'menuitem', { name: 'Main steps' } )
	).toBeEnabled();
	await page.keyboard.press( 'Escape' );

	await checkPegworkA11y( page );
	await selectCard( page, 'each_task' );
	await openBlockTab( page );
	await shot( page, 'automation-loop-1280.png' );

	// summary: after the loop, with the list of due tasks.
	await insertMain( page, 'Send email' );
	await setStepId( page, 'email', 'summary' );
	await openBlockTab( page );
	await press(
		sidebar( page ).getByRole( 'button', {
			name: 'Insert list of records into Text body',
		} )
	);
	const list = page.getByRole( 'combobox', { name: 'List', exact: true } );
	await expect( list ).toBeFocused();
	await expect( list.locator( 'option' ) ).toHaveText( [ 'Tasks from due' ] );
	await checkPegworkA11y( page );
	await shot( page, 'automation-loop-list-1280.png' );
	await press(
		page.getByRole( 'button', { name: 'Insert list', exact: true } )
	);
	await expect( field( page, 'Text body' ) ).toHaveValue(
		'{{#each steps.due.records}}- {{item.name}}\n{{/each}}'
	);
	await emailFields( page, step( 'summary' ) );

	await saveByKeyboard( page );
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
	await expect(
		page.getByRole( 'button', { name: 'Go to first error' } )
	).toHaveCount( 0 );
	const compared = await adminCall(
		'GET',
		'/pegwork-e2e/v1/barn/compare',
		undefined,
		{ key: def.key }
	);
	expect( compared.source ).toBe( 'db' );
	expect( compared.enabled ).toBe( false );
	expect( compared.saved ).toBe( compared.fixture );
	savedUrl = page.url();

	// Reloaded: the loop holds its two steps; axe at 1280 and 782 px.
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await expect(
		frame.locator( '.pegwork-automation-loop .pegwork-automation-step' )
	).toHaveCount( 2 );
	await checkPegworkA11y( page );
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkPegworkA11y( page );
	const settings = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );
	if ( ( await settings.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await press( settings );
	}
	await selectCard( page, 'each_task' );
	await openBlockTab( page );
	await checkPegworkA11y( page );
	// The sidebar covers the canvas at this width: the shot is of the canvas.
	await press( settings );
	await expect( settings ).toHaveAttribute( 'aria-pressed', 'false' );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-loop-782.png' ),
	} );
	await page.setViewportSize( { width: 1280, height: 900 } );
} );

test( 'a Wait pasted into the loop body is refused at its card, with focus on the message', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page
		.context()
		.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await page.goto( savedUrl );
	await openEditor( page, /post\.php/ );
	const frame = canvas( page );

	// A Wait after the summary (offered there), copied by keyboard; Move to offers no loop body.
	await selectCard( page, 'summary' );
	await insertMain( page, 'Wait' );
	const wait = await selectCard( page, 'wait' );
	await press( page.getByRole( 'button', { name: 'Move to', exact: true } ) );
	await expect(
		page.getByRole( 'menuitem', { name: 'Main steps' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'menuitem', { name: 'Repeat each_task' } )
	).toHaveCount( 0 );
	await page.keyboard.press( 'Escape' );
	await wait.focus();
	await page.keyboard.press( 'ControlOrMeta+c' );

	// Pasted over stamp inside the loop: the card says why, and the message has focus.
	await selectCard( page, 'stamp' );
	await page.keyboard.press( 'ControlOrMeta+v' );
	const refused = frame.getByRole( 'alert' ).filter( {
		hasText: 'A loop cannot hold a wait',
	} );
	await expect( refused ).toBeVisible();
	await expect( refused ).toBeFocused();
	await expect(
		frame.locator(
			'.pegwork-automation-loop .pegwork-automation-step.has-error'
		)
	).toHaveCount( 1 );
	await checkPegworkA11y( page );
	await shot( page, 'automation-loop-refused-1280.png' );
} );

test( 'Test run of barn_tasks_digest shows a heading per task', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	const day = ( offset ) =>
		new Date( Date.now() + offset * 86_400_000 )
			.toISOString()
			.slice( 0, 10 );
	const first = await adminCall( 'POST', '/pegwork-e2e/v1/barn/task', {
		name: 'Call Ada',
		due_on: day( 1 ),
		assignee_email: 'ada@example.org',
	} );
	const second = await adminCall( 'POST', '/pegwork-e2e/v1/barn/task', {
		name: 'Send the barn photos',
		due_on: day( 3 ),
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/task', {
		name: 'Already done',
		due_on: day( 2 ),
		done: true,
	} );

	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( savedUrl );
	await openEditor( page, /post\.php/ );
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}
	await openPanel( page, 'Test run' );
	const panel = sidebar( page );
	await press( panel.getByRole( 'button', { name: 'Run test' } ) );
	await expect(
		panel.getByRole( 'heading', { name: 'Test run result' } )
	).toBeFocused();
	const headings = panel.locator( '.pegwork-automation__report-heading' );
	await expect( headings ).toHaveText( [
		`Item 1 of 2: Task ${ first.id } "Call Ada"`,
		`Item 2 of 2: Task ${ second.id } "Send the barn photos"`,
	] );
	await expect(
		panel.locator( '.pegwork-automation__report' )
	).toContainText( 'summary' );
	await checkPegworkA11y( page );
	await shot( page, 'automation-loop-test-run-1280.png', 1300 );
} );
