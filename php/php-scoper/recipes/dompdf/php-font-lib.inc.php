<?php declare( strict_types=1 );
/**
 * Returns the fixes php-font-lib needs to work under a prefix.
 *
 * @package DeepWebSolutions\Config
 */

return array(
	// Font::load() names its font classes relative to the FontLib namespace, such as "TrueType\\File", which php-scoper takes for global class names.
	'exclude-namespaces' => array( '/^(?:TrueType|OpenType|WOFF|EOT)$/i' ),
	'replacements'       => array(
		// getFontType() reads the font type from the second segment of its class name, which the prefix shifts.
		'src/FontLib/TrueType/File.php'    => array( 'return $class_parts[1];' => 'return $class_parts[\count($class_parts) - 2];' ),
		// The test names FontLib\glyf, a class no package declares, so it is always false; the global name keeps that meaning.
		'src/FontLib/AdobeFontMetrics.php' => array( '$glyf instanceof glyf' => '$glyf instanceof \FontLib\glyf' ),
	),
);
