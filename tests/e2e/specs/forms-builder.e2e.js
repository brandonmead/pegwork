/**
 * The form builder (E2E case 7): build a two-step contact form in the block editor by keyboard and
 * drag-and-drop, see an invalid save refused with the field message, save, place the form on a page
 * and submit it; the Forms list by keyboard; editors kept out; axe on the Forms list (1280 and 782 px,
 * no horizontal scroll) and on the editor's settings panel. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php (module toggle, reset, entries routes).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const FORMS = '/wp-admin/admin.php?page=pegwork-forms';
const KEY = 'e2e_built';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };

let admin;
const created = { posts: [], pages: [], users: [] };

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

// Moves focus to a control and activates it with the keyboard.
async function press( locator, key = 'Enter' ) {
	await locator.focus();
	await expect( locator ).toBeFocused();
	await locator.page().keyboard.press( key );
}

// The editor canvas: an iframe when every block is API version 3, else the page itself.
function canvas( page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

// Waits for the block editor that a link just opened (the URL matches `pattern`).
async function openEditor( page, pattern ) {
	await page.waitForURL( pattern );
	await page.waitForFunction( () =>
		window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
	);
	await page.evaluate( () => {
		const prefs = window.wp.data.dispatch( 'core/preferences' );
		prefs.set( 'core/edit-post', 'welcomeGuide', false );
		prefs.set( 'core', 'editorMode', 'visual' );
		// Persisted per user and expected by other specs: keep the pre-publish panel on.
		window.wp.data.dispatch( 'core/editor' ).enablePublishSidebar();
	} );
}

// Opens a document settings panel by its title (Post tab of the sidebar).
async function openPanel( page, title ) {
	const toggle = page
		.getByRole( 'region', { name: 'Editor settings' } )
		.getByRole( 'button', { name: title, exact: true } );
	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await press( toggle );
	}
	return toggle;
}

async function openSettingsSidebar( page ) {
	const settings = page.getByRole( 'button', {
		name: 'Settings',
		exact: true,
	} );
	if ( ( await settings.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await press( settings );
	}
	const postTab = page
		.getByRole( 'region', { name: 'Editor settings' } )
		.getByRole( 'tab' )
		.first();
	await press( postTab );
}

async function save( page ) {
	const button = page
		.getByRole( 'region', { name: 'Editor top bar' } )
		.getByRole( 'button', { name: /^(Publish|Save|Update)$/ } )
		.first();
	// The builder relabels core's Publish as Save, so a new form is told apart by its status.
	const isNew = await page.evaluate(
		() => ! window.wp.data.select( 'core/editor' ).isCurrentPostPublished()
	);
	await press( button );
	const confirm = page
		.locator( '.editor-post-publish-panel' )
		.getByRole( 'button', { name: /^(Publish|Save)$/ } );
	// The first save of a new form asks for confirmation in the pre-publish panel.
	if ( isNew && ( await confirm.isVisible() ) === false ) {
		await confirm.waitFor( { timeout: 3000 } ).catch( () => {} );
	}
	if ( await confirm.isVisible() ) {
		await press( confirm );
	}
}

// A block in the inserter results (its accessible name starts with the icon).
function blockOption( page, name ) {
	return page
		.getByRole( 'option', { name: new RegExp( `^[^A-Za-z]*${ name }$` ) } )
		.first();
}

function inserterToggle( page ) {
	return page.getByRole( 'button', {
		name: /^(Block Inserter|Toggle block inserter)$/,
	} );
}

// Opens the main inserter by keyboard and types a search.
async function searchInserter( page, search ) {
	await press( inserterToggle( page ) );
	const box = page.getByRole( 'searchbox', {
		name: /Search for blocks and patterns|Search/,
	} );
	// Focus lands on the search box (WordPress 6.7) or the Blocks tab before it (later versions).
	const focused = () =>
		box.evaluate( ( el ) => el === el.ownerDocument.activeElement );
	for ( let i = 0; i < 3; i++ ) {
		if ( await focused() ) {
			break;
		}
		await page.keyboard.press( 'Tab' );
	}
	// A reopened inserter keeps focus on its last result; move it back to the search box.
	if ( ! ( await focused() ) ) {
		await box.focus();
	}
	await expect( box ).toBeFocused();
	await page.keyboard.press( 'ControlOrMeta+a' );
	await page.keyboard.type( search );
	await expect( blockOption( page, search ) ).toBeVisible();
}

async function closeInserter( page ) {
	const toggle = inserterToggle( page );
	if ( ( await toggle.getAttribute( 'aria-pressed' ) ) === 'true' ) {
		await press( toggle );
	}
}

// Drags with the mouse from a source to a point in a target (the target's bottom area by default,
// below any block toolbar). Pointer events, as a person would, across the canvas iframe.
async function drag( page, source, target, fraction = 0.8 ) {
	const from = await source.boundingBox();
	const to = await target.boundingBox();
	await page.mouse.move( from.x + from.width / 2, from.y + from.height / 2 );
	await page.mouse.down();
	await page.mouse.move(
		from.x + from.width / 2 + 20,
		from.y + from.height / 2,
		{ steps: 5 }
	);
	const x = to.x + Math.min( 60, to.width / 2 );
	const y = to.y + to.height * fraction;
	await page.mouse.move( x, y, { steps: 20 } );
	// The editor throttles dragover (about 200 ms); hover a moment so it places the drop indicator.
	for ( let i = 1; i <= 3; i++ ) {
		await page.waitForTimeout( 150 );
		await page.mouse.move( x, y + i, { steps: 2 } );
	}
	await page.mouse.up();
}

// Drags until `fields` has `count` blocks: a slow runner can drop before the drop zone is ready.
async function dragUntil( page, source, target, fraction, fields, count ) {
	await expect( async () => {
		if ( ( await fields.count() ) < count ) {
			await drag( page, source, target, fraction );
		}
		await expect( fields ).toHaveCount( count, { timeout: 3000 } );
	} ).toPass( { timeout: 30_000 } );
}

// Inserts a block from the main inserter: searched and chosen by keyboard.
async function insertByKeyboard( page, search ) {
	await searchInserter( page, search );
	await press( blockOption( page, search ) );
	await closeInserter( page );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
} );

test.afterAll( async () => {
	for ( const id of created.pages ) {
		await adminCall( 'DELETE', `/wp/v2/pages/${ id }`, undefined, {
			force: true,
		} ).catch( () => {} );
	}
	for ( const id of created.posts ) {
		await adminCall( 'DELETE', `/wp/v2/pegwork-forms/${ id }`, undefined, {
			force: true,
		} ).catch( () => {} );
	}
	for ( const id of created.users ) {
		await adminCall( 'DELETE', `/wp/v2/users/${ id }`, undefined, {
			force: true,
			reassign: 1,
		} ).catch( () => {} );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' ).catch( () => {} );
	await admin.dispose();
} );

test( 'build a two-step form by keyboard and drag and drop, save it, place it and submit it', async ( {
	page,
} ) => {
	test.setTimeout( 120_000 );
	await page.setViewportSize( { width: 1280, height: 900 } );

	// The Forms list leads to a new form.
	await page.goto( FORMS );
	await press( page.getByRole( 'link', { name: 'Add form' } ) );
	await openEditor( page, /post-new\.php\?post_type=pegwork_form/ );
	const postId = await page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).getCurrentPostId()
	);
	created.posts.push( postId );

	const frame = canvas( page );
	await frame
		.getByRole( 'textbox', { name: 'Add title' } )
		.fill( 'E2E built contact' );

	// Step 1 by keyboard from the main inserter, its title typed.
	await insertByKeyboard( page, 'Form step' );
	await frame.getByLabel( 'Step title' ).first().fill( 'About you' );

	// A Text field into step 1 by keyboard: the step's own appender, search, Enter.
	await press( frame.getByRole( 'button', { name: 'Add block' } ).first() );
	// The quick inserter opens in a popover and moves focus to its search box.
	await expect(
		page
			.locator( '.block-editor-inserter__quick-inserter' )
			.getByRole( 'searchbox' )
	).toBeFocused();
	await page.keyboard.type( 'Text' );
	await press( blockOption( page, 'Text' ) );
	await frame.getByLabel( 'Field label' ).first().fill( 'Your name' );

	// Step 2 after it, then an Email field dragged from the inserter into step 1 and a Paragraph
	// text field dragged into step 2.
	await frame.getByLabel( 'Step title' ).first().click();
	await insertByKeyboard( page, 'Form step' );
	await frame.getByLabel( 'Step title' ).nth( 1 ).fill( 'Your message' );

	const steps = frame.locator( '.pegwork-builder-step' );
	await searchInserter( page, 'Email' );
	const firstFields = steps.first().locator( '.pegwork-builder-field' );
	await dragUntil(
		page,
		blockOption( page, 'Email' ),
		firstFields.first(),
		0.8,
		firstFields,
		2
	);
	await page.getByRole( 'searchbox' ).fill( 'Paragraph text' );
	await dragUntil(
		page,
		blockOption( page, 'Paragraph text' ),
		steps.nth( 1 ).locator( '.pegwork-builder-step__fields' ),
		0.5,
		steps.nth( 1 ).locator( '.pegwork-builder-field' ),
		1
	);
	await closeInserter( page );

	// Each field block is titled by its type (the variation).
	const label = ( type ) =>
		frame
			.locator( `.pegwork-builder-field[data-title="${ type }"]` )
			.getByLabel( 'Field label' );
	await expect( frame.getByLabel( 'Field label' ) ).toHaveCount( 3 );
	await expect( label( 'Text' ) ).toHaveValue( 'Your name' );
	await label( 'Email' ).fill( 'Email' );
	await label( 'Paragraph text' ).fill( 'Email' );

	// Settings: the key, typed by keyboard.
	await openSettingsSidebar( page );
	await openPanel( page, 'Form settings' );
	await page.getByLabel( 'Form key' ).fill( KEY );

	// Invalid: two fields named Email share the key "email". The save is refused and the message
	// shows in core's notice and under the field.
	await save( page );
	await expect(
		page.locator( '.components-notice__content' ).filter( {
			hasText: /appears more than once/,
		} )
	).toBeVisible();
	await expect(
		frame
			.locator( '.pegwork-builder-field .pegwork-builder__error' )
			.filter( { hasText: 'appears more than once' } )
	).toBeVisible();
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-builder-error.png' ),
	} );

	// Fix it: rename the second step's field, required on the first.
	await label( 'Paragraph text' ).fill( 'Message' );
	await label( 'Email' ).click();
	await press(
		page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'tab', { name: 'Block' } )
	);
	const required = page.getByRole( 'checkbox', { name: 'Required' } );
	await required.check();
	await save( page );
	await expect(
		page
			.getByTestId( 'snackbar' )
			.or( page.locator( '.components-snackbar' ) )
			.filter( { hasText: /saved|published|updated/i } )
			.first()
	).toBeVisible();

	// The axe check on the settings panel, as far as the editor allows.
	await openSettingsSidebar( page );
	await openPanel( page, 'Form settings' );
	await expectNoSeriousA11yViolations( page, {
		exclude: [ '#wpadminbar', 'iframe' ],
	} );
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-builder-editor.png' ),
	} );

	// The registry has the form, two steps, in that order.
	const status = await page.evaluate( () =>
		window.wp.data
			.select( 'core/editor' )
			.getCurrentPostAttribute( 'status' )
	);
	expect( status ).toBe( 'private' );
	await expect( page.getByLabel( 'Form key' ) ).toBeDisabled();

	// Place it on a page and submit it (the view script shows one step at a time).
	const pageRecord = await adminCall( 'POST', '/wp/v2/pages', {
		title: 'E2E built form',
		status: 'publish',
		content: `<!-- wp:pegwork/form {"form":"${ KEY }"} /-->`,
	} );
	created.pages.push( pageRecord.id );
	await page.goto( pageRecord.link );
	const form = page.locator( 'form' ).filter( {
		has: page.locator( 'input[name="pegwork_form[key]"]' ),
	} );
	await expect(
		form.getByRole( 'group', { name: 'About you' } )
	).toBeVisible();
	await expect( form.locator( '.pegwork-form__progress' ) ).toHaveText(
		'Step 1 of 2'
	);
	await form.getByLabel( /Your name/ ).fill( 'Ada Lovelace' );
	await form.getByLabel( /Email/ ).fill( 'ada@example.org' );
	await form.getByRole( 'button', { name: 'Next' } ).click();
	await expect(
		form.getByRole( 'group', { name: 'Your message' } )
	).toBeVisible();
	await form.getByLabel( /Message/ ).fill( 'Built in the block editor.' );
	// The time trap needs 3 seconds between render and submit.
	await page.waitForTimeout( 3200 );
	await form.getByRole( 'button', { name: 'Send' } ).press( 'Enter' );
	await expect( page.getByRole( 'status' ) ).toContainText( 'Thank you' );

	const entries = await adminCall(
		'GET',
		'/pegwork-e2e/v1/entries',
		undefined,
		{ form: KEY }
	);
	expect( entries[ 0 ].status ).toBe( 'received' );
	expect( entries[ 0 ].values ).toMatchObject( {
		your_name: 'Ada Lovelace',
		email: 'ada@example.org',
		message: 'Built in the block editor.',
	} );
} );

test( 'Forms list by keyboard: counts, Entries link, Copy block; axe at 1280 and 782 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( FORMS );
	const row = page.getByRole( 'row' ).filter( { hasText: KEY } );
	await expect( row ).toContainText( 'E2E built contact' );
	await expect( row ).toContainText( 'Site' );
	await expect( row.locator( 'td.column-received' ) ).toHaveText( '1' );
	await expect( row.locator( 'td.column-unconfirmed' ) ).toHaveText( '0' );
	await expect(
		page.getByRole( 'row' ).filter( { hasText: 'e2e_contact' } )
	).toContainText( 'File: e2e_forms' );

	await page
		.context()
		.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await press( row.getByRole( 'button', { name: /Copy block/ } ) );
	await expect( page.locator( '#pegwork-forms-status' ) ).toHaveText(
		/Copied/
	);
	const copied = await page.evaluate( () => navigator.clipboard.readText() );
	expect( copied ).toBe( `<!-- wp:pegwork/form {"form":"${ KEY }"} /-->` );

	await expectNoSeriousA11yViolations( page, AXE );
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-builder-list.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 782, height: 900 } );
	await expectNoSeriousA11yViolations( page, AXE );
	const overflow = await page.evaluate(
		() => document.documentElement.scrollWidth > window.innerWidth
	);
	expect( overflow ).toBe( false );
	await page.screenshot( {
		path: path.join( SHOTS, 'forms-builder-list-782.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 1280, height: 900 } );

	await press( row.getByRole( 'link', { name: /Entries of/ } ) );
	await expect( page ).toHaveURL( /page=pegwork-entries.*form=e2e_built/ );
} );

test( 'a file form opens read-only with Duplicate as site form', async ( {
	page,
} ) => {
	await page.goto( FORMS );
	const row = page.getByRole( 'row' ).filter( { hasText: 'e2e_contact' } );
	await press( row.getByRole( 'link', { name: /View .* \(read-only\)/ } ) );
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );
	await expect(
		page
			.locator( '.components-notice' )
			.filter( { hasText: 'read-only' } )
			.first()
	).toBeVisible();
	// Truly read-only: no inserter, no Save draft, no status rows, no "Last edited".
	await expect( inserterToggle( page ) ).toBeHidden();
	await expect(
		page.getByRole( 'button', { name: 'Save draft' } )
	).toHaveCount( 0 );
	await expect(
		page.locator( '.editor-post-last-edited-panel' )
	).toBeHidden();
	await expect( page.locator( '.editor-post-status' ) ).toHaveCount( 0 );
	const duplicate = page
		.getByRole( 'link', { name: 'Duplicate as site form' } )
		.or( page.getByRole( 'button', { name: 'Duplicate as site form' } ) )
		.first();
	await press( duplicate );
	await openEditor( page, /post-new\.php.*pegwork_duplicate=e2e_contact/ );
	const id = await page.evaluate( () =>
		window.wp.data.select( 'core/editor' ).getCurrentPostId()
	);
	created.posts.push( id );
	await expect(
		canvas( page ).getByRole( 'textbox', { name: 'Add title' } )
	).toHaveText( /\(copy\)/ );
	await openSettingsSidebar( page );
	await openPanel( page, 'Form settings' );
	await expect( page.getByLabel( 'Form key' ) ).toHaveValue(
		'e2e_contact_copy'
	);
	await expect( page.getByLabel( 'Form key' ) ).toBeEnabled();
	// A new site form offers Save, not Publish.
	await expect(
		page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Save', exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Publish', exact: true } )
	).toHaveCount( 0 );
} );

test( 'an editor cannot open the builder or the Forms list', async ( {
	browser,
} ) => {
	const password = `E2e-${ Date.now() }-pw!`;
	const user = await adminCall( 'POST', '/wp/v2/users', {
		username: `e2e_editor_${ Date.now() }`,
		email: `e2e-editor-${ Date.now() }@example.org`,
		password,
		roles: [ 'editor' ],
	} );
	created.users.push( user.id );

	const context = await browser.newContext( {
		baseURL,
		storageState: { cookies: [], origins: [] },
	} );
	const page = await context.newPage();
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( user.username );
	await page.getByLabel( 'Password', { exact: true } ).fill( password );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( /wp-admin/ );

	const list = await page.goto( FORMS );
	expect( list.status() ).toBe( 403 );
	const builder = await page.goto(
		'/wp-admin/post-new.php?post_type=pegwork_form'
	);
	expect( builder.status() ).toBe( 403 );
	await context.close();
} );
