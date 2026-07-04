/**
 * Shared Stylelint base config for DWS WordPress plugins: the SCSS preset of
 * `@wordpress/stylelint-config` plus DWS defaults.
 *
 * `ignoreFiles` takes effect via the spread pattern (see README); under
 * `extends` Stylelint resolves the globs against this file's directory.
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
