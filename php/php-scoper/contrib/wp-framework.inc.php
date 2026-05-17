<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * Scoping partial for `ahegyes/wp-framework-*` packages. Auto-detects which
 * are installed in the vendor folder; composer require dictates what gets scoped.
 *
 * @throws \RuntimeException If glob() fails for the vendor directory.
 */
return static function ( string $vendor_dir ): array {
	$packages = \glob( $vendor_dir . '/ahegyes/wp-framework-*', GLOB_ONLYDIR );
	if ( false === $packages ) {
		throw new \RuntimeException( sprintf( 'glob() failed for %s', $vendor_dir ) );
	}

	if ( 0 === count( $packages ) ) {
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
