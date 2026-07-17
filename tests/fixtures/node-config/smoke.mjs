/**
 * Load-smokes the four shared Node baselines against the installed toolchain —
 * the only behavioral check these exports get before a consumer's CI does.
 * Run from the repo root (`npm run lint:config`).
 */

import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
// eslint-disable-next-line import/no-extraneous-dependencies -- provided transitively by @wordpress/scripts; used here only to load-smoke the shared ESLint baseline.
import { ESLint } from 'eslint';

const require = createRequire( import.meta.url );
const probes = [];

probes.push( [
	'eslint.config.base.mjs',
	async () => {
		const base = await import(
			pathToFileURL( resolve( 'node/eslint.config.base.mjs' ) )
		);
		if ( ! Array.isArray( base.default ) || 0 === base.default.length ) {
			throw new Error( 'did not export a non-empty flat-config array' );
		}

		// Lint one probe per config scope so a broken rule or plugin reference in ANY entry surfaces
		// here rather than only in a consumer's CI: an ESLint flat-config entry with a `files` glob
		// is applied (and its rules resolved) only to a matching path, so cover the unscoped
		// baseline plus the test-unit and test-playwright globs.
		const eslint = new ESLint( {
			overrideConfigFile: resolve( 'node/eslint.config.base.mjs' ),
		} );
		const scopedProbePaths = [
			'tests/fixtures/node-config/probe.js', // recommended baseline (unscoped rules)
			'tests/fixtures/node-config/probe.ts', // TS recommended entries (ts/tsx/mts/cts)
			'tests/fixtures/node-config/probe.tsx',
			'tests/fixtures/node-config/probe.mts',
			'tests/fixtures/node-config/probe.cts',
			'tests/fixtures/node-config/probe.test.js', // test-unit files glob
			'tests/e2e/probe.js', // test-playwright files glob
		];
		for ( const probePath of scopedProbePaths ) {
			await eslint.lintText( 'const probe = 42;\n', {
				filePath: resolve( probePath ),
			} );
		}
	},
] );

probes.push( [
	'stylelint.config.base.js',
	() =>
		execFileSync(
			'./node_modules/.bin/stylelint',
			[
				'--print-config',
				'tests/fixtures/node-config/probe.css',
				'--config',
				'node/stylelint.config.base.js',
			],
			{ stdio: 'pipe' }
		),
] );

probes.push( [
	'tsconfig.base.json',
	() =>
		execFileSync(
			'./node_modules/.bin/tsc',
			[ '--noEmit', '-p', 'tests/fixtures/node-config/tsconfig.json' ],
			{ stdio: 'pipe' }
		),
] );

probes.push( [
	'playwright.config.base.js',
	() => require( resolve( 'node/playwright.config.base.js' ) ),
] );

let failed = false;
for ( const [ name, probe ] of probes ) {
	try {
		await probe();
	} catch ( error ) {
		failed = true;
		console.error( `✖ ${ name }: ${ error.message }` );
		if ( error.stderr?.length ) {
			console.error( String( error.stderr ) );
		}
	}
}
if ( failed ) {
	process.exit( 1 );
}
