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
