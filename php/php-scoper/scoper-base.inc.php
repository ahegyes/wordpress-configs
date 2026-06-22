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
 *     exclude_files?: list<string>,
 *     patchers?: list<callable>,
 * } $overrides
 *
 * @throws \JsonException    If scoping-exclusions.json exists but cannot be parsed.
 * @throws \RuntimeException If scoping-exclusions.json exists but cannot be read.
 *
 * @return array
 */
return static function ( array $overrides = array() ): array {
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
	$exclusions['constants'] ??= array();

	// `exclude-functions`/`exclude-classes` cover direct calls. php-scoper still prefixes
	// excluded symbols referenced in string literals (e.g. `function_exists('foo')`) and in
	// `use` statements; this patcher strips the prefix off those references after scoping.
	$reference_stripper = static function ( string $file_path, string $prefix, string $content ) use ( $exclusions ): string {
		// Replace `$search` with `$replacement`, but only when not followed by another name char or
		// namespace separator. Prevents partial-name over-catch (e.g., excluded `Foo` matching `FooBar`)
		// and FQCN-segment over-catch (e.g., excluded `A\Foo` matching `A\Foo\Bar`).
		$strip = static function ( string $code, string $search, string $replacement ): string {
			return (string) \preg_replace(
				'/' . \preg_quote( $search, '/' ) . '(?![A-Za-z0-9_\\\\])/',
				\strtr(
					$replacement,
					array(
						'\\' => '\\\\',
						'$'  => '\\$',
					)
				),
				$code
			);
		};

		$apply_patches = static function ( string $code ) use ( $exclusions, $prefix, $strip ): string {
			foreach ( $exclusions['functions'] as $function ) {
				// Direct call: `\Prefix\func(` — anchored on the opening paren.
				$code = \str_replace( '\\' . $prefix . '\\' . $function . '(', '\\' . $function . '(', $code );
				// String form inside function_exists().
				$code = $strip( $code, "function_exists('" . $prefix . '\\\\' . $function, "function_exists('\\" . $function );
				// Function name as a plain string (e.g. callable for array_map).
				$code = $strip( $code, "'" . $prefix . '\\\\' . $function, "'\\" . $function );
			}
			foreach ( $exclusions['classes'] as $class ) {
				// 'use' statement, no alias — anchored on the trailing semicolon.
				$code = \str_replace( 'use ' . $prefix . '\\' . $class . ';', '', $code );
				// 'use ... as Alias;' form — anchored on the ` as ` keyword.
				$code = \str_replace( 'use ' . $prefix . '\\' . $class . ' as ', 'use \\' . $class . ' as ', $code );
				// Direct ref: leading-backslash, prefix, class.
				$code = $strip( $code, '\\' . $prefix . '\\' . $class, '\\' . $class );
				// String-typed class refs (class_exists, method_exists, is_a, ReflectionClass, etc.).
				$code = $strip( $code, "'" . $prefix . '\\\\' . $class, "'\\" . $class );
			}
			foreach ( $exclusions['constants'] as $constant ) {
				// String form inside defined().
				$code = $strip( $code, "defined('" . $prefix . '\\\\' . $constant, "defined('\\" . $constant );
				// Bare ref: leading-backslash, prefix, constant.
				$code = $strip( $code, '\\' . $prefix . '\\' . $constant, '\\' . $constant );
				// String-typed constant ref.
				$code = $strip( $code, "'" . $prefix . '\\\\' . $constant, "'\\" . $constant );
			}
			return $code;
		};

		// Tokenize and apply patches only to non-comment tokens — comments containing the prefix
		// pattern would otherwise get their text rewritten (cosmetic damage to documentation).
		$out    = '';
		$buffer = '';
		foreach ( \token_get_all( $content ) as $token ) {
			if ( \is_array( $token ) && \in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out   .= $apply_patches( $buffer );
				$out   .= $token[1];
				$buffer = '';
			} else {
				$buffer .= \is_array( $token ) ? $token[1] : $token;
			}
		}
		$out .= $apply_patches( $buffer );

		return $out;
	};

	return array(
		'finders'            => $overrides['finders'] ?? array(),

		'exclude-namespaces' => \array_merge(
			array( 'Psr' ),
			$overrides['exclude_namespaces'] ?? array()
		),

		'exclude-classes'    => \array_merge(
			$exclusions['classes'],
			$overrides['exclude_classes'] ?? array()
		),
		'exclude-functions'  => \array_merge(
			$exclusions['functions'],
			$overrides['exclude_functions'] ?? array()
		),
		'exclude-constants'  => \array_merge(
			$exclusions['constants'],
			$overrides['exclude_constants'] ?? array()
		),
		'exclude-files'      => $overrides['exclude_files'] ?? array(),

		'patchers'           => \array_merge(
			array( $reference_stripper ),
			$overrides['patchers'] ?? array()
		),
	);
};
