// Connection settings shared by the config and the helpers.
// Point the specs at any running WordPress with PEGWORK_E2E_BASE_URL (and user/password).
const baseURL = (
	process.env.PEGWORK_E2E_BASE_URL || 'http://localhost:8888'
).replace( /\/$/, '' );
const adminUser = process.env.PEGWORK_E2E_USER || 'admin';
const adminPassword = process.env.PEGWORK_E2E_PASSWORD || 'password';
const authFile = 'test-results/.auth/admin.json';

module.exports = { baseURL, adminUser, adminPassword, authFile };
