/**
 * Shared ESLint base config for DWS WordPress plugins.
 *
 * Uses the legacy `.eslintrc` shareable-config format because that's what
 * `@wordpress/eslint-plugin` (v22) ships and what `@wordpress/scripts` consumes.
 * Migrating to ESLint flat config will be revisited when @wordpress/eslint-plugin
 * ships native flat-config exports.
 *
 * Plugins extend this in their own `.eslintrc.js`:
 *
 *     module.exports = {
 *         extends: [
 *             require.resolve('@ahegyes/wordpress-configs/node/eslint.config.base.js'),
 *         ],
 *         rules: {
 *             // Plugin-specific overrides.
 *         },
 *     };
 */

module.exports = {
    extends: ['plugin:@wordpress/eslint-plugin/recommended'],
    rules: {
        'no-console': ['warn', { allow: ['warn', 'error'] }],
    },
    ignorePatterns: [
        'assets/**',
        'build/**',
        'vendor/**',
        'node_modules/**',
        '*.min.js',
    ],
};
