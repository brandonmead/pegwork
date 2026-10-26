/**
 * Automation v4 Editor A (design `docs/automation-v4-design.md` sections 5.1 and 5.4), keyboard only
 * (no mouse calls): barn_task_due_reminder is built from Pegwork > Automations > Add automation, its
 * wait set to "Until a date" (3 days before Due on at 09:00) after the disabled reason was shown on a
 * schedule trigger; barn_volunteer_review gets its To from "Add a role" (Editor) plus the volunteer
 * manager option. Each is saved and its canonical JSON must equal the fixture in
 * tests/fixtures/valid/v4/automations byte for byte, except `enabled`. Test run on each shows the date
 * wait line and the role line with a count, never a member address. Axe on the Pegwork parts and no
 * horizontal scroll at 1280 and 782 px. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-barn.php (the barn types, form and options page; sample
 * task and volunteer; the editor user; canonical compare; reset) and pegwork-e2e-automation.php
 * (module toggle).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const {
	adminCaller,
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
	radio,
	saveByKeyboard,
	selectCard,
	setCondition,
	sidebar,
	typeInto,
} = require( '../utils/builder' );

const fixture = ( key ) =>
	require(
		path.join(
			__dirname,
			'..',
			'..',
			'fixtures',
			'valid',
			'v4',
			'automations',
			`${ key }.json`
		)
	);

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';

let admin;
const adminCall = adminCaller( () => admin );
const saved = {};
let task;
let volunteer;

// A site date (the e2e sites run in UTC) some days from now.
const day = ( days ) =>
	new Date( Date.now() + days * 86_400_000 ).toISOString().slice( 0, 10 );

// The selected block's inspector field by its exact name.
const field = ( page, name ) =>
	sidebar( page ).getByRole( 'textbox', { name, exact: true } );

// Selects the card with id `from` and renames it to `to` in Advanced.
async function setStepId( page, from, to ) {
	await selectCard( page, from );
	await openBlockTab( page );
	const advanced = sidebar( page ).getByRole( 'button', {
		name: 'Advanced',
		exact: true,
	} );
	if ( ( await advanced.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await press( advanced );
	}
	await typeInto( field( page, 'Step ID' ), to );
	await expect(
		canvas( page ).locator( 'code', { hasText: new RegExp( `^${ to }$` ) } )
	).toHaveCount( 1 );
}

// Starts a new automation from the list and sets its title, key and trigger kind.
async function startAutomation( page, definition, startsOn ) {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press( page.getByRole( 'link', { name: 'Add automation' } ) );
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	await typeInto(
		canvas( page ).getByRole( 'textbox', { name: 'Add title' } ),
		definition.label
	);
	await openPanel( page, 'Automation' );
	await typeInto(
		sidebar( page ).getByRole( 'textbox', { name: 'Key' } ),
		definition.key
	);
	await openPanel( page, 'Trigger' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Starts on' } ),
		startsOn
	);
}

// Saves, then checks the saved definition against its fixture in canonical form.
async function saveAndCompare( page, key ) {
	await saveByKeyboard( page );
	await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
	await expect(
		page.getByRole( 'button', { name: 'Go to first error' } )
	).toHaveCount( 0 );
	const compared = await adminCall(
		'GET',
		'/pegwork-e2e/v1/barn/compare',
		undefined,
		{ key }
	);
	expect( compared.source ).toBe( 'db' );
	expect( compared.saved ).toBe( compared.fixture );
	saved[ key ] = page.url();
}

// Axe and no horizontal scroll at 1280 px, then at 782 px with the inspector reopened.
async function checkBothWidths( page, id, name ) {
	await page.setViewportSize( { width: 1280, height: 1100 } );
	await selectCard( page, id );
	await openBlockTab( page );
	await checkPegworkA11y( page );
	await page.screenshot( { path: path.join( SHOTS, `${ name }-1280.png` ) } );
	await page.setViewportSize( { width: 782, height: 1100 } );
	const settings = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );
	if ( ( await settings.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await press( settings );
	}
	await selectCard( page, id );
	await openBlockTab( page );
	await checkPegworkA11y( page );
	await page.screenshot( { path: path.join( SHOTS, `${ name }-782.png` ) } );
	await page.setViewportSize( { width: 1280, height: 900 } );
}

// Runs a test of the saved automation `key` on a sample record found by its title.
async function testRun( page, key, sample ) {
	await page.setViewportSize( { width: 1280, height: 1100 } );
	await page.goto( saved[ key ] );
	await openEditor( page, /post\.php/ );
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}
	await openPanel( page, 'Test run' );
	const panel = sidebar( page );
	const box = panel.getByRole( 'combobox', { name: 'Sample record' } );
	await typeInto( box, sample.title );
	const option = panel.getByRole( 'option', {
		name: `${ sample.title } (#${ sample.id })`,
	} );
	await expect( option ).toBeVisible();
	await page.keyboard.press( 'ArrowDown' );
	await page.keyboard.press( 'Enter' );
	await expect( box ).toHaveValue( `${ sample.title } (#${ sample.id })` );
	await press( panel.getByRole( 'button', { name: 'Run test' } ) );
	await expect(
		panel.getByRole( 'heading', { name: 'Test run result' } )
	).toBeFocused();
	return panel.locator( '.pegwork-automation__report' );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/editor' );
	const created = await adminCall( 'POST', '/pegwork-e2e/v1/barn/task', {
		name: 'E2E due in a month',
		due_on: day( 30 ),
		assignee_email: 'staff@example.org',
	} );
	task = { id: created.id, title: 'E2E due in a month' };
	volunteer = await adminCall( 'POST', '/pegwork-e2e/v1/barn/volunteer', {
		name: 'E2E applicant',
		email: 'applicant@example.org',
		interest: 'events',
	} );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'build barn_task_due_reminder by keyboard, with a wait until a date; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const def = fixture( 'barn_task_due_reminder' );
	const frame = canvas( page );
	await startAutomation( page, def, 'schedule' );
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}

	// before_due: on a schedule (no record), "Until a date" is disabled and says why.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Wait'
	);
	await setStepId( page, 'wait', 'before_due' );
	const until = sidebar( page ).getByRole( 'radio', {
		name: 'Until a date',
		exact: true,
	} );
	await expect(
		sidebar( page ).getByRole( 'radio', {
			name: 'For a length of time',
			exact: true,
		} )
	).toBeChecked();
	await expect( until ).toBeDisabled();
	await expect( until ).toHaveAccessibleDescription(
		'Needs a trigger with a record of one content type that has a date field.'
	);
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-wait-until-disabled-1280.png' ),
	} );

	// The trigger: a task is created.
	await openPanel( page, 'Trigger' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Starts on' } ),
		'record'
	);
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Content type' } ),
		def.trigger.content_type
	);
	await expect(
		sidebar( page ).getByRole( 'combobox', { name: 'When a record is' } )
	).toHaveValue( def.trigger.event );
	await closePanel( page, 'Trigger' );

	// Now the wait can wait until the task's Due on: 3 days before, at 09:00 (the default).
	await selectCard( page, 'before_due' );
	await openBlockTab( page );
	await radio( page, 'Until a date' );
	const dateField = sidebar( page ).getByRole( 'combobox', {
		name: 'Date field',
	} );
	await expect( dateField ).toHaveValue( def.steps[ 0 ].until.var );
	await expect( dateField.locator( 'option' ) ).toHaveText( [
		'Due on',
		'Reminded on',
	] );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Before or after' } ),
		'before'
	);
	await typeInto(
		sidebar( page ).getByRole( 'spinbutton', { name: 'Amount' } ),
		'3'
	);
	await expect(
		sidebar( page ).getByRole( 'combobox', { name: 'Unit' } )
	).toHaveValue( 'days' );
	await expect(
		sidebar( page ).getByLabel( 'At', { exact: true } )
	).toHaveValue( def.steps[ 0 ].until.at );
	const card = frame.locator( '.pegwork-automation-step' ).first();
	await expect(
		card.locator( '.pegwork-automation-step__summary' )
	).toHaveText( 'Wait until 3 days before Due on at 09:00' );
	await expect( card.locator( '.pegwork-automation-step__note' ) ).toHaveText(
		'Checks the date again when it wakes. Waits for a date if it is empty.'
	);

	// still_open: not done, and not overdue.
	await insertMain( page, 'Continue only if' );
	await setStepId( page, 'check', 'still_open' );
	await setCondition( page, 'record.done', 'ne' );
	const panel = sidebar( page );
	await expect(
		panel.getByRole( 'combobox', { name: 'Value', exact: true } )
	).toHaveValue( 'true' );
	await press( panel.getByRole( 'button', { name: 'Add condition' } ) );
	const second = panel.locator( '.pegwork-automation__condition' ).nth( 1 );
	await choose(
		second.getByRole( 'combobox', { name: 'Field' } ),
		'record.due_on'
	);
	await choose( second.getByRole( 'combobox', { name: 'Operator' } ), 'gte' );
	await choose(
		second.getByRole( 'combobox', { name: 'Compare with' } ),
		'date'
	);
	await expect(
		second.getByRole( 'combobox', { name: 'From' } )
	).toHaveValue( 'today' );

	// remind and stamp.
	const remind = def.steps[ 2 ];
	await insertMain( page, 'Send email' );
	await setStepId( page, 'email', 'remind' );
	await insertInto( field( page, 'To' ), remind.to );
	await insertInto( field( page, 'Subject' ), remind.subject );
	await insertInto( field( page, 'Text body' ), remind.text );
	await insertMain( page, 'Update record' );
	await setStepId( page, 'update', 'stamp' );
	await press( panel.getByRole( 'button', { name: 'Add value' } ) );
	await choose(
		panel.getByRole( 'combobox', { name: 'Field 1' } ),
		'reminded_on'
	);
	await insertInto(
		field( page, 'Value of Reminded on' ),
		def.steps[ 3 ].values.reminded_on
	);

	await saveAndCompare( page, def.key );
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await expect( frame.locator( '.pegwork-automation-step' ) ).toHaveCount(
		4
	);
	await checkBothWidths( page, 'before_due', 'automation-wait-until' );
} );

test( 'Test run of barn_task_due_reminder says when the date wait would wake', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	const report = await testRun( page, 'barn_task_due_reminder', task );
	await expect( report ).toContainText(
		'Wait before_due: until 3 days before Due on ('
	);
	await expect( report ).toContainText(
		'When it wakes it checks the date again.'
	);
	await expect( report ).toContainText( 'remind' );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-wait-until-test-run-1280.png' ),
	} );
} );

test( 'build barn_volunteer_review by keyboard with "Add a role"; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 240_000 );
	const def = fixture( 'barn_volunteer_review' );
	const notify = def.steps[ 0 ];
	const frame = canvas( page );
	await startAutomation( page, def, 'form' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Form' } ),
		def.trigger.form
	);
	await expect(
		sidebar( page ).getByRole( 'combobox', { name: 'When an entry is' } )
	).toHaveValue( def.trigger.event );
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}

	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Send email'
	);
	await setStepId( page, 'email', 'notify' );
	const to = field( page, 'To' );
	await press(
		sidebar( page ).getByRole( 'button', { name: 'Add a role to To' } )
	);
	const role = page.getByRole( 'combobox', { name: 'Role', exact: true } );
	await expect( role ).toBeFocused();
	await expect( role.locator( 'option', { hasText: 'Editor' } ) ).toHaveCount(
		1
	);
	await expect(
		role.locator( 'option', { hasText: 'Subscriber' } )
	).toHaveCount( 0 );
	await choose( role, 'editor' );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-add-role-1280.png' ),
	} );
	await press(
		page.getByRole( 'button', { name: 'Add role', exact: true } )
	);
	await expect( to ).toHaveValue( '{role:editor}' );
	await expect( to ).toBeFocused();
	await page.keyboard.press( 'End' );
	await page.keyboard.insertText( notify.to.slice( '{role:editor}'.length ) );
	await expect( to ).toHaveValue( notify.to );
	await insertInto( field( page, 'Subject' ), notify.subject );
	await insertInto( field( page, 'Text body' ), notify.text );
	await expect(
		frame.locator( '.pegwork-automation-step__summary' )
	).toHaveText(
		`Email to everyone with role Editor, {option:barn_settings.volunteer_manager}: ${ notify.subject }`
	);

	await saveAndCompare( page, def.key );
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await checkBothWidths( page, 'notify', 'automation-role-recipient' );
} );

test( 'Test run of barn_volunteer_review counts the role, never naming members', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	const report = await testRun( page, 'barn_volunteer_review', volunteer );
	await expect( report ).toContainText( 'role editor (1 person)' );
	await expect( report ).not.toContainText( 'e2e-editor@example.org' );
	await focus( sidebar( page ).getByRole( 'button', { name: 'Run test' } ) );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-role-test-run-1280.png' ),
	} );
} );
