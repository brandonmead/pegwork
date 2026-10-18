const { chromium } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );
const { authFile } = require( './utils/env' );
const { loginAsAdmin } = require( './utils/auth' );

// Logs in once and saves the cookies so specs start authenticated.
module.exports = async () => {
	const browser = await chromium.launch();
	const context = await browser.newContext();
	const page = await context.newPage();
	await loginAsAdmin( page );
	fs.mkdirSync( path.dirname( authFile ), { recursive: true } );
	await context.storageState( { path: authFile } );
	await browser.close();
};
