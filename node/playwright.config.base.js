/**
 * Spread nested keys (`use`, `webServer`, `projects`) individually when overriding the returned config.
 */

module.exports = ( { port } ) => {
	// @wordpress/scripts derives use.baseURL, webServer.port and outputDir from both variables while it is
	// being required, so they are set before the require; `??=` leaves an exported value authoritative.
	process.env.WP_BASE_URL ??= `http://localhost:${ port }`;
	process.env.WP_ARTIFACTS_PATH ??= 'tests/.cache/artifacts';

	const wpBaseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

	return {
		...wpBaseConfig,
		testDir: 'tests/e2e',
		webServer: {
			...wpBaseConfig.webServer,
			// The @wordpress/scripts default, `npm run wp-env start`, bypasses the consumer's wp-env:start script.
			command: 'npm run wp-env:start',
		},
	};
};
