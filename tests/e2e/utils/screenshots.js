/**
 * Where a display spec writes its screenshots.
 *
 * By default `test-results/screenshots` (ignored by git), so a test run leaves the tracked PNGs in
 * docs/screenshots untouched. To refresh the documented ones on purpose, set
 * `PEGWORK_E2E_REFRESH_SCREENSHOTS=1`; then they go to `docs/screenshots`.
 *
 * @return {string} Directory, relative to the repository root.
 */
function screenshotDir() {
	return process.env.PEGWORK_E2E_REFRESH_SCREENSHOTS === '1'
		? 'docs/screenshots'
		: 'test-results/screenshots';
}

module.exports = { screenshotDir };
