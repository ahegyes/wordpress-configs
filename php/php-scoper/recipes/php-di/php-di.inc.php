<?php declare( strict_types=1 );
/**
 * Returns the fixes PHP-DI needs to work under a prefix.
 *
 * @package WordPressConfigs
 */

return array(
	// PHP-DI renders this file as the raw template of a compiled container, which a prefixed namespace declaration would break.
	'exclude-files'      => array( 'src/Compiler/Template.php' ),
	// Lazy injection needs ocramius/proxy-manager, an optional dependency that stays whatever the project installs.
	'exclude-namespaces' => array( '/^ProxyManager(?:\\\\|$)/i' ),
);
