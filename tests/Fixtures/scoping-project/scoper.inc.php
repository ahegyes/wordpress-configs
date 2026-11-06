<?php declare( strict_types=1 );
/**
 * Returns the scoping project's php-scoper config.
 *
 * @package Acme
 */

return ( require Composer\InstalledVersions::getInstallPath( 'ahegyes/wordpress-configs' ) . '/php/php-scoper/scoper-base.inc.php' )( __DIR__, 'acme/commerce', 'acme/legacy' );
