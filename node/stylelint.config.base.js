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
 * No `ignoreFiles` here: Stylelint resolves those globs against the directory
 * of the config file that declares them — from a shared base that means inside
 * node_modules, never the consumer's tree. Declare ignores in the consuming
 * plugin's own config (or `.stylelintignore`).
 */

module.exports = {
	extends: ['@wordpress/stylelint-config/scss'],
};
