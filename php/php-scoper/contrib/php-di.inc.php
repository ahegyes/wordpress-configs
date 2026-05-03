<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * php-scoper partial for PHP-DI 7 + transitive deps (laravel/serializable-closure).
 *
 * `php-di/php-di/src/Compiler/Template.php` is excluded — it's a raw PHP template
 * (no open tag, embedded short-form tags) rendered at runtime; injecting a
 * namespace declaration mid-template breaks generation, and the template has no
 * symbols needing prefixing anyway.
 */
return static function ( string $vendor_dir ): array {
	return array(
		'finders'       => array(
			Finder::create()
				->files()
				->ignoreVCS( true )
				->name( array( '*.php', 'LICENSE', 'LICENSE.md', 'composer.json' ) )
				->in( $vendor_dir . '/php-di' )
				->in( $vendor_dir . '/laravel/serializable-closure' ),
		),
		'exclude_files' => array(
			$vendor_dir . '/php-di/php-di/src/Compiler/Template.php',
		),
	);
};
