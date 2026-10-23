/**
 * The automation editing surface (Phase 6 PR 3, before the editor UI of PR 5): a site automation
 * saved through REST (400 with pointers, 201), the list's Add automation, Edit, View and "Duplicate as
 * site automation" links by keyboard, the block editor opening the step tree (decision, branches,
 * Otherwise) and saving it back unchanged, a file automation read-only, and a duplicate saved as a
 * disabled site automation. Axe at 1280 and 782 px on the list, no horizontal page scroll; axe on
 * the editor outside the canvas. Saves screenshots to docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (package e2e_automation, module toggle,
 * definition read, site reset).
 */
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const KEY = 'e2e_site_route';
const LABEL = 'E2E site route';
const FILE_LABEL = 'E2E daily digest';

const when = ( value ) => ( {
	var: 'trigger.payload.kind',
	op: 'eq',
	value,
} );
const mail = ( id, subject ) => ( {
	id,
	type: 'send_email',
	to: 'desk@example.org',
	subject,
	text: 'Kind {{trigger.payload.kind}}',
} );
const block = ( name, attrs, inner ) =>
	inner === undefined
		? `<!-- wp:pegwork/${ name } ${ JSON.stringify( attrs ) } /-->`
		: `<!-- wp:pegwork/${ name } ${ JSON.stringify(
				attrs
			) } -->\n${ inner.join( '\n\n' ) }\n<!-- /wp:pegwork/${ name } -->`;

/**
 * Block markup of the site automation: a decision with one branch and Otherwise, then a step.
 *
 * @param {string} subject Subject of the first branch step.
 */
function content( subject ) {
	return [
		block( 'automation-decision', { id: 'route' }, [
			block(
				'automation-branch',
				{ label: 'Kind A', when: when( 'a' ) },
				[ block( 'automation-step', mail( 'tell_a', subject ) ) ]
			),
			block( 'automation-branch', { otherwise: true }, [
				block( 'automation-step', mail( 'tell_other', 'Other' ) ),
			] ),
		] ),
		block( 'automation-step', mail( 'done', 'Done' ) ),
	].join( '\n\n' );
}

let admin;

async function call( method, route, data, params ) {
	return admin.fetch( restUrl( route ), {
		method,
		headers: { 'X-WP-Nonce': await restNonce( admin ) },
		data,
		params,
	} );
}

async function adminCall( method, route, data, params ) {
	const res = await call( method, route, data, params );
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

// The editor canvas: an iframe when every block is API version 3, else the page itself.
function canvas( page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

// Waits for the block editor that a link just opened, and closes the welcome guide.
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

// Saves with the keyboard shortcut and waits for the save request to finish.
async function saveByKeyboard( page ) {
	await page.keyboard.press( 'ControlOrMeta+s' );
	await page.waitForFunction(
		() =>
			! window.wp.data.select( 'core/editor' ).isSavingPost() &&
			! window.wp.data.select( 'core/editor' ).isEditedPostDirty()
	);
}

async function checkLayout( page ) {
	await expectNoSeriousA11yViolations( page, AXE );
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

test( 'REST save: 400 with pointers, then 201 as a disabled site automation', async () => {
	const body = ( subject ) => ( {
		title: LABEL,
		content: content( subject ),
		status: 'publish',
		meta: {
			_pegwork_automation_settings: JSON.stringify( {
				key: KEY,
				trigger: { type: 'webhook' },
			} ),
		},
	} );

	const refused = await call(
		'POST',
		'/wp/v2/pegwork-automations',
		body( 'Bad {{record.nope}}' )
	);
	expect( refused.status() ).toBe( 400 );
	const error = await refused.json();
	expect( error.code ).toBe( 'pegwork_invalid_automation' );
	expect( Object.keys( error.data.fields ) ).toEqual( [
		'/steps/0/branches/0/steps/0/subject',
	] );

	const created = await call(
		'POST',
		'/wp/v2/pegwork-automations',
		body( 'Kind A {{trigger.payload.kind}}' )
	);
	expect( created.status() ).toBe( 201 );
	const saved = await definition( KEY );
	expect( saved.source ).toBe( 'db' );
	expect( saved.definition.enabled ).toBe( false );
	expect( saved.definition.steps[ 0 ].otherwise[ 0 ].id ).toBe(
		'tell_other'
	);
} );

test( 'list links by keyboard, the editor opens the tree and saves it unchanged; axe at 1280 and 782 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	await expect(
		page.getByRole( 'link', { name: 'Add automation' } )
	).toHaveAttribute( 'href', /post-new\.php\?post_type=pegwork_automation$/ );

	const siteRow = page.getByRole( 'row', { name: new RegExp( LABEL ) } );
	await expect(
		siteRow.getByRole( 'link', { name: `Edit ${ LABEL }` } )
	).toBeVisible();
	await expect(
		siteRow.getByRole( 'link', { name: /^Duplicate as site automation/ } )
	).toHaveCount( 0 );
	const fileRow = page.getByRole( 'row', { name: new RegExp( FILE_LABEL ) } );
	await expect(
		fileRow.getByRole( 'link', { name: `View ${ FILE_LABEL }` } )
	).toBeVisible();
	await expect(
		fileRow.getByRole( 'link', {
			name: `Duplicate as site automation ${ FILE_LABEL }`,
		} )
	).toBeVisible();
	await checkLayout( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-list-edit-links.png' ),
		fullPage: true,
	} );
	await page.setViewportSize( { width: 782, height: 900 } );
	await checkLayout( page );
	await page.setViewportSize( { width: 1280, height: 900 } );

	const before = ( await definition( KEY ) ).definition;
	await press( siteRow.getByRole( 'link', { name: `Edit ${ LABEL }` } ) );
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );

	const frame = canvas( page );
	await expect( frame.locator( '.pegwork-automation-decision' ) ).toHaveCount(
		1
	);
	await expect(
		frame.locator( '.pegwork-automation-branch__heading' )
	).toHaveText( [ 'If trigger.payload.kind is "a"', 'Otherwise' ] );
	await expect( frame.locator( '.pegwork-automation-step' ) ).toHaveCount(
		3
	);
	await expect( frame.getByText( 'Then continue below' ) ).toBeVisible();
	await expectNoSeriousA11yViolations( page, {
		exclude: [ '#wpadminbar', '#adminmenuwrap', 'iframe' ],
	} );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-editor-placeholder.png' ),
	} );

	// Edit the title by keyboard and save: the editor's own serialization compiles to the same steps.
	const title = frame.getByRole( 'textbox', { name: 'Add title' } );
	await title.focus();
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' v2' );
	await saveByKeyboard( page );
	const after = ( await definition( KEY ) ).definition;
	expect( after.label ).toBe( `${ LABEL } v2` );
	expect( after.steps ).toEqual( before.steps );
	expect( after.trigger ).toEqual( before.trigger );
	expect( after.enabled ).toBe( false );
} );

test( 'a file automation is read-only; its duplicate saves as a disabled site automation', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	const fileRow = page.getByRole( 'row', { name: new RegExp( FILE_LABEL ) } );
	await press(
		fileRow.getByRole( 'link', { name: `View ${ FILE_LABEL }` } )
	);
	await openEditor( page, /post\.php\?post=\d+&action=edit/ );

	// The editor notice (the Automation panel, when the sidebar is open, holds a second one).
	const notice = page
		.locator( '.components-notice' )
		.filter( { hasText: 'comes from the file package "e2e_automation"' } )
		.first();
	await expect( notice ).toBeVisible();
	expect(
		await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).isPostSavingLocked()
		)
	).toBe( true );
	await expect( page.locator( 'body' ) ).toHaveClass(
		/pegwork-automation-readonly/
	);
	await expect(
		canvas( page ).locator( '.pegwork-automation-step' )
	).toHaveCount( 1 );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-editor-read-only.png' ),
	} );

	// Duplicate from the notice, by keyboard: a new post prefilled under e2e_digest_copy.
	await press(
		notice.getByRole( 'link', { name: 'Duplicate as site automation' } )
	);
	await openEditor( page, /post-new\.php\?post_type=pegwork_automation/ );
	await expect(
		canvas( page ).getByRole( 'textbox', { name: 'Add title' } )
	).toHaveText( `${ FILE_LABEL } (copy)` );
	await saveByKeyboard( page );

	const copy = await definition( 'e2e_digest_copy' );
	expect( copy.source ).toBe( 'db' );
	expect( copy.definition.enabled ).toBe( false );
	const original = ( await definition( 'e2e_digest' ) ).definition;
	expect( copy.definition.steps ).toEqual( original.steps );
	expect( copy.definition.trigger ).toEqual( original.trigger );
} );
