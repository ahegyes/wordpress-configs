/**
 * Shared Stylelint base config for DWS WordPress plugins: the SCSS preset of
 * `@wordpress/stylelint-config` plus DWS defaults.
 *
 * `ignoreFiles` only takes effect when this config is spread into the consumer
 * config. Under `extends`, Stylelint ignores `ignoreFiles` from the extended
 * config entirely, so the spread pattern is the required wiring.
 */

module.exports = {
	extends: ['@wordpress/stylelint-config/scss'],
	ignoreFiles: [
		'assets/**',
		'build/**',
		'vendor/**',
		'node_modules/**',
		'**/*.min.css',
	],
};
