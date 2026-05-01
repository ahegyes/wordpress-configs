<?php declare( strict_types = 1 );

use Isolated\Symfony\Component\Finder\Finder;

/**
 * Catalog-agnostic php-scoper base config. Returns a closure that reads
 * `scoping-exclusions.json` (written by CollectScopingStubs) from the project
 * root and builds the full php-scoper config, merging plugin overrides on top.
 *
 * `Psr\*` is always excluded — scoping it would give each package its own
 * incompatible copy of the standard interfaces, breaking cross-package interop.
 *
 * @param array{
 *     project_dir?: string,
 *     finders?: list<Finder>,
 *     exclude_classes?: list<string>,
 *     exclude_functions?: list<string>,
 *     exclude_namespaces?: list<string>,
 *     exclude_files?: list<string>,
 *     patchers?: list<callable>,
 * } $overrides
 *
 * @return array
 */
return static function ( array $overrides = array() ): array {
	$project_dir = $overrides['project_dir'] ?? getcwd();

	$exclusions_path = $project_dir . '/scoping-exclusions.json';
	$exclusions      = is_file( $exclusions_path )
		? json_decode( file_get_contents( $exclusions_path ), true, 512, JSON_THROW_ON_ERROR )
		: array( 'classes' => array(), 'functions' => array() );

	// `exclude-functions`/`exclude-classes` cover direct calls. Strings like
	// `function_exists('foo')` and `use` statements concatenated from strings
	// still slip through and get prefixed; this patcher strips those after.
	$reference_stripper = static function ( string $file_path, string $prefix, string $content ) use ( $exclusions ): string {
		foreach ( $exclusions['functions'] as $function ) {
			$content = str_replace( '\\' . $prefix . '\\' . $function . '(', '\\' . $function . '(', $content );
			$content = str_replace( "function_exists('" . $prefix . "\\\\" . $function, "function_exists('\\" . $function, $content );
		}
		foreach ( $exclusions['classes'] as $class ) {
			$content = str_replace( 'use ' . $prefix . '\\' . $class . ';', '', $content );
			$content = str_replace( '\\' . $prefix . '\\' . $class, '\\' . $class, $content );
		}

		return $content;
	};

	return array(
		'finders'            => $overrides['finders'] ?? array(),

		'exclude-namespaces' => array_merge(
			array( 'Psr' ),
			$overrides['exclude_namespaces'] ?? array()
		),

		'exclude-classes'    => array_merge(
			$exclusions['classes'],
			$overrides['exclude_classes'] ?? array()
		),
		'exclude-functions'  => array_merge(
			$exclusions['functions'],
			$overrides['exclude_functions'] ?? array()
		),
		'exclude-files'      => $overrides['exclude_files'] ?? array(),

		'patchers'           => array_merge(
			array( $reference_stripper ),
			$overrides['patchers'] ?? array()
		),
	);
};
