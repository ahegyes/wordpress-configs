/**
 * Shared flat ESLint baseline for WordPress projects; append project overrides after this array.
 * Ignores cover dependencies and build output.
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
		ignores: ['vendor/**', 'node_modules/**', '**/build/**', '**/*.min.js'],
	},
];
