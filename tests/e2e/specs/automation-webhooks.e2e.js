/**
 * Webhooks over real HTTP: the inbound endpoint's answers (202, duplicate delivery, 401 for bad, unknown
 * and replayed signatures, 413, 415, 429), the outbound delivery a signed request starts (verified by the
 * test receiver), a retrying delivery while the receiver is down, and the Automations list and run detail
 * by keyboard. Axe at 1280 and 782 px, no horizontal page scroll at 782 px. Saves screenshots to
 * docs/screenshots/.
 *
 * Needs tests/e2e/mu-plugins/pegwork-e2e-automation.php (module switch, cron runner) and
 * tests/e2e/mu-plugins/pegwork-e2e-webhooks.php (package, test receiver, fixed test secret, reset).
 */
const crypto = require( 'crypto' );
const path = require( 'path' );
const { test, expect, request: requestFactory } = require( '@playwright/test' );
const { baseURL, authFile } = require( '../utils/env' );
const { restNonce, restUrl } = require( '../utils/records' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

test.describe.configure( { mode: 'serial' } );

const SHOTS = path.join( __dirname, '..', '..', '..', 'docs', 'screenshots' );
const LIST = '/wp-admin/admin.php?page=pegwork-automations';
const AXE = { exclude: [ '#wpadminbar', '#adminmenuwrap' ] };
const LABEL = 'E2E inbound to outbound';
// The fixed test secret the webhooks mu-plugin stores on reset.
const SECRET = 'e2e-webhook-secret-not-for-production-0123456789';
const ROUTE = '/pegwork/v1/automations/e2e_hook/webhook';

let admin;
let visitor;

async function adminCall( method, route, data ) {
	const res = await admin.fetch( restUrl( route ), {
		method,
		headers: { 'X-WP-Nonce': await restNonce( admin ) },
		data,
	} );
	if ( ! res.ok() ) {
		throw new Error(
			`${ method } ${ route } failed: ${ res.status() } ${ await res.text() }`
		);
	}
	return res.json();
}

/**
 * Posts to the inbound endpoint as an outside sender would (no cookies), signed as documented:
 * sha256= HMAC-SHA256 of "<timestamp>.<raw body>".
 *
 * @param {string} body                Raw body.
 * @param {Object} options             Options.
 * @param {string} [options.route]     REST route.
 * @param {string} [options.secret]    Secret to sign with.
 * @param {number} [options.timestamp] Unix time.
 * @param {string} [options.type]      Content-Type.
 * @param {string} [options.delivery]  X-Pegwork-Delivery.
 * @return {Promise<import('@playwright/test').APIResponse>} Response.
 */
function send( body, options = {} ) {
	const timestamp = String(
		options.timestamp ?? Math.floor( Date.now() / 1000 )
	);
	const signature =
		'sha256=' +
		crypto
			.createHmac( 'sha256', options.secret ?? SECRET )
			.update( `${ timestamp }.${ body }` )
			.digest( 'hex' );
	const headers = {
		'Content-Type': options.type ?? 'application/json',
		'X-Pegwork-Timestamp': timestamp,
		'X-Pegwork-Signature': signature,
	};
	if ( options.delivery ) {
		headers[ 'X-Pegwork-Delivery' ] = options.delivery;
	}
	return visitor.post( restUrl( options.route ?? ROUTE ), {
		headers,
		data: body,
	} );
}

async function press( page, control, url ) {
	await control.focus();
	await expect( control ).toBeFocused();
	await Promise.all( [
		page.waitForURL( url ),
		page.keyboard.press( 'Enter' ),
	] );
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
	visitor = await requestFactory.newContext( {
		baseURL,
		storageState: { cookies: [], origins: [] },
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: true,
	} );
	await adminCall( 'POST', '/pegwork-e2e/v1/webhooks/reset' );
} );

test.afterAll( async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/webhooks/reset' );
	await adminCall( 'POST', '/pegwork-e2e/v1/automation-module', {
		enabled: false,
	} );
	await admin.dispose();
	await visitor.dispose();
} );

test( 'inbound answers: 202, duplicate, 401, 413, 415', async () => {
	const body = JSON.stringify( { title: 'From the E2E sender' } );

	const first = await send( body, { delivery: 'e2e-1' } );
	expect( first.status() ).toBe( 202 );
	const created = await first.json();
	expect( created.status ).toBe( 'queued' );
	expect( created.run_id ).toBeGreaterThan( 0 );
	expect( JSON.stringify( created ) ).not.toContain( 'E2E sender' );

	const again = await send( body, { delivery: 'e2e-1' } );
	expect( again.status() ).toBe( 200 );
	expect( await again.json() ).toEqual( {
		run_id: created.run_id,
		duplicate: true,
	} );

	// The pretty /wp-json/ URL is the same endpoint.
	const pretty = await visitor.post( `${ baseURL }/wp-json${ ROUTE }`, {
		headers: { 'Content-Type': 'text/plain' },
		data: body,
	} );
	expect( pretty.status() ).toBe( 415 );

	const unauthorized = [
		await send( body, { secret: 'wrong' } ),
		await send( body, {
			timestamp: Math.floor( Date.now() / 1000 ) - 400,
		} ),
		await send( body, {
			route: '/pegwork/v1/automations/e2e_nothing/webhook',
		} ),
		await send( body, {
			route: '/pegwork/v1/automations/e2e_digest/webhook',
		} ),
	];
	const answers = [];
	for ( const res of unauthorized ) {
		expect( res.status() ).toBe( 401 );
		answers.push( await res.text() );
	}
	expect( new Set( answers ).size ).toBe( 1 );
	expect( answers[ 0 ] ).toContain( 'pegwork_webhook_unauthorized' );

	const large = await send(
		JSON.stringify( { title: 'x'.repeat( 70 * 1024 ) } )
	);
	expect( large.status() ).toBe( 413 );
	const text = await send( 'title=x', {
		type: 'application/x-www-form-urlencoded',
	} );
	expect( text.status() ).toBe( 415 );

	// The run ran the outbound step; the test receiver verified its signature.
	await expect( async () => {
		await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );
		const received = await adminCall(
			'GET',
			'/pegwork-e2e/v1/webhooks/received'
		);
		expect( received ).toEqual( [
			{
				delivery: `${ created.run_id }-notify`,
				verified: true,
				down: false,
			},
		] );
	} ).toPass( { timeout: 20_000 } );
} );

test( 'list and run detail by keyboard, a retrying delivery; axe clean at 1280 and 782 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );
	await page.goto( LIST );
	const row = page.getByRole( 'row', { name: new RegExp( LABEL ) } );
	await expect( row ).toContainText( 'Inbound webhook' );
	await expect( row.locator( 'code.pegwork-endpoint' ) ).toHaveText(
		/\/pegwork\/v1\/automations\/e2e_hook\/webhook$/
	);
	await expect( row ).toContainText(
		'wp pegwork automation secret e2e_hook'
	);
	await expect( row ).toContainText( 'When a signed request arrives' );
	await expect( page.locator( 'body' ) ).not.toContainText( SECRET );
	await checkLayout( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-webhook-list.png' ),
		fullPage: true,
	} );

	// The receiver goes down: the next delivery is retried later.
	await adminCall( 'POST', '/pegwork-e2e/v1/webhooks/down', { down: true } );
	const res = await send( JSON.stringify( { title: 'While down' } ), {
		delivery: 'e2e-2',
	} );
	expect( res.status() ).toBe( 202 );
	const { run_id: runId } = await res.json();
	await expect( async () => {
		await adminCall( 'POST', '/pegwork-e2e/v1/automation/cron' );
		const received = await adminCall(
			'GET',
			'/pegwork-e2e/v1/webhooks/received'
		);
		expect( received.map( ( r ) => r.delivery ) ).toContain(
			`${ runId }-notify`
		);
	} ).toPass( { timeout: 20_000 } );

	// Keyboard: View runs of the automation, then the waiting run.
	await page.reload();
	await press(
		page,
		page.getByRole( 'link', { name: `View runs of ${ LABEL }` } ),
		/view=log&automation=e2e_hook/
	);
	await checkLayout( page );
	await press(
		page,
		page.getByRole( 'link', { name: `Run ${ runId }`, exact: true } ),
		new RegExp( `view=run&run=${ runId }` )
	);
	await expect(
		page.locator( '.pegwork-run .pegwork-status' ).first()
	).toHaveText( 'Waiting' );
	const attempts = page.getByRole( 'table', {
		name: 'Step attempts, oldest first',
	} );
	await expect( attempts ).toContainText( 'call_webhook' );
	await expect( attempts ).toContainText( 'receiver.e2e.test' );
	await expect( attempts ).toContainText( `${ runId }-notify` );
	await expect( attempts ).toContainText(
		'Could not reach receiver.e2e.test'
	);
	await expect( page.locator( 'body' ) ).not.toContainText( SECRET );
	await expect( page.locator( 'body' ) ).not.toContainText( 'sha256=' );
	await checkLayout( page );
	await page.screenshot( {
		path: path.join( SHOTS, 'automation-webhook-run.png' ),
		fullPage: true,
	} );

	// The delivered run: status 200 in the summary.
	const delivered = await adminCall(
		'GET',
		'/pegwork-e2e/v1/webhooks/received'
	);
	const firstRun = delivered[ 0 ].delivery.split( '-' )[ 0 ];
	await page.goto( `${ LIST }&view=run&run=${ firstRun }` );
	await expect(
		page.locator( '.pegwork-run .pegwork-status' ).first()
	).toHaveText( 'Completed' );
	await expect( attempts ).toContainText( 'status' );
	await expect( attempts ).toContainText( '200' );

	// 782 px.
	await page.setViewportSize( { width: 782, height: 1000 } );
	await page.goto( LIST );
	await checkLayout( page );
	await page.goto( `${ LIST }&view=run&run=${ runId }` );
	await checkLayout( page );
} );

test( 'inbound rate limit: 429 with Retry-After after 30 a minute', async () => {
	await adminCall( 'POST', '/pegwork-e2e/v1/webhooks/reset' );
	const body = JSON.stringify( { title: 'Burst' } );
	let limited = null;
	let sent = 0;
	while ( sent < 35 && ! limited ) {
		const res = await send( body, { secret: 'wrong' } );
		sent++;
		if ( res.status() === 429 ) {
			limited = res;
		} else {
			expect( res.status() ).toBe( 401 );
		}
	}
	expect( limited, 'a 429 within 35 requests' ).not.toBeNull();
	expect( sent ).toBe( 31 );
	expect( Number( limited.headers()[ 'retry-after' ] ) ).toBeGreaterThan( 0 );
} );
