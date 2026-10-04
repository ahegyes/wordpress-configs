/**
 * Load-smokes the five shared Node baselines against the installed toolchain —
 * the only behavioral check these exports get before a consumer's CI does.
 * Run from the repo root (`npm run lint:config`).
 */

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const require = createRequire( import.meta.url );
// Consumers lint through `wp-scripts lint-js`, which runs the ESLint @wordpress/scripts depends on; a
// bare `eslint` import resolves the older copy npm hoists for the ESLint plugins' peer ranges.
const { ESLint } = createRequire(
	require.resolve( '@wordpress/scripts/package.json' )
)( 'eslint' );
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

		// The test-unit glob is extension-scoped, so every script extension the base lints must
		// still get the test-unit rules, while a test snapshot or a JSON fixture must not be linted at all.
		for ( const extension of [
			'js',
			'jsx',
			'ts',
			'tsx',
			'mjs',
			'cjs',
			'mts',
			'cts',
		] ) {
			const config = await eslint.calculateConfigForFile(
				resolve(
					`tests/fixtures/node-config/probe.test.${ extension }`
				)
			);
			if ( ! config?.rules?.[ 'vitest/expect-expect' ] ) {
				throw new Error(
					`probe.test.${ extension } does not get the test-unit rules`
				);
			}
		}
		for ( const name of [ 'probe.test.js.snap', 'probe.test.json' ] ) {
			const config = await eslint.calculateConfigForFile(
				resolve( `tests/fixtures/node-config/${ name }` )
			);
			if ( undefined !== config ) {
				throw new Error( `${ name } is linted as a script` );
			}
		}
	},
] );

probes.push( [
	'stylelint.config.base.js',
	() => {
		const config = JSON.parse(
			execFileSync(
				'./node_modules/.bin/stylelint',
				[
					'--print-config',
					'tests/fixtures/node-config/probe.css',
					'--config',
					'node/stylelint.config.base.js',
				],
				{ stdio: 'pipe' }
			)
		);
		for ( const flag of [
			'reportDescriptionlessDisables',
			'reportInvalidScopeDisables',
			'reportNeedlessDisables',
		] ) {
			if ( true !== config[ flag ] ) {
				throw new Error( `${ flag } is not enabled` );
			}
		}
		if ( config.rules[ 'selector-class-pattern' ] ) {
			throw new Error( 'selector-class-pattern is not turned off' );
		}
	},
] );

probes.push( [
	'postcss.config.base.js',
	async () => {
		const factory = require( resolve( 'node/postcss.config.base.js' ) );
		const postcss = createRequire(
			require.resolve( '@wordpress/scripts/package.json' )
		)( 'postcss' );
		const css = readFileSync(
			'tests/fixtures/node-config/probe.css',
			'utf8'
		);
		const transform = async ( env ) =>
			(
				await postcss( factory( { env } ).plugins ).process( css, {
					from: undefined,
				} )
			).css;

		const production = await transform( 'production' );
		if ( production.includes( '/*' ) || production.includes( '\n' ) ) {
			throw new Error(
				`production CSS is not minified: ${ production }`
			);
		}
		const development = await transform( 'development' );
		if ( ! development.includes( '/*' ) ) {
			throw new Error(
				`development CSS lost its comment: ${ development }`
			);
		}
	},
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
	() => {
		// The @wordpress/scripts config reads both variables only while it is being required, so these
		// assertions catch a require moved to module scope, which pins every consumer to port 8889.
		const config = require( resolve( 'node/playwright.config.base.js' ) )( {
			port: 9999,
		} );
		if ( ! config.use.baseURL.includes( '9999' ) ) {
			throw new Error(
				`port did not reach use.baseURL (got ${ config.use.baseURL })`
			);
		}
		if ( '9999' !== config.webServer.port ) {
			throw new Error(
				`port did not reach webServer.port (got ${ config.webServer.port })`
			);
		}
		if ( 'npm run wp-env:start' !== config.webServer.command ) {
			throw new Error(
				`webServer.command is ${ config.webServer.command }`
			);
		}
		if ( ! config.outputDir.startsWith( 'tests/.cache/artifacts' ) ) {
			throw new Error( `outputDir is ${ config.outputDir }` );
		}
		if ( 'tests/e2e' !== config.testDir ) {
			throw new Error( `testDir is ${ config.testDir }` );
		}
	},
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
