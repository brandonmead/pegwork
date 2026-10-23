/**
 * The read and link steps in the automation editor (Automation v3 PR 6), by keyboard only (no mouse
 * calls): barn_lifetime_milestone (design worked example b) is built control by control from
 * Pegwork > Automations > Add automation, saved, and read back through the admin e2e route; its
 * canonical JSON must equal tests/fixtures/valid/reads/automations/barn_lifetime_milestone.json byte
 * for byte, except `enabled`. On the way: the variable picker groups read outputs ("Totals (from
 * giving)") and writes `| number:2`. Test run on a gift with a linked donor shows the read lines.
 * Then a new automation shows the "Started by staff" trigger picker, a Link records inspector and
 * the "If this step fails after retries" control. Axe on the Pegwork parts and no horizontal scroll
 * at 1280 and 782 px. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-barn.php (switched to package e2e_barn_reads: the reads
 * fixture's types, relationships, fund and options page; sample gift with donor; canonical compare;
 * reset) and pegwork-e2e-automation.php (module toggle).
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
	saveByKeyboard,
	selectCard,
	setCondition,
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
		'barn_lifetime_milestone.json'
	)
);

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';

let admin;
const adminCall = adminCaller( () => admin );
let gift;
let savedUrl;

const step = ( id ) => def.steps.find( ( item ) => item.id === id );

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

// Picks a radio of the selected block's inspector by keyboard.
async function radio( page, name ) {
	const option = sidebar( page ).getByRole( 'radio', { name, exact: true } );
	await press( option, 'Space' );
	await expect( option ).toBeChecked();
}

// Picks a relationship in the combobox by typing its label.
async function relationship( page, label ) {
	const box = sidebar( page ).getByRole( 'combobox', {
		name: 'Relationship',
	} );
	await typeInto( box, label );
	const option = sidebar( page ).getByRole( 'option', {
		name: new RegExp( `^${ label } \\(` ),
	} );
	await expect( option ).toBeVisible();
	await page.keyboard.press( 'ArrowDown' );
	await page.keyboard.press( 'Enter' );
	await expect( box ).toHaveValue( new RegExp( `^${ label } \\(` ) );
}

// Sets the selected read step's content type and finds it through gift_donor of `of`.
async function readByDonor( page, contentType, of ) {
	await openBlockTab( page );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Content type' } ),
		contentType
	);
	await radio( page, 'A relationship' );
	await relationship( page, 'Donor' );
	await insertInto( field( page, 'Of' ), of );
}

// A screenshot at 1280 px, tall enough to show the inspector.
async function shot( page, name, height = 1100 ) {
	await page.setViewportSize( { width: 1280, height } );
	await page.screenshot( { path: path.join( SHOTS, name ) } );
	await page.setViewportSize( { width: 1280, height: 900 } );
}

// At 782 px (the editor closes the sidebar): axe on the canvas, then with the inspector reopened.
async function narrow( page, id, name ) {
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkPegworkA11y( page );
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
	gift = await adminCall( 'POST', '/pegwork-e2e/v1/barn/gift', {
		amount: 1200,
	} );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reads', { reads: false } );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'build barn_lifetime_milestone by keyboard; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const frame = canvas( page );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press( page.getByRole( 'link', { name: 'Add automation' } ) );
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	await typeInto(
		canvas( page ).getByRole( 'textbox', { name: 'Add title' } ),
		def.label
	);
	await openPanel( page, 'Automation' );
	await typeInto(
		sidebar( page ).getByRole( 'textbox', { name: 'Key' } ),
		def.key
	);

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
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}

	// donor: the gift's Donor contact.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Get a record'
	);
	await setStepId( page, 'get', 'donor' );
	await readByDonor( page, 'barn_contact', step( 'donor' ).related.of );
	await expect(
		frame.locator( '.pegwork-automation-step__summary' ).first()
	).toContainText( 'Contact' );
	await checkPegworkA11y( page );
	await shot( page, 'automation-read-get-1280.png' );

	// has_donor and not_yet: typed read outputs in the field list.
	await insertMain( page, 'Continue only if' );
	await setStepId( page, 'check', 'has_donor' );
	await setCondition( page, 'steps.donor.found', 'eq' );
	await expect(
		sidebar( page ).getByRole( 'combobox', { name: 'Value', exact: true } )
	).toHaveValue( 'true' );
	await insertMain( page, 'Continue only if' );
	await setStepId( page, 'check', 'not_yet' );
	await setCondition( page, 'steps.donor.record.milestone_1000_on', 'empty' );

	// giving: every gift of that donor, none loaded, Amount totalled.
	await insertMain( page, 'Find records' );
	await setStepId( page, 'find', 'giving' );
	await readByDonor( page, 'barn_gift', step( 'giving' ).related.of );
	await typeInto(
		sidebar( page ).getByRole( 'spinbutton', { name: 'Limit' } ),
		'0'
	);
	const amount = sidebar( page ).getByRole( 'checkbox', {
		name: 'Amount',
		exact: true,
	} );
	await press( amount, 'Space' );
	await expect( amount ).toBeChecked();
	await checkPegworkA11y( page );
	await shot( page, 'automation-read-find-1280.png' );

	// reached: the total is a number.
	await insertMain( page, 'Continue only if' );
	await setStepId( page, 'check', 'reached' );
	await setCondition( page, 'steps.giving.sum.amount', 'gte' );
	await typeInto(
		sidebar( page ).getByRole( 'spinbutton', {
			name: 'Value',
			exact: true,
		} ),
		'1000'
	);

	// stamp: the donor's milestone date, picked from the contact's fields.
	await insertMain( page, 'Update record' );
	await setStepId( page, 'update', 'stamp' );
	const stamp = step( 'stamp' );
	await openBlockTab( page );
	await insertInto( field( page, 'Record (optional)' ), stamp.record );
	await press( sidebar( page ).getByRole( 'button', { name: 'Add value' } ) );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Field 1' } ),
		'milestone_1000_on'
	);
	await insertInto(
		field( page, 'Value of Lifetime 1,000 reached on' ),
		stamp.values.milestone_1000_on
	);

	// celebrate: the picker groups read outputs and formats the total.
	await insertMain( page, 'Send email' );
	await setStepId( page, 'email', 'celebrate' );
	const celebrate = step( 'celebrate' );
	await openBlockTab( page );
	await press(
		sidebar( page ).getByRole( 'button', {
			name: 'Insert merge tag into Text body',
		} )
	);
	const tag = page.getByRole( 'combobox', { name: 'Merge tag' } );
	await expect( tag ).toBeVisible();
	const groups = await tag
		.locator( 'optgroup' )
		.evaluateAll( ( list ) => list.map( ( item ) => item.label ) );
	expect( groups ).toEqual(
		expect.arrayContaining( [
			'This Gift',
			'Donor (from donor)',
			'Totals (from giving)',
		] )
	);
	await choose( tag, 'steps.giving.sum.amount' );
	await choose(
		page.getByRole( 'combobox', { name: 'Format the number' } ),
		'2'
	);
	await press( page.getByRole( 'button', { name: 'Insert', exact: true } ) );
	await expect( field( page, 'Text body' ) ).toHaveValue(
		/\{\{steps\.giving\.sum\.amount \| number:2\}\}/
	);
	await insertInto( field( page, 'To' ), celebrate.to );
	await insertInto(
		field( page, 'Reply-To (optional)' ),
		celebrate.reply_to
	);
	await insertInto( field( page, 'Subject' ), celebrate.subject );
	await insertInto( field( page, 'Text body' ), celebrate.text );

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

	// Reloaded: the cards, axe at 1280 and 782 px.
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await expect( frame.locator( '.pegwork-automation-step' ) ).toHaveCount(
		7
	);
	await checkPegworkA11y( page );
	await shot( page, 'automation-barn-lifetime-milestone-1280.png', 1500 );
	await narrow( page, 'giving', 'automation-read-find-782.png' );
} );

test( 'Test run of barn_lifetime_milestone shows the read lines', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( savedUrl );
	await openEditor( page, /post\.php/ );
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}
	await openPanel( page, 'Test run' );
	const panel = sidebar( page );
	const sample = panel.getByRole( 'combobox', { name: 'Sample record' } );
	await typeInto( sample, gift.title );
	const option = panel.getByRole( 'option', {
		name: `${ gift.title } (#${ gift.id })`,
	} );
	await expect( option ).toBeVisible();
	await page.keyboard.press( 'ArrowDown' );
	await page.keyboard.press( 'Enter' );
	await expect( sample ).toHaveValue( `${ gift.title } (#${ gift.id })` );
	await press( panel.getByRole( 'button', { name: 'Run test' } ) );
	await expect(
		panel.getByRole( 'heading', { name: 'Test run result' } )
	).toBeFocused();
	const report = panel.locator( '.pegwork-automation__report' );
	await expect( report ).toContainText( 'Get record donor: found Contact' );
	await expect( report ).toContainText( 'Find records giving: 1 Gift' );
	await expect( report ).toContainText( 'celebrate' );
	await checkPegworkA11y( page );
	await shot( page, 'automation-read-test-run-1280.png' );
} );

test( 'Started by staff, Link records and If this step fails, by keyboard', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	const frame = canvas( page );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press( page.getByRole( 'link', { name: 'Add automation' } ) );
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	await closePanel( page, 'Automation' );

	// The trigger picker: Started by staff on a Gift, run by its editors.
	await openPanel( page, 'Trigger' );
	const panel = sidebar( page );
	await choose(
		panel.getByRole( 'combobox', { name: 'Starts on' } ),
		'manual'
	);
	await choose(
		panel.getByRole( 'combobox', { name: 'Content type' } ),
		'barn_gift'
	);
	await typeInto(
		panel.getByRole( 'textbox', { name: 'Button text' } ),
		'Link a follow-up task'
	);
	const editors = panel.getByRole( 'radio', {
		name: 'Anyone who can edit the record',
	} );
	await press( editors, 'Space' );
	await expect( editors ).toBeChecked();
	await checkPegworkA11y( page );
	await shot( page, 'automation-trigger-manual-1280.png' );
	await closePanel( page, 'Trigger' );

	// Link records: Task to this Gift, continuing if it fails.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Link records'
	);
	await selectCard( page, 'link' );
	await openBlockTab( page );
	await relationship( page, 'Gift' );
	await expect( field( page, 'From (a Task ID)' ) ).toBeVisible();
	await insertInto( field( page, 'To (a Gift ID)' ), '{{record.id}}' );
	await openAdvanced( page );
	const go = sidebar( page ).getByRole( 'radio', {
		name: 'Continue',
		exact: true,
	} );
	await press( go, 'Space' );
	await expect( go ).toBeChecked();
	await expect(
		frame.locator( '.pegwork-automation-step__note' )
	).toHaveText( 'Continues if it fails' );
	await focus( field( page, 'Step ID' ) );
	await checkPegworkA11y( page );
	await shot( page, 'automation-relate-1280.png' );
	await narrow( page, 'link', 'automation-relate-782.png' );
} );
