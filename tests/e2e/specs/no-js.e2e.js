const { test, expect } = require( '@playwright/test' );
const { noJs } = require( '../utils/no-js' );

test.use( noJs );

for ( const path of [ '/', '/projects/' ] ) {
	test( `${ path } returns 200 and renders with JavaScript off`, async ( {
		page,
	} ) => {
		const response = await page.goto( path );
		expect( response.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).toBeVisible();
		await expect( page.locator( 'html' ) ).not.toHaveClass( /wp-die/ );
		expect(
			( await page.locator( 'body' ).innerText() ).trim().length
		).toBeGreaterThan( 0 );
	} );
}
