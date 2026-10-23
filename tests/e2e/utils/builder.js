/**
 * Keyboard-only helpers for the automation builder specs (no mouse calls): focus with a retry,
 * key presses, typing over a field, the canvas and sidebar, panels and the Block tab, inserters,
 * selecting cards and branches, conditions, saving, and axe on the Pegwork parts.
 */
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { expect } = require( '@playwright/test' );
const { restNonce, restUrl } = require( './records' );

/**
 * An admin REST caller over a logged-in request context: `( method, route, data, params )`.
 *
 * @param {() => import('@playwright/test').APIRequestContext} getContext Returns the request context (created in beforeAll).
 * @return {(method: string, route: string, data?: Object, params?: Object) => Promise<Object>} Caller returning the parsed JSON, throwing on a non-2xx status.
 */
function adminCaller( getContext ) {
	return async ( method, route, data, params ) => {
		const admin = getContext();
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
	};
}

// Moves focus to a control. Retried: when focus first enters the canvas from the sidebar, the
// editor can hand it back to the selected block once.
async function focus( locator ) {
	// Let the editor finish rendering the last keystrokes first.
	await locator
		.page()
		.evaluate(
			() =>
				new Promise( ( resolve ) =>
					window.requestAnimationFrame( () =>
						window.requestAnimationFrame( resolve )
					)
				)
		);
	await expect( async () => {
		await locator.focus();
		await expect( locator ).toBeFocused( { timeout: 1000 } );
	} ).toPass( { timeout: 10_000 } );
}

// Moves focus to a control and activates it with the keyboard.
async function press( locator, key = 'Enter' ) {
	await focus( locator );
	await locator.page().keyboard.press( key );
}

// Focuses a text field, selects what it holds and types over it.
async function typeInto( locator, text ) {
	await focus( locator );
	await locator.page().keyboard.press( 'ControlOrMeta+a' );
	await locator.page().keyboard.type( text );
}

// As typeInto(), but enters the text as one keyboard input event (long templates).
async function insertInto( locator, text ) {
	await focus( locator );
	await locator.page().keyboard.press( 'ControlOrMeta+a' );
	await locator.page().keyboard.insertText( text );
	await expect( locator ).toHaveValue( text );
}

// Focuses a select and picks an option by value (no pointer: the change event a key press makes).
async function choose( locator, value ) {
	await focus( locator );
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

// Closes a document panel by its title (after openPanel()).
async function closePanel( page, title ) {
	const toggle = await openPanel( page, title );
	await press( toggle );
	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
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
	// The quick inserter focuses its search box; the same hand-back as in focus() can undo it.
	await focus( box );
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
		if (
			await box.evaluate(
				( el ) => el === el.ownerDocument.activeElement
			)
		) {
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
		return id ? ( editor.getBlockAttributes( id ).id ?? null ) : null;
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

// Gives the selected branch (or condition step) one condition: field and operator.
async function setCondition( page, field, op ) {
	const panel = sidebar( page );
	await press( panel.getByRole( 'button', { name: 'Add condition' } ) );
	const fieldSelect = panel.getByRole( 'combobox', { name: 'Field' } );
	if ( field ) {
		await choose( fieldSelect, field );
	}
	await choose( panel.getByRole( 'combobox', { name: 'Operator' } ), op );
}

// Axe on the Pegwork parts: canvas cards, the document panels and the block inspector, and no
// horizontal page scroll.
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

module.exports = {
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
	renameBranch,
	saveByKeyboard,
	selectBranch,
	selectCard,
	selectedId,
	setCondition,
	sidebar,
	typeInto,
};
