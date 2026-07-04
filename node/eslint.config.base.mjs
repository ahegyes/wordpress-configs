/**
 * Shared ESLint base config (flat) for DWS WordPress plugins: the recommended
 * `@wordpress/eslint-plugin` preset plus DWS defaults, with the plugin's test
 * presets scoped to the DWS test layout.
 */

import wordpress from '@wordpress/eslint-plugin';

export default [
	...wordpress.configs.recommended,
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
