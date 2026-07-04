/**
 * Shared Stylelint base config for DWS WordPress plugins.
 *
 * Plugins extend this in their own `stylelint.config.js`:
 *
 *     const dwsBase = require('@ahegyes/wordpress-configs/node/stylelint.config.base.js');
 *     module.exports = {
 *         ...dwsBase,
 *         rules: { ...dwsBase.rules, 'plugin-specific-rule': 'error' },
 *     };
 *
 * Composes the @wordpress/stylelint-config preset (which itself extends
 * stylelint-config-recommended-scss) with sensible defaults for DWS plugins.
 *
 * The `ignoreFiles` defaults take effect through the spread pattern above: the
 * globs land in the consumer's own config and resolve against the consumer's
 * project. Loading this file via `extends` instead leaves them inert —
 * Stylelint resolves an extended config's `ignoreFiles` against the directory
 * of the file that declares them, which under `extends` is inside
 * node_modules. Spread, or declare your own.
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
