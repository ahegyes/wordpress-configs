/**
 * Shared ESLint base config for DWS WordPress plugins.
 *
 * Uses the `.eslintrc` shareable-config format consumed by
 * `@wordpress/eslint-plugin` and `@wordpress/scripts`.
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
