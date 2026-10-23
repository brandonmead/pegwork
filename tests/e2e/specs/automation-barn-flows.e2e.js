/**
 * The BarnCRM example flows (Phase 6 PR 6, design "Worked examples: BarnCRM flows") built in the
 * automation editor by keyboard only (no mouse calls): from Pegwork > Automations > Add automation,
 * each of barn_gift_thanks, barn_volunteer_intake and barn_gift_followup is built control by
 * control, saved, and read back through the admin e2e route; its canonical JSON must equal the
 * fixture in tests/fixtures/valid/barn/automations byte for byte, except `enabled` (a new site
 * automation is saved disabled). The texts typed come from the fixture; the structure, ids,
 * conditions and values are entered through the editor's controls. Test run on the saved
 * barn_gift_thanks with gifts of 300 and 40 shows the major branch and Otherwise. Axe on the Pegwork
 * parts and no horizontal scroll at 1280 and 782 px. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-barn.php (package e2e_barn: the fixture's types, form and
 * options page; sample gifts; canonical compare; reset) and pegwork-e2e-automation.php (module
 * toggle).
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
	renameBranch,
	saveByKeyboard,
	selectCard,
	setCondition,
	sidebar,
	typeInto,
} = require( '../utils/builder' );

const FIXTURES = path.join(
	__dirname,
	'..',
	'..',
	'fixtures',
	'valid',
	'barn',
	'automations'
);
const fixture = ( key ) => require( path.join( FIXTURES, `${ key }.json` ) );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';

let admin;
const adminCall = adminCaller( () => admin );
const gifts = {};
let thanksUrl;

// A step of a fixture by id, anywhere in the tree.
function stepOf( definition, id ) {
	const walk = ( steps ) => {
		for ( const step of steps || [] ) {
			if ( step.id === id ) {
				return step;
			}
			const found =
				walk( step.otherwise ) ||
				( step.branches || [] )
					.map( ( branch ) => walk( branch.steps ) )
					.find( Boolean );
			if ( found ) {
				return found;
			}
		}
		return null;
	};
	return walk( definition.steps );
}

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

// Fills the selected email step from the fixture's step.
async function fillEmail( page, step ) {
	await openBlockTab( page );
	await insertInto( field( page, 'To' ), step.to );
	if ( step.reply_to ) {
		await insertInto( field( page, 'Reply-To (optional)' ), step.reply_to );
	}
	await insertInto( field( page, 'Subject' ), step.subject );
	await insertInto( field( page, 'Text body' ), step.text );
}

// Adds one values row per entry: on a known type the row's field is picked from the list, otherwise
// its key is typed; then its value is entered.
async function fillValues( page, values, labels = null ) {
	await openBlockTab( page );
	const panel = sidebar( page );
	let row = 0;
	for ( const [ key, value ] of Object.entries( values ) ) {
		row++;
		await press( panel.getByRole( 'button', { name: 'Add value' } ) );
		if ( labels ) {
			await choose(
				panel.getByRole( 'combobox', { name: `Field ${ row }` } ),
				key
			);
		} else {
			await typeInto(
				panel.getByRole( 'textbox', { name: `Field key ${ row }` } ),
				key
			);
		}
		await insertInto(
			field( page, `Value of ${ labels ? labels[ key ] : key }` ),
			String( value )
		);
	}
}

// The condition value of the selected branch or step: a number, an option, or another field.
async function setValue( page, kind, value ) {
	const panel = sidebar( page );
	if ( kind === 'number' ) {
		await typeInto(
			panel.getByRole( 'spinbutton', { name: 'Value', exact: true } ),
			String( value )
		);
	} else if ( kind === 'option' ) {
		await choose(
			panel.getByRole( 'combobox', { name: 'Value', exact: true } ),
			value
		);
	} else {
		await choose(
			panel.getByRole( 'combobox', { name: 'Compare with' } ),
			'var'
		);
		await choose(
			panel.getByRole( 'combobox', { name: 'Other field' } ),
			value
		);
	}
}

// A wait step's amount in days.
async function setWaitDays( page, days ) {
	await openBlockTab( page );
	await typeInto(
		sidebar( page ).getByRole( 'spinbutton', { name: 'Amount' } ),
		String( days )
	);
}

// Starts a new automation from the list and sets its title and key.
async function startAutomation( page, definition ) {
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
	expect( compared.enabled ).toBe( false );
	expect( compared.saved ).toBe( compared.fixture );
	return page.url();
}

// A screenshot of the finished flow at 1280 px, tall enough to show the whole canvas.
async function shot( page, name, height ) {
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
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	for ( const amount of [ 300, 40 ] ) {
		gifts[ amount ] = await adminCall(
			'POST',
			'/pegwork-e2e/v1/barn/gift',
			{ amount }
		);
	}
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'build barn_gift_thanks by keyboard; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const def = fixture( 'barn_gift_thanks' );
	const frame = canvas( page );
	await startAutomation( page, def );

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

	// The main list: the decision, then "Continue only if" and Update record after it.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'If / Otherwise'
	);
	await setStepId( page, 'decision', 'size' );
	await insertMain( page, 'Continue only if' );
	await setStepId( page, 'check', 'has_donor' );
	await setCondition( page, 'record.donor_id', 'not_empty' );
	await insertMain( page, 'Update record' );
	await setStepId( page, 'update', 'stamp_donor' );
	const stamp = stepOf( def, 'stamp_donor' );
	await insertInto( field( page, 'Record (optional)' ), stamp.record );
	await fillValues( page, stamp.values );

	// The major branch: its condition, the email and the call task.
	const major = def.steps[ 0 ].branches[ 0 ];
	await renameBranch( page, 'Path 1', major.label );
	await setCondition( page, major.when.var, major.when.op );
	await setValue( page, 'number', major.when.value );
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: `Add step to ${ major.label }` } ),
		'Send email'
	);
	await setStepId( page, 'email', 'thank_major' );
	await fillEmail( page, stepOf( def, 'thank_major' ) );
	await insertMain( page, 'Create record' );
	await setStepId( page, 'create', 'call_task' );
	const task = stepOf( def, 'call_task' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Content type' } ),
		task.content_type
	);
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Status' } ),
		task.status
	);
	await fillValues( page, task.values, {
		name: 'Name',
		due_on: 'Due on',
		assignee_email: 'Assignee email',
		related_gift: 'Related gift',
	} );

	// Otherwise: the plain thank-you.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add step to Otherwise' } ),
		'Send email'
	);
	await setStepId( page, 'email', 'thank' );
	await fillEmail( page, stepOf( def, 'thank' ) );

	await expect(
		frame.locator( '.pegwork-automation-branch__heading' )
	).toHaveText( [ 'If Amount is at least 250', 'Otherwise' ] );

	thanksUrl = await saveAndCompare( page, def.key );

	// Reloaded, the finished flow: axe and no horizontal scroll at 1280 and 782 px.
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await expect( frame.locator( '.pegwork-automation-step' ) ).toHaveCount(
		5
	);
	await checkPegworkA11y( page );
	await shot( page, 'automation-barn-gift-thanks-1280.png', 1100 );
	await selectCard( page, 'call_task' );
	await openBlockTab( page );
	await checkPegworkA11y( page );
	// At 782 px the editor closes the sidebar: the canvas alone, then with the inspector reopened.
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-barn-gift-thanks-782.png' ),
	} );
	const settings = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );
	if ( ( await settings.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await press( settings );
	}
	await selectCard( page, 'call_task' );
	await openBlockTab( page );
	await expect(
		sidebar( page ).getByRole( 'combobox', { name: 'Field 4' } )
	).toBeVisible();
	await checkPegworkA11y( page );
} );

test( 'Test run of barn_gift_thanks takes the major branch for 300 and Otherwise for 40', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( thanksUrl );
	await openEditor( page, /post\.php/ );
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}
	await openPanel( page, 'Test run' );
	const panel = sidebar( page );
	const sample = panel.getByRole( 'combobox', { name: 'Sample record' } );
	const report = panel.locator( '.pegwork-automation__report' );

	for ( const [ amount, heading ] of [
		[ 300, 'If Major gift (250 or more)' ],
		[ 40, 'Otherwise' ],
	] ) {
		const gift = gifts[ amount ];
		await typeInto( sample, gift.title );
		const option = panel.getByRole( 'option', {
			name: `${ gift.title } (#${ gift.id })`,
		} );
		await expect( option ).toBeVisible();
		await page.keyboard.press( 'ArrowDown' );
		await expect( option ).toHaveAttribute( 'aria-selected', 'true' );
		await page.keyboard.press( 'Enter' );
		await expect( sample ).toHaveValue( `${ gift.title } (#${ gift.id })` );

		await press( panel.getByRole( 'button', { name: 'Run test' } ) );
		const result = panel.getByRole( 'heading', {
			name: 'Test run result',
		} );
		await expect( result ).toBeFocused();
		await expect(
			report.locator( '.pegwork-automation__report-heading' )
		).toHaveText( [ heading ] );
		// The sample has no donor, so the run stops at has_donor either way.
		await expect( report ).toContainText( 'has_donor' );
		if ( amount === 300 ) {
			await expect( report ).toContainText( 'call_task' );
			await checkPegworkA11y( page );
			await page.screenshot( {
				path: path.join( SHOTS, 'automation-barn-test-run-1280.png' ),
			} );
		} else {
			await expect( report ).not.toContainText( 'call_task' );
		}
		await focus( sample );
	}
} );

test( 'build barn_volunteer_intake by keyboard; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const def = fixture( 'barn_volunteer_intake' );
	const frame = canvas( page );
	await startAutomation( page, def );

	await openPanel( page, 'Trigger' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Starts on' } ),
		'form'
	);
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Form' } ),
		def.trigger.form
	);
	await expect(
		sidebar( page ).getByRole( 'combobox', { name: 'When an entry is' } )
	).toHaveValue( def.trigger.event );

	// The main list: route, notify, grace, check.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Choose a path'
	);
	await setStepId( page, 'decision', 'route' );
	await insertMain( page, 'Send email' );
	await setStepId( page, 'email', 'notify' );
	await fillEmail( page, stepOf( def, 'notify' ) );
	await insertMain( page, 'Wait' );
	await setStepId( page, 'wait', 'grace' );
	await setWaitDays( page, 2 );
	await insertMain( page, 'If / Otherwise' );
	await setStepId( page, 'decision', 'check' );

	// route: Events, Garden, Otherwise, each writing the coordinator.
	const route = def.steps[ 0 ];
	const coordinator = { coordinator_email: 'Coordinator email' };
	for ( const [ index, branch ] of route.branches.entries() ) {
		await renameBranch( page, `Path ${ index + 1 }`, branch.label );
		await setCondition( page, branch.when.var, branch.when.op );
		await setValue( page, 'option', branch.when.value );
		const step = branch.steps[ 0 ];
		await insertFrom(
			page,
			frame.getByRole( 'button', {
				name: `Add step to ${ branch.label }`,
			} ),
			'Update record'
		);
		await setStepId( page, 'update', step.id );
		await fillValues( page, step.values, coordinator );
	}
	// The first "Add step to Otherwise" is route's (check's comes after it).
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add step to Otherwise' } ).first(),
		'Update record'
	);
	await setStepId( page, 'update', 'to_manager' );
	await fillValues( page, route.otherwise[ 0 ].values, coordinator );

	// check: Not contacted yet, with remind, grace_2, still_new and escalate; no Otherwise steps.
	const pending = def.steps[ 3 ].branches[ 0 ];
	await renameBranch( page, 'Path 1', pending.label );
	await setCondition( page, pending.when.var, pending.when.op );
	await setValue( page, 'option', pending.when.value );
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: `Add step to ${ pending.label }` } ),
		'Send email'
	);
	await setStepId( page, 'email', 'remind' );
	await fillEmail( page, stepOf( def, 'remind' ) );
	await insertMain( page, 'Wait' );
	await setStepId( page, 'wait', 'grace_2' );
	await setWaitDays( page, 3 );
	await insertMain( page, 'Continue only if' );
	// "check" is the decision's id, so the new condition step took check_2.
	await setStepId( page, 'check_2', 'still_new' );
	const stillNew = stepOf( def, 'still_new' );
	await setCondition( page, stillNew.when.var, stillNew.when.op );
	await setValue( page, 'option', stillNew.when.value );
	await insertMain( page, 'Send email' );
	await setStepId( page, 'email', 'escalate' );
	await fillEmail( page, stepOf( def, 'escalate' ) );

	await expect(
		frame.locator( '.pegwork-automation-branch__heading' )
	).toHaveText( [
		'If Interest is Events',
		'If Interest is Garden',
		'Otherwise',
		'If Stage is New',
		'Otherwise',
	] );

	await saveAndCompare( page, def.key );
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await checkPegworkA11y( page );
	await shot( page, 'automation-barn-volunteer-intake-1280.png', 1750 );
} );

test( 'build barn_gift_followup by keyboard; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const def = fixture( 'barn_gift_followup' );
	const frame = canvas( page );
	await startAutomation( page, def );

	// Record updated on contacts, only when the last gift date changed, once per contact and date.
	await openPanel( page, 'Trigger' );
	const panel = sidebar( page );
	await choose(
		panel.getByRole( 'combobox', { name: 'Starts on' } ),
		'record'
	);
	await choose(
		panel.getByRole( 'combobox', { name: 'Content type' } ),
		def.trigger.content_type
	);
	await choose(
		panel.getByRole( 'combobox', { name: 'When a record is' } ),
		def.trigger.event
	);
	await setCondition( page, def.trigger.when.var, def.trigger.when.op );
	await insertInto(
		panel.getByRole( 'textbox', { name: 'Run once per (optional)' } ),
		def.idempotency
	);

	// first_gift (If, no Otherwise steps), only_gift, grace, no_second_gift, follow_up.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'If / Otherwise'
	);
	await setStepId( page, 'decision', 'first_gift' );
	for ( const id of [
		'only_gift',
		'grace',
		'no_second_gift',
		'follow_up',
	] ) {
		const step = stepOf( def, id );
		if ( step.type === 'condition' ) {
			await insertMain( page, 'Continue only if' );
			await setStepId( page, 'check', id );
			await setCondition( page, step.when.var, step.when.op );
			await setValue( page, 'var', step.when.value.var );
		} else if ( step.type === 'wait' ) {
			await insertMain( page, 'Wait' );
			await setStepId( page, 'wait', id );
			await setWaitDays( page, 30 );
		} else {
			await insertMain( page, 'Send email' );
			await setStepId( page, 'email', id );
			await fillEmail( page, step );
		}
	}

	const first = def.steps[ 0 ].branches[ 0 ];
	await renameBranch( page, 'Path 1', first.label );
	await setCondition( page, first.when.var, first.when.op );
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: `Add step to ${ first.label }` } ),
		'Update record'
	);
	await setStepId( page, 'update', 'stamp_first' );
	await fillValues( page, first.steps[ 0 ].values, {
		first_gift_on: 'First gift on',
	} );

	await saveAndCompare( page, def.key );
} );
