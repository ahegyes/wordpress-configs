/**
 * Shared Playwright base config for DWS WordPress plugin E2E tests.
 *
 * Extends `@wordpress/scripts/config/playwright.config.js` (the canonical
 * Playwright config from @wordpress/scripts), adjusting `testDir` to match
 * DWS plugin layout (`tests/e2e/`).
 *
 * Plugins extend this in their own `playwright.config.js`. Nested keys
 * (`use`, `webServer`, `projects`) must be spread individually — a top-level
 * spread replaces the whole nested object and drops the inherited WP defaults
 * (storage state, tracing, screenshots, viewport):
 *
 *     const { defineConfig } = require('@playwright/test');
 *     const baseConfig = require('@ahegyes/wordpress-configs/node/playwright.config.base.js');
 *
 *     module.exports = defineConfig({
 *         ...baseConfig,
 *         use: {
 *             ...baseConfig.use,
 *             // Plugin-specific overrides go here.
 *         },
 *     });
 *
 * For test fixtures (admin login, editor utilities, etc.) import from
 * `@wordpress/e2e-test-utils-playwright` in your test files.
 */

const wpBaseConfig = require('@wordpress/scripts/config/playwright.config.js');

module.exports = {
	...wpBaseConfig,
	testDir: 'tests/e2e',
};
