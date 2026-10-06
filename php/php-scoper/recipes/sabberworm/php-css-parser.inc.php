<?php declare( strict_types=1 );
/**
 * Returns the fixes php-css-parser needs to work under a prefix.
 *
 * @package DeepWebSolutions\Config
 */

return array(
	// ParserState matches the escape sequence '\\r\\n', which php-scoper takes for the class r\n.
	'exclude-namespaces' => array( '/^r$/i' ),
);
