<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * Base scoping config. Reads `scoping-exclusions.json` (written by
 * CollectScopingStubs) and merges plugin overrides on top. `Psr\*` always
 * excluded — scoping it would break cross-package interop on shared interfaces.
 *
 * @param array{
 *     project_dir?: string,
 *     finders?: list<Finder>,
 *     exclude_classes?: list<string>,
 *     exclude_functions?: list<string>,
 *     exclude_namespaces?: list<string>,
 *     exclude_constants?: list<string>,
 *     exclude_files?: list<string>,
 *     patchers?: list<callable>,
 * } $overrides
 *
 * @throws \InvalidArgumentException If $overrides carries a key outside the documented set.
 * @throws \JsonException            If scoping-exclusions.json exists but cannot be parsed.
 * @throws \RuntimeException         If scoping-exclusions.json exists but cannot be read.
 *
 * @return array
 */
return static function ( array $overrides = array() ): array {
	// Reject unknown override keys loudly: php-scoper's own config keys are hyphenated
	// (`exclude-classes`), so a consumer reaching for that spelling — or a typo — would
	// otherwise be silently dropped and scope a symbol it meant to exclude.
	$known_keys   = array(
		'project_dir',
		'finders',
		'exclude_classes',
		'exclude_functions',
		'exclude_namespaces',
		'exclude_constants',
		'exclude_files',
		'patchers',
	);
	$unknown_keys = \array_diff( \array_keys( $overrides ), $known_keys );
	if ( array() !== $unknown_keys ) {
		throw new \InvalidArgumentException(
			\sprintf(
				'Unknown scoper override key(s): %s. Valid keys: %s. Hyphenated forms (e.g. "exclude-classes") are php-scoper output, not override keys.',
				\implode( ', ', $unknown_keys ),
				\implode( ', ', $known_keys )
			)
		);
	}

	$project_dir = $overrides['project_dir'] ?? \getcwd();

	$exclusions_path = $project_dir . '/scoping-exclusions.json';
	if ( \is_file( $exclusions_path ) ) {
		$contents = file_get_contents( $exclusions_path );
		if ( false === $contents ) {
			throw new \RuntimeException( sprintf( 'Could not read %s', $exclusions_path ) );
		}
		$exclusions = json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );
	} else {
		$exclusions = array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array(),
		);
	}
	// json_decode does not throw on valid scalar JSON ("foo", 42, true), which would then fatal on
	// the array access below with a confusing engine error rather than the documented RuntimeException.
	if ( ! \is_array( $exclusions ) ) {
		throw new \RuntimeException(
			sprintf( 'scoping-exclusions.json must decode to a JSON object; got %s.', \get_debug_type( $exclusions ) )
		);
	}
	$exclusions['classes']   ??= array();
	$exclusions['functions'] ??= array();
	$exclusions['constants'] ??= array();

	// Merge consumer overrides into the exclusion set so a consumer's own `exclude_*` symbols
	// join the php-scoper `exclude-*` config that governs prefixing.
	$exclude_classes   = \array_merge( $exclusions['classes'], $overrides['exclude_classes'] ?? array() );
	$exclude_functions = \array_merge( $exclusions['functions'], $overrides['exclude_functions'] ?? array() );
	$exclude_constants = \array_merge( $exclusions['constants'], $overrides['exclude_constants'] ?? array() );

	// Action Scheduler's `as_*` API is host-provided by WooCommerce or the standalone plugin,
	// so scoped code must never prefix it. A regex covers the whole family, including
	// functions added upstream later.
	$host_function_exclusions = array( '/^as_/' );

	return array(
		'finders'            => $overrides['finders'] ?? array(),

		// Anchored regex, not the bare literal 'Psr': php-scoper matches a plain namespace string
		// by case-insensitive substring, so 'Psr' would also leave any namespace merely containing
		// "psr" (e.g. `Nyholm\Psr7`, `GuzzleHttp\Psr7`) unprefixed — silently breaking isolation for
		// bundled PSR-7 implementations. The regex excludes only the real `Psr\*` root.
		'exclude-namespaces' => \array_merge(
			array( '/^Psr(?:\\\\|$)/i' ),
			$overrides['exclude_namespaces'] ?? array()
		),

		'exclude-classes'    => $exclude_classes,
		'exclude-functions'  => \array_merge( $host_function_exclusions, $exclude_functions ),
		'exclude-constants'  => $exclude_constants,
		'exclude-files'      => $overrides['exclude_files'] ?? array(),

		'patchers'           => $overrides['patchers'] ?? array(),
	);
};
