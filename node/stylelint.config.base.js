/**
 * Spread this base into the consumer root config; `extends` drops `ignoreFiles`.
 */

module.exports = {
	extends: [ '@wordpress/stylelint-config/scss-stylistic' ],
	ignoreFiles: [
		'vendor/**',
		'vendor-prefixed/**',
		'node_modules/**',
		'**/build/**',
		'**/*.min.css',
	],
	reportDescriptionlessDisables: true,
	reportInvalidScopeDisables: true,
	reportNeedlessDisables: true,
	rules: {
		'selector-class-pattern': null,
	},
};
