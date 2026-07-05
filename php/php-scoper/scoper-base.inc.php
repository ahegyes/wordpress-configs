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
		$contents   = file_get_contents( $exclusions_path ) ?: throw new \RuntimeException( sprintf( 'Could not read %s', $exclusions_path ) );
		$exclusions = json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );
	} else {
		$exclusions = array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array(),
		);
	}
	$exclusions['classes']   ??= array();
	$exclusions['functions'] ??= array();
	$exclusions['constants'] ??= array();

	// Merge consumer overrides into the exclusion set BEFORE building the patcher, so a
	// consumer's own `exclude_*` symbols are stripped from string/`use` references too — not
	// only from the php-scoper `exclude-*` config that governs direct prefixing.
	$exclude_classes   = \array_merge( $exclusions['classes'], $overrides['exclude_classes'] ?? array() );
	$exclude_functions = \array_merge( $exclusions['functions'], $overrides['exclude_functions'] ?? array() );
	$exclude_constants = \array_merge( $exclusions['constants'], $overrides['exclude_constants'] ?? array() );

	// php-scoper's `exclude-*` config stops it prefixing direct references, but it still
	// prefixes excluded symbols named in `use` statements and string literals
	// (`function_exists('foo')`, `class_exists("Foo")`, `defined('BAR')`, callables). One
	// flat lookup of every excluded symbol drives the patcher that restores those references.
	$excluded_symbols = \array_flip( \array_merge( $exclude_classes, $exclude_functions, $exclude_constants ) );

	// Action Scheduler's `as_*` API is host-provided by WooCommerce or the standalone plugin,
	// so scoped code must never prefix it. A regex covers the whole family, including
	// functions added upstream later.
	$host_function_exclusions = array( '/^as_/' );

	// Token-aware reference stripper. Working on the token stream rather than raw text makes it
	// correct for any prefix depth (php-scoper writes multi-segment prefixes with doubled
	// backslashes inside string literals, which text matching has to second-guess) and both
	// quote styles, and visits every token exactly once.
	$reference_stripper = static function ( string $file_path, string $prefix, string $content ) use ( $excluded_symbols ): string {
		$needle = $prefix . '\\';

		// Restores `\<symbol>` from a `<prefix>\<symbol>` name when <symbol> is an excluded
		// global. The post-prefix remainder must match an exclusion EXACTLY, so a longer name
		// that merely starts with an excluded one stays prefixed (`A\Foo` never catches
		// `A\Foo\Bar`; `WP_Post` never catches `WP_Post_Type`).
		$restore = static function ( string $qualified ) use ( $needle, $excluded_symbols ): ?string {
			$bare = \ltrim( $qualified, '\\' );
			if ( ! \str_starts_with( $bare, $needle ) ) {
				return null;
			}
			$symbol = \substr( $bare, \strlen( $needle ) );
			return isset( $excluded_symbols[ $symbol ] ) ? '\\' . $symbol : null;
		};

		$out               = '';
		$in_namespace_decl = false;
		foreach ( \token_get_all( $content ) as $token ) {
			if ( \is_string( $token ) ) {
				// `;` or `{` closes a `namespace …` declaration.
				if ( $in_namespace_decl && ( ';' === $token || '{' === $token ) ) {
					$in_namespace_decl = false;
				}
				$out .= $token;
				continue;
			}

			$id   = $token[0];
			$text = $token[1];

			// Comments are never rewritten — a prefix pattern in prose stays verbatim.
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				$out .= $text;
				continue;
			}

			// A namespace declaration's name is the scoped file's OWN namespace, not a reference
			// to an excluded global — and a leading backslash there is a parse error
			// (`namespace \A\Foo;`). Leave the declaration's name token untouched.
			if ( T_NAMESPACE === $id ) {
				$in_namespace_decl = true;
				$out              .= $text;
				continue;
			}

			// Code references: `\Prefix\Foo` (fully qualified) and `Prefix\Foo` (e.g. a `use`
			// target). Rewriting the resolved name token turns `use Prefix\Foo;` into
			// `use \Foo;` and `use Prefix\Foo as Alias;` into `use \Foo as Alias;` — restoring
			// the global import rather than deleting it, so unqualified body references resolve.
			// (Grouped imports `use Prefix\{A, B};` tokenise the prefix separately and are left
			// as-is.)
			if ( ( T_NAME_FULLY_QUALIFIED === $id || T_NAME_QUALIFIED === $id ) && ! $in_namespace_decl ) {
				$out .= $restore( $text ) ?? $text;
				continue;
			}

			// String-literal references. Decoding handles either quote style and the doubled
			// backslash php-scoper writes between prefix segments; a `Class::member` callable or
			// class-constant string restores only the class portion. Matches re-emit single-quoted
			// (symbol characters never need single-quote escaping).
			if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
				$quote = $text[0];
				if ( '\'' === $quote || '"' === $quote ) {
					$value     = \str_replace( '\\\\', '\\', \substr( $text, 1, -1 ) );
					$separator = \strpos( $value, '::' );
					$symbol    = false === $separator ? $value : \substr( $value, 0, $separator );
					$restored  = $restore( $symbol );
					if ( null !== $restored ) {
						$suffix = false === $separator ? '' : \substr( $value, $separator );
						$out   .= '\'' . $restored . $suffix . '\'';
						continue;
					}
				}
			}

			$out .= $text;
		}

		return $out;
	};

	return array(
		'finders'            => $overrides['finders'] ?? array(),

		'exclude-namespaces' => \array_merge(
			array( 'Psr' ),
			$overrides['exclude_namespaces'] ?? array()
		),

		'exclude-classes'    => $exclude_classes,
		'exclude-functions'  => \array_merge( $host_function_exclusions, $exclude_functions ),
		'exclude-constants'  => $exclude_constants,
		'exclude-files'      => $overrides['exclude_files'] ?? array(),

		'patchers'           => \array_merge(
			array( $reference_stripper ),
			$overrides['patchers'] ?? array()
		),
	);
};
