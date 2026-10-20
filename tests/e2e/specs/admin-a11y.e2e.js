const { test, expect } = require( '../utils/fixtures' );
const { expectNoSeriousA11yViolations } = require( '../utils/a11y' );

// Axe at 1280 and 782 px, and no horizontal page scroll at 782 px, on every Pegwork admin screen
// found in the admin menu. Skips cleanly while none exist.
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

	const base = page.url();
	for ( const href of hrefs ) {
		for ( const width of [ 1280, 782 ] ) {
			await page.setViewportSize( { width, height: 900 } );
			await page.goto( new URL( href, base ).toString() );
			await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
			await expectNoSeriousA11yViolations( page, {
				exclude: [ '#wpadminbar', '#adminmenuwrap' ],
			} );
			if ( width === 782 ) {
				const overflow = await page.evaluate(
					() =>
						document.documentElement.scrollWidth - window.innerWidth
				);
				expect(
					overflow,
					`horizontal page scroll at 782 px on ${ href }`
				).toBeLessThanOrEqual( 0 );
			}
		}
	}
	await page.setViewportSize( { width: 1280, height: 900 } );
} );
