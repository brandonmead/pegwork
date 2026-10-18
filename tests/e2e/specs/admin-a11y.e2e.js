const { test, expect } = require( '../utils/fixtures' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

// Axe on every Pegwork admin screen found in the admin menu. Skips cleanly while none exist.
test( 'Pegwork admin screens have no serious accessibility violations', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/' );
	const hrefs = await page.$$eval(
		'#adminmenu a[href*="page=pegwork"]',
		( links ) => [
			...new Set( links.map( ( a ) => a.getAttribute( 'href' ) ) ),
		]
	);
	test.skip(
		hrefs.length === 0,
		'No Pegwork admin screens on this build yet.'
	);

	for ( const href of hrefs ) {
		await page.goto( new URL( href, page.url() ).toString() );
		await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
		await expectNoSeriousA11yViolations( page, {
			exclude: [ '#wpadminbar', '#adminmenuwrap' ],
		} );
	}
} );
