/**
 * Re-export this base directly as the consumer's postcss.config.js.
 */

const { createRequire } = require( 'node:module' );
const postcssPlugins = require( '@wordpress/postcss-plugins-preset' );

const cssnano = createRequire(
	require.resolve( '@wordpress/scripts/package.json' )
)( 'cssnano' );

// Any project PostCSS config replaces the @wordpress/scripts fallback, so production builds re-add its minifier here.
module.exports = ( { env } ) => ( {
	plugins:
		'production' === env
			? [
					...postcssPlugins,
					cssnano( {
						preset: [
							'default',
							{ discardComments: { removeAll: true } },
						],
					} ),
				]
			: postcssPlugins,
} );
