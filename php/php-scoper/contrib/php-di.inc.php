<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * Scoping partial for PHP-DI 7 + transitive deps (laravel/serializable-closure).
 *
 * `php-di/php-di/src/Compiler/Template.php` is excluded — it's a raw PHP template
 * (no open tag, embedded short-form tags) rendered at runtime; injecting a
 * namespace declaration mid-template breaks generation, and the template has no
 * symbols needing prefixing anyway.
 */
return static function ( string $vendor_dir ): array {
	$finder_paths = array();
	foreach ( array( $vendor_dir . '/php-di', $vendor_dir . '/laravel/serializable-closure' ) as $path ) {
		if ( \is_dir( $path ) ) {
			$finder_paths[] = $path;
		}
	}

	if ( array() === $finder_paths ) {
		return array(
			'finders'       => array(),
			'exclude_files' => array(),
		);
	}

	$finder = Finder::create()
		->files()
		->ignoreVCS( true )
		->name( array( '*.php', 'LICENSE', 'LICENSE.md', 'composer.json' ) );

	foreach ( $finder_paths as $path ) {
		$finder->in( $path );
	}

	return array(
		'finders'       => array(
			$finder,
		),
		'exclude_files' => array(
			$vendor_dir . '/php-di/php-di/src/Compiler/Template.php',
		),
	);
};
