/**
 * Re-export this base directly as the consumer's postcss.config.js.
 */

const postcssPlugins = require( '@wordpress/postcss-plugins-preset' );

module.exports = ( ctx ) => {
	const isDevelopment = 'development' === ctx.env;

	return {
		map: {
			inline: isDevelopment,
			annotation: true,
		},
		plugins: [ ...postcssPlugins ],
	};
};
