/**
 * Spread nested keys (`use`, `webServer`, `projects`) individually when overriding the returned config.
 */

module.exports = ( { port } ) => {
	// The @wordpress/scripts config derives use.baseURL, webServer.port and outputDir from both variables
	// only while it is being required, so they are set first.
	process.env.WP_BASE_URL ??= `http://localhost:${ port }`;
	process.env.WP_ARTIFACTS_PATH ??= 'tests/.cache/artifacts';

	const wpBaseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

	return {
		...wpBaseConfig,
		testDir: 'tests/e2e',
	};
};
