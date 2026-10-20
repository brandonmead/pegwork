/**
 * The forms view script, as a visitor with JavaScript on (design section 11, cases 2, 3, 5 and 6):
 *
 * 2. Submit a project with the keyboard only through three steps: Enter on step 1 runs the hints
 *    (error summary focused, links move focus), live word counter, Next and Back move focus to the
 *    step heading and announce "Step 2 of 3" (the heading names the step), the Volunteer link
 *    appears in place, marked required, when "Needs volunteers" is checked (focus stays on the
 *    checkbox), the submitting state, the
 *    confirmation email, the confirm page POST, and the Pending project visible to the admin.
 *    Turnstile (a widget on the last step) is Phase 3 PR 4; this form has none.
 *    Server errors: the form opens on the first step with an error, summary links switch steps.
 * 3. JavaScript off: every step on one page with one submit button and the condition as help text
 *    (the full one-page submission is forms-confirm.e2e.js; the "needs JavaScript" message with
 *    Turnstile on is PR 4).
 * 5. Rate limit: the sixth submission within 10 minutes gets the notice, focused.
 * 6. Honeypot filled: the visitor sees the normal success message, the entry lands in Spam.
 *
 * axe at 1280 and 782 px on every step (no horizontal scroll at 782 px).
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-forms.php and tests/e2e/mu-plugins/pegwork-e2e-sifg.php.
 */
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { noJs } = require( '../utils/no-js' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

const visitor = { storageState: { cookies: [], origins: [] } };
test.describe.configure( { mode: 'serial' } );

// The server treats submissions faster than 3 seconds after render as bots.
const HUMAN_PAUSE_MS = 3200;
const SCREENSHOTS = 'docs/screenshots';

let admin;
const pages = {};
const projects = [];

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

const entries = ( form ) =>
	adminCall( 'GET', '/pegwork-e2e/v1/entries', undefined, { form } );
const mail = () => adminCall( 'GET', '/pegwork-e2e/v1/mail' );

/**
 * axe at 1280 and 782 px, and no horizontal scroll at 782 px.
 *
 * @param {import('@playwright/test').Page} page
 */
async function expectAccessible( page ) {
	for ( const width of [ 1280, 782 ] ) {
		await page.setViewportSize( { width, height: 900 } );
		await expectNoSeriousA11yViolations( page, {
			exclude: [ '.wp-block-navigation' ],
		} );
	}
	const overflow = await page.evaluate(
		() =>
			document.documentElement.scrollWidth -
			document.documentElement.clientWidth
	);
	expect( overflow, 'horizontal scroll at 782 px' ).toBeLessThanOrEqual( 0 );
	await page.setViewportSize( { width: 1280, height: 900 } );
}

/**
 * Presses Tab until the locator has focus (keyboard only, as a person would).
 *
 * @param {import('@playwright/test').Page}    page   Page.
 * @param {import('@playwright/test').Locator} target Element to reach.
 * @param {string}                             [key]  Tab or Shift+Tab.
 */
async function tabTo( page, target, key = 'Tab' ) {
	for ( let i = 0; i < 80; i++ ) {
		if (
			await target.evaluate(
				( el ) => el === el.ownerDocument.activeElement
			)
		) {
			return;
		}
		await page.keyboard.press( key );
	}
	throw new Error( 'Tab never reached the target' );
}

test.beforeAll( async () => {
	admin = await requestFactory.newContext( {
		baseURL,
		storageState: authFile,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/forms-module', {
		enabled: true,
	} );
	for ( const form of [ 'e2e_project', 'e2e_contact' ] ) {
		const created = await adminCall( 'POST', '/wp/v2/pages', {
			status: 'publish',
			title: `Forms view ${ form } ${ Date.now() }`,
			content: `<!-- wp:pegwork/form {"form":"${ form }"} /-->`,
		} );
		pages[ form ] = { id: created.id, url: created.link };
	}
} );

test.beforeEach( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
} );

test.afterAll( async () => {
	const nonce = await restNonce( admin );
	for ( const { id } of Object.values( pages ) ) {
		await admin.delete( restUrl( `/wp/v2/pages/${ id }` ), {
			headers: { 'X-WP-Nonce': nonce },
			params: { force: 'true' },
		} );
	}
	for ( const id of projects ) {
		await admin.delete( restUrl( `/wp/v2/projects/${ id }` ), {
			headers: { 'X-WP-Nonce': nonce },
			params: { force: 'true' },
		} );
	}
	await adminCall( 'POST', '/pegwork-e2e/v1/reset' );
	await admin.dispose();
} );

test.describe( 'JavaScript on', () => {
	test.use( visitor );

	test( 'case 2: submit a project with the keyboard through three steps', async ( {
		browser,
		page,
	} ) => {
		test.setTimeout( 120_000 );
		const name = `River Watch ${ Date.now() }`;
		const description = Array( 12 )
			.fill( 'Volunteers test river water every week.' )
			.join( ' ' );

		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( pages.e2e_project.url );
		const loaded = Date.now();
		const progress = page.locator( '.pegwork-form__progress' );
		const step = ( label ) => page.getByRole( 'group', { name: label } );
		const next = page.getByRole( 'button', { name: 'Next' } );
		const back = page.getByRole( 'button', { name: 'Back' } );
		const submit = page.locator( '.pegwork-form__submit' );

		// Step 1 only; the progress is a polite live region.
		await expect( progress ).toHaveText( 'Step 1 of 3' );
		await expect( progress ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( step( 'Your project' ) ).toBeVisible();
		await expect( step( 'Links' ) ).toBeHidden();
		await expect( step( 'Contact' ) ).toBeHidden();
		await expect( back ).toBeHidden();
		await expect( submit ).toBeHidden();
		await expectAccessible( page );
		await page.screenshot( {
			path: `${ SCREENSHOTS }/forms-view-step-1.png`,
			fullPage: true,
		} );

		// Keyboard only from here. Enter in Name means Next: the hints stop it.
		const nameBox = page.getByRole( 'textbox', { name: /^Name/ } );
		await tabTo( page, nameBox );
		await page.keyboard.press( 'Enter' );
		const summary = page.locator( '.pegwork-form__errors' );
		await expect( summary ).toBeFocused();
		await expect( summary.getByRole( 'link' ) ).toHaveText( [
			'Name is required.',
			'Description is required.',
			'Type is required.',
			'Project status is required.',
		] );
		await expect( progress ).toHaveText( 'Step 1 of 3' );
		await expect( nameBox ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( page ).toHaveTitle( /^Error: / );
		await expectAccessible( page );
		await page.screenshot( {
			path: `${ SCREENSHOTS }/forms-view-hints.png`,
			fullPage: true,
		} );

		// A summary link moves focus to its field.
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Enter' );
		await expect( nameBox ).toBeFocused();
		await page.keyboard.type( name );

		// Live word counter (debounced, polite, described by).
		await page.keyboard.press( 'Tab' );
		const descriptionBox = page.getByRole( 'textbox', {
			name: /^Description/,
		} );
		await expect( descriptionBox ).toBeFocused();
		const counter = page.locator(
			'#pegwork-e2e_project-description-count'
		);
		await expect( counter ).toHaveText( '0 words (50 to 300)' );
		await expect( counter ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( descriptionBox ).toHaveAttribute(
			'aria-describedby',
			/pegwork-e2e_project-description-count/
		);
		await page.keyboard.type( 'Volunteers test river water' );
		await expect( counter ).toHaveText( '4 words (50 to 300)' );
		await page.keyboard.press( 'ControlOrMeta+A' );
		await page.keyboard.type( description );
		await expect( counter ).toHaveText( '72 words (50 to 300)' );

		// Radio groups: Space picks the focused option, arrows move.
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'ArrowDown' );
		await expect(
			page.getByRole( 'radio', { name: 'Nonprofit' } )
		).toBeChecked();
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Space' );
		await expect(
			page.getByRole( 'radio', { name: 'Idea' } )
		).toBeChecked();
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Space' );
		await expect(
			page.getByRole( 'checkbox', { name: 'Zero Hunger' } )
		).toBeChecked();

		// Next: step 2, focus on its heading, announced.
		await tabTo( page, next );
		await page.keyboard.press( 'Enter' );
		await expect( progress ).toHaveText( 'Step 2 of 3' );
		await expect(
			page.getByRole( 'heading', { name: 'Links' } )
		).toBeFocused();
		await expect( step( 'Your project' ) ).toBeHidden();
		await expect( summary ).toHaveCount( 0 );
		await expect( page ).not.toHaveTitle( /^Error: / );

		// The condition toggles in place; its help text is gone with JavaScript.
		const volunteerLink = page.getByRole( 'textbox', {
			name: /^Volunteer link/,
		} );
		await expect( volunteerLink ).toBeHidden();
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Only needed if'
		);
		await expectAccessible( page );

		await page.keyboard.press( 'Tab' );
		await page.keyboard.type( 'https://riverwatch.example.org' );
		await page.keyboard.press( 'Tab' );
		const needsVolunteers = page.getByRole( 'checkbox', {
			name: /^Needs volunteers/,
		} );
		await expect( needsVolunteers ).toBeFocused();
		await page.keyboard.press( 'Space' );
		await expect( volunteerLink ).toBeVisible();
		await expect( needsVolunteers ).toBeFocused();
		// While the condition holds the field is marked required, for sight and for screen readers.
		await expect( volunteerLink ).toHaveAttribute(
			'aria-required',
			'true'
		);
		await expect(
			page.locator(
				'label[for="pegwork-e2e_project-volunteer_link"] [data-pegwork-required-when]'
			)
		).toHaveText( '(required)' );
		await page.screenshot( {
			path: `${ SCREENSHOTS }/forms-view-step-2.png`,
			fullPage: true,
		} );

		// Next with the link empty: required_when hint.
		await tabTo( page, next );
		await page.keyboard.press( 'Enter' );
		await expect( summary ).toBeFocused();
		await expect( summary.getByRole( 'link' ) ).toHaveText( [
			'Volunteer link is required.',
		] );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Enter' );
		await expect( volunteerLink ).toBeFocused();
		await page.keyboard.type( 'https://riverwatch.example.org/join' );
		await expectAccessible( page );

		await tabTo( page, next );
		await page.keyboard.press( 'Enter' );
		await expect( progress ).toHaveText( 'Step 3 of 3' );
		await expect(
			page.getByRole( 'heading', { name: 'Contact' } )
		).toBeFocused();
		await expect( next ).toBeHidden();
		await expect( submit ).toBeVisible();

		// Back keeps the answers and focuses the step heading.
		await tabTo( page, back );
		await page.keyboard.press( 'Enter' );
		await expect( progress ).toHaveText( 'Step 2 of 3' );
		await expect(
			page.getByRole( 'heading', { name: 'Links' } )
		).toBeFocused();
		await expect( volunteerLink ).toHaveValue(
			'https://riverwatch.example.org/join'
		);
		await tabTo( page, next );
		await page.keyboard.press( 'Enter' );
		await expect( progress ).toHaveText( 'Step 3 of 3' );
		await expectAccessible( page );

		await page.keyboard.press( 'Tab' );
		await expect(
			page.getByRole( 'textbox', { name: /^Contact email/ } )
		).toBeFocused();
		await page.keyboard.type( 'ada@example.org' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Space' );
		await expect(
			page.getByRole( 'checkbox', { name: /I have permission to share/ } )
		).toBeChecked();
		await page.screenshot( {
			path: `${ SCREENSHOTS }/forms-view-step-3.png`,
			fullPage: true,
		} );

		// Submitting state. Playwright cannot read the page while its POST is in flight, so a listener
		// after the script's own records the state in sessionStorage (kept across the navigation), and
		// asks for a second submit, which the script must refuse. The POST is held 500 ms.
		let posts = 0;
		page.on( 'request', ( request ) => {
			if ( request.method() === 'POST' ) {
				posts++;
			}
		} );
		await page.route(
			( url ) => url.href.startsWith( pages.e2e_project.url ),
			async ( route ) => {
				if ( route.request().method() === 'POST' ) {
					await new Promise( ( resolve ) =>
						setTimeout( resolve, 500 )
					);
				}
				await route.continue();
			}
		);
		await page.evaluate( () => {
			const form = document.querySelector( 'form.pegwork-form__form' );
			form.addEventListener( 'submit', ( event ) => {
				const button = form.querySelector( '.pegwork-form__submit' );
				const seen = JSON.parse(
					sessionStorage.getItem( 'pegworkE2eSubmits' ) || '[]'
				);
				seen.push( {
					prevented: event.defaultPrevented,
					text: button.textContent,
					ariaDisabled: button.getAttribute( 'aria-disabled' ),
					focused: button.ownerDocument.activeElement === button,
					busy: form.getAttribute( 'aria-busy' ),
				} );
				sessionStorage.setItem(
					'pegworkE2eSubmits',
					JSON.stringify( seen )
				);
				if ( seen.length === 1 ) {
					setTimeout( () => form.requestSubmit(), 0 );
				}
			} );
		} );
		const elapsed = Date.now() - loaded;
		if ( elapsed < HUMAN_PAUSE_MS ) {
			await page.waitForTimeout( HUMAN_PAUSE_MS - elapsed );
		}
		await tabTo( page, submit );
		await page.keyboard.press( 'Enter' );
		await page.waitForURL( /pegwork_sent=e2e_project/ );
		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		const submits = await page.evaluate( () =>
			JSON.parse( sessionStorage.getItem( 'pegworkE2eSubmits' ) )
		);
		expect( submits ).toEqual( [
			{
				prevented: false,
				text: 'Sending',
				ariaDisabled: 'true',
				focused: true,
				busy: 'true',
			},
			{
				prevented: true,
				text: 'Sending',
				ariaDisabled: 'true',
				focused: true,
				busy: 'true',
			},
		] );
		expect( posts ).toBe( 1 );
		const sent = page.getByRole( 'status' );
		await expect( sent ).toHaveText(
			'Check your email to confirm your submission. The link expires in 72 hours.'
		);
		await expect( sent ).toBeFocused();

		let stored = await entries( 'e2e_project' );
		expect( stored ).toHaveLength( 1 );
		expect( stored[ 0 ].status ).toBe( 'unconfirmed' );

		// Confirm by email: the link shows a button, Enter on it POSTs.
		const confirmMail = ( await mail() ).find( ( m ) =>
			String( m.message ).includes( 'pegwork_confirm=' )
		);
		expect( confirmMail.to ).toContain( 'ada@example.org' );
		const link = String( confirmMail.message ).match(
			/https?:\/\/\S*pegwork_confirm=[0-9]+\.[A-Za-z0-9_-]{43}/
		)[ 0 ];
		await page.goto( link );
		const confirmButton = page.getByRole( 'button', {
			name: 'Confirm my submission',
		} );
		await tabTo( page, confirmButton );
		await page.keyboard.press( 'Enter' );
		await page.waitForURL( /pegwork_confirmed=e2e_project/ );
		await expect(
			page.getByRole( 'heading', { name: 'Submission confirmed' } )
		).toBeFocused();
		await expect( page.locator( '.pegwork-form__confirm' ) ).toContainText(
			'Thank you. Your submission is confirmed.'
		);

		stored = await entries( 'e2e_project' );
		expect( stored[ 0 ].status ).toBe( 'received' );
		const postId = Number( stored[ 0 ].post_id );
		expect( postId ).toBeGreaterThan( 0 );
		projects.push( postId );
		const project = await adminCall(
			'GET',
			`/wp/v2/projects/${ postId }`,
			undefined,
			{ context: 'edit' }
		);
		expect( project.status ).toBe( 'pending' );
		expect( project.title.raw ).toBe( name );
		expect( project.meta ).toMatchObject( {
			_sifg_project_website: 'https://riverwatch.example.org',
			_sifg_project_needs_volunteers: true,
			_sifg_project_volunteer_link: 'https://riverwatch.example.org/join',
			_sifg_project_contact_email: 'ada@example.org',
		} );
		const terms = async ( taxonomy ) =>
			(
				await adminCall( 'GET', `/wp/v2/${ taxonomy }`, undefined, {
					post: postId,
				} )
			)
				.map( ( t ) => t.slug )
				.sort();
		expect( await terms( 'sifg_project_type' ) ).toEqual( [ 'nonprofit' ] );
		expect( await terms( 'sifg_project_status' ) ).toEqual( [ 'idea' ] );
		expect( await terms( 'sifg_impact' ) ).toEqual( [
			'sdg-01',
			'sdg-02',
		] );

		// The admin sees it in the Pending list.
		const context = await browser.newContext( { storageState: authFile } );
		const adminPage = await context.newPage();
		await adminPage.goto(
			`${ baseURL }/wp-admin/edit.php?post_type=sifg_project&post_status=pending`
		);
		await expect(
			adminPage
				.locator( '#the-list' )
				.getByRole( 'link', { name, exact: true } )
		).toBeVisible();
		await context.close();
	} );

	test( 'case 2: server errors open on the first step with one, summary links switch steps', async ( {
		page,
	} ) => {
		await page.goto( pages.e2e_project.url );
		const loaded = Date.now();
		const progress = page.locator( '.pegwork-form__progress' );
		await page
			.getByRole( 'textbox', { name: /^Name/ } )
			.fill( `Errors ${ Date.now() }` );
		await page
			.getByRole( 'textbox', { name: /^Description/ } )
			.fill(
				Array( 13 ).fill( 'Volunteers test river water.' ).join( ' ' )
			);
		await page.getByRole( 'radio', { name: 'Nonprofit' } ).check();
		await page.getByRole( 'radio', { name: 'Idea' } ).check();
		await page.getByRole( 'button', { name: 'Next' } ).click();
		await page
			.getByRole( 'checkbox', { name: /^Needs volunteers/ } )
			.check();
		// Send without the hints (form.submit() fires no submit event), so the server finds the errors.
		const elapsed = Date.now() - loaded;
		if ( elapsed < HUMAN_PAUSE_MS ) {
			await page.waitForTimeout( HUMAN_PAUSE_MS - elapsed );
		}
		await Promise.all( [
			page.waitForNavigation(),
			page.evaluate( () =>
				document.querySelector( 'form.pegwork-form__form' ).submit()
			),
		] );

		const summary = page.locator( '.pegwork-form__errors' );
		await expect( summary ).toBeFocused();
		await expect( summary.getByRole( 'link' ) ).toHaveText( [
			'Volunteer link is required.',
			'Contact email is required.',
			/^Check the box to confirm:/,
		] );
		await expect( progress ).toHaveText( 'Step 2 of 3' );
		await expect(
			page.getByRole( 'textbox', { name: /^Volunteer link/ } )
		).toBeVisible();
		await expect( page ).toHaveTitle( /^Error: / );
		await expectAccessible( page );
		await page.screenshot( {
			path: `${ SCREENSHOTS }/forms-view-server-errors.png`,
			fullPage: true,
		} );

		// Keyboard: the second link switches to step 3 and focuses the field.
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Enter' );
		await expect( progress ).toHaveText( 'Step 3 of 3' );
		await expect(
			page.getByRole( 'textbox', { name: /^Contact email/ } )
		).toBeFocused();
		await summary.getByRole( 'link', { name: 'Volunteer link' } ).click();
		await expect( progress ).toHaveText( 'Step 2 of 3' );
		await expect(
			page.getByRole( 'textbox', { name: /^Volunteer link/ } )
		).toBeFocused();
		expect( await entries( 'e2e_project' ) ).toHaveLength( 0 );
	} );

	test( 'case 5: the sixth submission in 10 minutes is rate limited', async ( {
		page,
	} ) => {
		test.setTimeout( 120_000 );
		const send = async ( n ) => {
			await page.goto( pages.e2e_contact.url );
			const loaded = Date.now();
			await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Ada' );
			await page
				.getByRole( 'textbox', { name: /^Email/ } )
				// A new address each time: the per-address limit (3 an hour) must not hit first.
				.fill( `ada${ n }@example.org` );
			await page
				.getByRole( 'textbox', { name: /^Message/ } )
				.fill( `Message number ${ n }.` );
			await page
				.getByRole( 'checkbox', { name: /You may contact me/ } )
				.check();
			const elapsed = Date.now() - loaded;
			if ( elapsed < HUMAN_PAUSE_MS ) {
				await page.waitForTimeout( HUMAN_PAUSE_MS - elapsed );
			}
			await Promise.all( [
				page.waitForNavigation(),
				page.getByRole( 'button', { name: 'Send message' } ).click(),
			] );
		};

		// Hints on a single-section form: nothing is sent.
		await page.goto( pages.e2e_contact.url );
		await page.getByRole( 'button', { name: 'Send message' } ).click();
		await expect( page.locator( '.pegwork-form__errors' ) ).toBeFocused();
		await expect(
			page.locator( '.pegwork-form__errors' ).getByRole( 'link' )
		).toHaveText( [
			'Name is required.',
			'Email is required.',
			'Message is required.',
			'Check the box to confirm: You may contact me about this message',
		] );
		await expect( page.locator( '.pegwork-form__progress' ) ).toHaveCount(
			0
		);

		for ( let n = 1; n <= 5; n++ ) {
			await send( n );
			await expect( page.getByRole( 'status' ) ).toHaveText(
				'Thanks. Your message is on its way.'
			);
		}
		await send( 6 );
		const notice = page.getByRole( 'alert' );
		// The time frame comes from the 10-minute window and the oldest of the five counted submissions.
		await expect( notice ).toHaveText(
			/^Too many submissions from your connection\. Try again in about (9|10) minutes\.$/
		);
		await expect( notice ).toBeFocused();
		await expect(
			page.getByRole( 'textbox', { name: /^Message/ } )
		).toHaveValue( 'Message number 6.' );
		await expectAccessible( page );
		expect( await entries( 'e2e_contact' ) ).toHaveLength( 5 );
	} );

	test( 'case 6: a filled honeypot shows success and lands in Spam', async ( {
		page,
	} ) => {
		await page.goto( pages.e2e_contact.url );
		const loaded = Date.now();
		await page.getByRole( 'textbox', { name: /^Name/ } ).fill( 'Bot' );
		await page
			.getByRole( 'textbox', { name: /^Email/ } )
			.fill( 'bot@example.org' );
		await page
			.getByRole( 'textbox', { name: /^Message/ } )
			.fill( 'Buy things.' );
		await page
			.getByRole( 'checkbox', { name: /You may contact me/ } )
			.check();
		await page.evaluate( () => {
			document.querySelector( 'input[name="pegwork_form[url]"]' ).value =
				'https://spam.example';
		} );
		const elapsed = Date.now() - loaded;
		if ( elapsed < HUMAN_PAUSE_MS ) {
			await page.waitForTimeout( HUMAN_PAUSE_MS - elapsed );
		}
		await page.getByRole( 'button', { name: 'Send message' } ).click();
		await page.waitForURL( /pegwork_sent=e2e_contact/ );
		await expect( page.getByRole( 'status' ) ).toHaveText(
			'Thanks. Your message is on its way.'
		);
		const stored = await entries( 'e2e_contact' );
		expect( stored ).toHaveLength( 1 );
		expect( stored[ 0 ].status ).toBe( 'spam' );
		expect( stored[ 0 ].meta.spam ).toContain( 'honeypot' );
		expect( await mail() ).toEqual( [] );
	} );
} );

test.describe( 'JavaScript off', () => {
	test.use( noJs );

	test( 'case 3: every step on one page, one submit, conditions as help text', async ( {
		page,
	} ) => {
		await page.goto( pages.e2e_project.url );
		for ( const label of [ 'Your project', 'Links', 'Contact' ] ) {
			await expect(
				page.getByRole( 'group', { name: label } )
			).toBeVisible();
			await expect(
				page.getByRole( 'heading', { name: label } )
			).toBeVisible();
		}
		await expect( page.locator( '.pegwork-form__progress' ) ).toHaveCount(
			0
		);
		await expect(
			page.locator( 'form.pegwork-form__form' ).getByRole( 'button' )
		).toHaveText( [ 'Submit project' ] );
		const volunteerLink = page.getByRole( 'textbox', {
			name: /^Volunteer link/,
		} );
		await expect( volunteerLink ).toBeVisible();
		// Without JavaScript the marker names the condition; aria-required would be wrong half the time.
		await expect(
			page.locator(
				'label[for="pegwork-e2e_project-volunteer_link"] [data-pegwork-required-when]'
			)
		).toHaveText( '(required if "Needs volunteers" is checked)' );
		await expect( volunteerLink ).not.toHaveAttribute( 'aria-required' );
		await expect( page.locator( 'body' ) ).toContainText(
			'Only needed if "Needs volunteers" is checked.'
		);
	} );
} );
