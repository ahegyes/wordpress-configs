/**
 * Spread this base into the consumer root config; `extends` drops `ignoreFiles`.
 * ignoreFiles covers dependencies and build output.
 */

module.exports = {
	extends: ['@wordpress/stylelint-config/scss'],
	ignoreFiles: [
		'vendor/**',
		'node_modules/**',
		'**/build/**',
		'**/*.min.css',
	],
};
