<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * php-scoper partial for `ahegyes/wp-framework-*` packages. Auto-detects which
 * are installed in vendor; composer require dictates what gets scoped.
 */
return static function ( string $vendor_dir ): array {
	$packages = glob( $vendor_dir . '/ahegyes/wp-framework-*', GLOB_ONLYDIR );
	if ( empty( $packages ) ) {
		return array( 'finders' => array() );
	}

	$finder = Finder::create()
		->files()
		->ignoreVCS( true )
		->name( array( '*.php', 'LICENSE', 'composer.json' ) )
		->exclude( array( 'tests', 'changelog' ) );

	foreach ( $packages as $dir ) {
		$finder->in( $dir );
	}

	return array( 'finders' => array( $finder ) );
};
