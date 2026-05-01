<?php declare( strict_types = 1 );

use Isolated\Symfony\Component\Finder\Finder;

/**
 * php-scoper partial config for PHP-DI 7 + its transitive deps. Returns a
 * closure taking a vendor dir and producing a `{finders, exclude_files}`
 * pair the consuming plugin merges into its scoper-base overrides.
 *
 *     $base   = require '.../scoper-base.inc.php';
 *     $php_di = ( require '.../contrib/php-di.inc.php' )( __DIR__ . '/vendor' );
 *
 *     return $base( array(
 *         'project_dir'   => __DIR__,
 *         'finders'       => array_merge( $plugin_finders, $php_di['finders'] ),
 *         'exclude_files' => $php_di['exclude_files'],
 *     ) );
 *
 * Finders cover `php-di/*` and `laravel/serializable-closure/src`.
 *
 * `php-di/php-di/src/Compiler/Template.php` is skipped via `exclude_files` —
 * it's a raw PHP template (no top-level open tag, embedded short-form PHP tags)
 * rendered by the container compiler at runtime. Scoping injects a namespace
 * declaration mid-template and breaks generation; the template has no
 * namespace references that would need prefixing.
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
