/**
 * Automation v4 Editor B (design `docs/automation-v4-design.md` sections 5.2, 5.3 and 5.5), keyboard
 * only for the builds (no mouse calls): barn_volunteer_retention (worked example b: Find records with
 * Created before today minus 2 years, a loop, Move to trash on the item) and barn_gift_receipt_start
 * (Start another automation on barn_gift_receipt) are built from Pegwork > Automations > Add
 * automation, saved, and their canonical JSON must equal the fixtures in
 * tests/fixtures/valid/v4/automations byte for byte, except `enabled`. On a sweep trigger Move to
 * trash leaves the inserter, and one pasted in anyway is refused at its card with focus on the
 * message. Trashing barn_gift_receipt while barn_gift_receipt_start starts it is refused with the
 * in-use message naming the caller. Axe on the Pegwork parts and no horizontal scroll at 1280 and 782
 * px. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-barn.php (the barn types and options page; v4 fixture save;
 * canonical compare; reset) and pegwork-e2e-automation.php (module toggle).
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

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
} );

test( 'build barn_volunteer_retention by keyboard, with Created before and Move to trash; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 300_000 );
	const def = fixture( 'barn_volunteer_retention' );
	const frame = canvas( page );
	await startAutomation( page, def, 'schedule' );
	const panel = sidebar( page );
	await choose( panel.getByRole( 'combobox', { name: 'Every' } ), 'day' );
	const at = panel.getByLabel( 'At (site time, optional)' );
	await focus( at );
	await page.keyboard.type( '0330AM' );
	await expect( at ).toHaveValue( def.trigger.at );
	await insertInto(
		panel.getByRole( 'textbox', { name: 'Run once per (optional)' } ),
		def.idempotency
	);
	for ( const title of [ 'Automation', 'Trigger' ] ) {
		await closePanel( page, title );
	}

	// old: volunteers not placed, created over two years ago, any status but scheduled.
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Find records'
	);
	await setStepId( page, 'find', 'old' );
	await openBlockTab( page );
	await choose(
		panel.getByRole( 'combobox', { name: 'Content type' } ),
		'barn_volunteer'
	);
	await radio( page, 'Matching fields' );
	const row = panel.locator( '.pegwork-automation__condition' ).first();
	await choose( row.getByRole( 'combobox', { name: 'Field' } ), 'stage' );
	await choose( row.getByRole( 'combobox', { name: 'Operator' } ), 'ne' );
	await choose( row.getByRole( 'combobox', { name: 'Value' } ), 'placed' );
	await typeInto( panel.getByRole( 'spinbutton', { name: 'Limit' } ), '50' );
	const more = panel.getByRole( 'button', { name: 'More options' } );
	if ( ( await more.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await press( more );
	}
	for ( const status of [ 'Draft', 'Pending review' ] ) {
		const box = panel.getByRole( 'checkbox', {
			name: status,
			exact: true,
		} );
		await press( box, 'Space' );
		await expect( box ).toBeChecked();
	}
	const before = panel.getByRole( 'group', { name: 'Created before' } );
	await choose(
		before.getByRole( 'combobox', { name: 'Compare with' } ),
		'date'
	);
	await expect(
		before.getByRole( 'combobox', { name: 'From' } )
	).toHaveValue( 'today' );
	await choose(
		before.getByRole( 'combobox', { name: 'Plus or minus' } ),
		'-'
	);
	await typeInto( before.getByRole( 'spinbutton', { name: 'Amount' } ), '2' );
	await choose( before.getByRole( 'combobox', { name: 'Unit' } ), 'years' );
	await checkPegworkA11y( page );
	await page.setViewportSize( { width: 1280, height: 1400 } );
	await focus( before.getByRole( 'combobox', { name: 'Compare with' } ) );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-created-before-1280.png' ),
	} );
	await page.setViewportSize( { width: 1280, height: 900 } );

	// any_old: at least one.
	await insertMain( page, 'Continue only if' );
	await setStepId( page, 'check', 'any_old' );
	await setCondition( page, 'steps.old.count', 'gt' );
	await expect(
		panel.getByRole( 'spinbutton', { name: 'Value', exact: true } )
	).toHaveValue( '0' );

	// each_old with remove: Move to trash on the item.
	await insertMain( page, 'Repeat for each' );
	await setStepId( page, 'each', 'each_old' );
	await openBlockTab( page );
	await choose(
		panel.getByRole( 'combobox', { name: 'For each' } ),
		def.steps[ 2 ].over
	);
	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add step for each item' } ),
		'Move to trash'
	);
	await setStepId( page, 'trash', 'remove' );
	await openBlockTab( page );
	await choose(
		panel.getByRole( 'combobox', { name: 'Content type' } ),
		'barn_volunteer'
	);
	await radio( page, 'Each Volunteer (in each_old)' );
	const card = frame.locator(
		'.pegwork-automation-loop .pegwork-automation-step'
	);
	await expect(
		card.locator( '.pegwork-automation-step__summary' )
	).toHaveText( 'Move Each Volunteer (in each_old) to the trash' );
	await expect( card.locator( '.pegwork-automation-step__note' ) ).toHaveText(
		'Removes records'
	);

	// report: after the loop.
	const report = def.steps[ 3 ];
	await selectCard( page, 'each_old' );
	await insertMain( page, 'Send email' );
	await setStepId( page, 'email', 'report' );
	await openBlockTab( page );
	await insertInto( field( page, 'To' ), report.to );
	await insertInto( field( page, 'Subject' ), report.subject );
	await insertInto( field( page, 'Text body' ), report.text );

	await saveAndCompare( page, def.key );
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await expect( frame.locator( '.pegwork-automation-step' ) ).toHaveCount(
		4
	);
	await checkBothWidths( page, 'remove', 'automation-trash' );
} );

test( 'on a sweep, Move to trash leaves the inserter, and one pasted in is refused with focus on the message', async ( {
	page,
} ) => {
	test.setTimeout( 180_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page
		.context()
		.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await page.goto( saved.barn_volunteer_retention );
	await openEditor( page, /post\.php/ );
	const frame = canvas( page );

	// Copy remove by keyboard.
	const remove = await selectCard( page, 'remove' );
	await remove.focus();
	await page.keyboard.press( 'ControlOrMeta+c' );

	// The trigger becomes a sweep over volunteers: the existing trash step is refused at once.
	await openPanel( page, 'Trigger' );
	const toggle = sidebar( page ).getByRole( 'checkbox', {
		name: 'Once for each record of a type',
	} );
	await press( toggle, 'Space' );
	await expect( toggle ).toBeChecked();
	await expect(
		frame.getByRole( 'alert' ).filter( {
			hasText: 'cannot move records to the trash',
		} )
	).toHaveCount( 1 );

	// The inserter no longer offers it.
	await selectCard( page, 'report' );
	const toggleInserter = page.getByRole( 'button', {
		name: /^(Block Inserter|Toggle block inserter)$/,
	} );
	await press( toggleInserter );
	const box = page.getByRole( 'searchbox', {
		name: /Search for blocks and patterns|Search/,
	} );
	await focus( box );
	await page.keyboard.type( 'Move to trash' );
	await expect(
		page
			.locator(
				'.block-editor-inserter__no-results, .block-editor-inserter__panel-content [role="option"]'
			)
			.first()
	).toBeVisible();
	await expect( blockOption( page, 'Move to trash' ) ).toHaveCount( 0 );
	await press( toggleInserter );

	// Pasted over report: its card says why, and the message has focus.
	await selectCard( page, 'report' );
	await page.keyboard.press( 'ControlOrMeta+v' );
	const refused = frame
		.locator( '.pegwork-automation-step.is-selected' )
		.getByRole( 'alert' )
		.filter( { hasText: 'cannot move records to the trash' } );
	await expect( refused ).toBeVisible();
	await expect( refused ).toBeFocused();
	await expect(
		frame.locator( '.pegwork-automation-step.has-error' )
	).toHaveCount( 2 );
	await checkPegworkA11y( page );
	await page.setViewportSize( { width: 1280, height: 1100 } );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-trash-refused-1280.png' ),
	} );
} );

test( 'build barn_gift_receipt_start by keyboard with Start another automation; it saves identical to the fixture', async ( {
	page,
} ) => {
	test.setTimeout( 240_000 );
	await adminCall( 'POST', '/pegwork-e2e/v1/barn/v4-automation', {
		key: 'barn_gift_receipt',
	} );
	const def = fixture( 'barn_gift_receipt_start' );
	const frame = canvas( page );
	await startAutomation( page, def, 'record' );
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

	await insertFrom(
		page,
		frame.getByRole( 'button', { name: 'Add block' } ),
		'Start another automation'
	);
	await setStepId( page, 'start', 'receipt' );
	await openBlockTab( page );
	const automation = sidebar( page ).getByRole( 'combobox', {
		name: 'Automation',
		exact: true,
	} );
	await expect(
		automation.locator( 'option', {
			hasText: 'Send the receipt for a gift: on each Gift',
		} )
	).toHaveCount( 1 );
	await choose( automation, 'barn_gift_receipt' );
	await expect( field( page, 'For record' ) ).toHaveValue( '' );
	await expect( field( page, 'For record' ) ).toHaveAccessibleDescription(
		/Empty: this Gift\./
	);
	await expect(
		frame.locator( '.pegwork-automation-step__summary' )
	).toHaveText(
		'Start "Send the receipt for a gift" for this Gift (runs separately)'
	);

	// skipped: the receipt did not start.
	await insertMain( page, 'Continue only if' );
	await setStepId( page, 'check', 'skipped' );
	await setCondition( page, 'steps.receipt.started', 'eq' );
	await choose(
		sidebar( page ).getByRole( 'combobox', { name: 'Value', exact: true } ),
		'false'
	);

	const tell = def.steps[ 2 ];
	await insertMain( page, 'Send email' );
	await setStepId( page, 'email', 'tell' );
	await openBlockTab( page );
	await insertInto( field( page, 'To' ), tell.to );
	await insertInto( field( page, 'Subject' ), tell.subject );
	await insertInto( field( page, 'Text body' ), tell.text );

	await saveAndCompare( page, def.key );
	await page.reload();
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await checkBothWidths( page, 'receipt', 'automation-start' );
} );

test( 'trashing an automation another one starts is refused, naming the caller', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await press(
		page.getByRole( 'link', { name: 'Edit Send the receipt for a gift' } )
	);
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await page.evaluate( () =>
		window.wp.data.dispatch( 'core/editor' ).trashPost()
	);
	const notice = page
		.locator( '.components-snackbar, .components-notice' )
		.filter( { hasText: 'is started by barn_gift_receipt_start' } );
	await expect( notice.first() ).toBeVisible();
	await expect( page ).toHaveURL( /post\.php\?post=\d+&action=edit/ );
	const compared = await adminCall(
		'GET',
		'/pegwork-e2e/v1/barn/compare',
		undefined,
		{ key: 'barn_gift_receipt' }
	);
	expect( compared.source ).toBe( 'db' );
	await checkPegworkA11y( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-in-use-trash-1280.png' ),
	} );
} );
