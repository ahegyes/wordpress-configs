/**
 * Load-smokes the four shared Node baselines against the installed toolchain —
 * the only behavioral check these exports get before a consumer's CI does.
 * Run from the repo root (`npm run lint:config`).
 */

import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

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
