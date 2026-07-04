/**
 * Shared ESLint base config for DWS WordPress plugins.
 *
 * Flat-config format (eslint.config.mjs). Consumes `@wordpress/eslint-plugin`'s
 * recommended preset and adds DWS defaults.
 *
 * Plugins extend this in their own `eslint.config.mjs`:
 *
 *     import dwsBase from '@ahegyes/wordpress-configs/node/eslint.config.base.mjs';
 *
 *     export default [
 *         ...dwsBase,
 *         {
 *             rules: {
 *                 // Plugin-specific overrides.
 *             },
 *         },
 *     ];
 */

import wordpress from '@wordpress/eslint-plugin';

export default [
	...wordpress.configs.recommended,
	// The plugin ships its test rules as separate presets; scope them to the DWS test layout
	// so Jest globals and Playwright-specific rules apply where those files actually live.
	...wordpress.configs['test-unit'].map((config) => ({
		...config,
		files: ['**/test/**', '**/*.test.*'],
	})),
	...wordpress.configs['test-playwright'].map((config) => ({
		...config,
		files: ['tests/e2e/**'],
	})),
	{
		rules: {
			'no-console': ['warn', { allow: ['warn', 'error'] }],
		},
	},
	{
		ignores: [
			'assets/**',
			'build/**',
			'vendor/**',
			'node_modules/**',
			'*.min.js',
		],
	},
];
