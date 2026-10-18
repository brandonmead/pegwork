/**
 * Use in a spec with `test.use( noJs )` to run every test in the file with JavaScript off.
 * Also drops the admin login so the spec sees the site as a visitor.
 */
const noJs = {
	javaScriptEnabled: false,
	storageState: { cookies: [], origins: [] },
};

module.exports = { noJs };
