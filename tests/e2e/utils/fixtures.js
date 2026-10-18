const base = require( '@playwright/test' );
const { createRecord, deleteRecord, restUrl } = require( './records' );

/**
 * Shared test object. Import `test` and `expect` from here in every spec.
 *
 * - `sifg`: asserts the examples/sifg package is registered (the mu-plugin is active) and
 *   returns helpers that create Projects and delete them after the test.
 */
const test = base.test.extend( {
	sifg: async ( { page }, provide ) => {
		const res = await page.request.get(
			restUrl( '/wp/v2/types/sifg_project' )
		);
		if ( ! res.ok() ) {
			throw new Error(
				'Post type sifg_project is not registered. Activate Pegwork and install tests/e2e/mu-plugins/pegwork-e2e-sifg.php.'
			);
		}
		const created = [];
		await provide( {
			restBase: 'projects',
			createProject: async ( data = {} ) => {
				const record = await createRecord(
					page.request,
					'projects',
					data
				);
				created.push( record.id );
				return record;
			},
		} );
		for ( const id of created ) {
			await deleteRecord( page.request, 'projects', id );
		}
	},
} );

module.exports = { test, expect: base.expect };
