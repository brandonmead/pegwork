const { baseURL, adminUser, adminPassword } = require( './env' );

/**
 * Logs in through wp-login.php and leaves the context's cookies set.
 *
 * @param {import('@playwright/test').Page} page
 */
async function loginAsAdmin( page ) {
	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', adminUser );
	await page.fill( '#user_pass', adminPassword );
	await Promise.all( [
		page.waitForURL( /wp-admin/ ),
		page.click( '#wp-submit' ),
	] );
}

module.exports = { loginAsAdmin };
