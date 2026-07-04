/**
 * Shared Playwright base config for DWS WordPress plugin E2E tests: the
 * `@wordpress/scripts` config with `testDir` on the DWS layout.
 *
 * When overriding nested keys (`use`, `webServer`, `projects`), spread them
 * individually — a top-level spread replaces the whole nested object.
 */

const wpBaseConfig = require('@wordpress/scripts/config/playwright.config.js');

module.exports = {
	...wpBaseConfig,
	testDir: 'tests/e2e',
};
