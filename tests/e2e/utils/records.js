const { baseURL } = require( './env' );

/**
 * REST helpers for creating and deleting records. They use the logged-in cookies of the
 * request context (page.request). Pass the REST base from the content type
 * (for sifg_project it is "projects").
 *
 * @param {import('@playwright/test').APIRequestContext} request Logged-in request context.
 * @return {Promise<string>} A REST nonce.
 */
async function restNonce( request ) {
	const res = await request.get(
		`${ baseURL }/wp-admin/admin-ajax.php?action=rest-nonce`
	);
	if ( ! res.ok() ) {
		throw new Error(
			`Could not get a REST nonce (${ res.status() }). Is the request logged in?`
		);
	}
	return ( await res.text() ).trim();
}

function restUrl( route ) {
	return `${ baseURL }/?rest_route=${ encodeURIComponent( route ) }`;
}

/**
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string}                                       restBase e.g. "projects"
 * @param {Object}                                       data     Post fields; defaults to a draft.
 * @return {Promise<{id:number}>} The created record.
 */
async function createRecord( request, restBase, data = {} ) {
	const res = await request.post( restUrl( `/wp/v2/${ restBase }` ), {
		headers: { 'X-WP-Nonce': await restNonce( request ) },
		data: { status: 'draft', title: `E2E ${ Date.now() }`, ...data },
	} );
	if ( ! res.ok() ) {
		throw new Error(
			`createRecord ${ restBase } failed: ${ res.status() } ${ await res.text() }`
		);
	}
	return res.json();
}

async function deleteRecord( request, restBase, id ) {
	await request.delete( restUrl( `/wp/v2/${ restBase }/${ id }` ), {
		headers: { 'X-WP-Nonce': await restNonce( request ) },
		params: { force: 'true' },
	} );
}

module.exports = { createRecord, deleteRecord, restUrl, restNonce };
