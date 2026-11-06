/**
 * Shared flat ESLint baseline for WordPress projects; append project overrides after this array.
 * Ignores cover dependencies and build output.
 */

import wordpress from '@wordpress/eslint-plugin';

export default [
	// ESLint discovers only .js, .mjs and .cjs files on its own, and the recommended config names the TypeScript ones.
	{ files: [ '**/*.jsx' ] },
	...wordpress.configs.recommended,
	...wordpress.configs[ 'test-unit' ].map( ( config ) => ( {
		...config,
		files: [
			'**/@(test|__tests__)/**/*.{js,jsx,ts,tsx,mjs,cjs,mts,cts}',
			'**/*.@(test|spec).{js,jsx,ts,tsx,mjs,cjs,mts,cts}',
		],
		ignores: [ 'tests/EndToEnd/**' ],
	} ) ),
	...wordpress.configs[ 'test-playwright' ].map( ( config ) => ( {
		...config,
		files: [ 'tests/EndToEnd/**' ],
	} ) ),
	{
		rules: {
			'no-console': [ 'warn', { allow: [ 'warn', 'error' ] } ],
		},
	},
	{
		ignores: [
			'vendor/**',
			'node_modules/**',
			'**/build/**',
			'**/*.min.js',
		],
	},
];
