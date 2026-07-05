<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * Scoping partial for `ahegyes/wp-framework-*` packages. Auto-detects which
 * are installed in the vendor folder; composer require dictates what gets scoped.
 *
 * Framework CI owns source-shape enforcement: WPCS `WordPress.WP.I18n` with `text_domain`
 * pinned to the real package domains accepts plain literal `wp-framework-*` text domains in
 * gettext calls. This partial owns scope-time consequences: every constant string whose raw
 * value starts with `wp-framework-` is rewritten to the consumer's domain, and any reserved
 * occurrence outside that lint guarantee trips the scope run.
 *
 * The rewrite is deliberately position-agnostic. Framework source reserves plain
 * `wp-framework-*` literals for text domains (hooks and options use the `dws_` prefix), so a
 * plain reserved literal is a domain wherever it appears in the token stream. Comments stay
 * inert; heredoc/nowdoc/interpolated fragments, mid-string occurrences, and escape-obfuscated
 * occurrences fail because they sit outside the plain-literal guarantee. A non-plugin consumer
 * opts out explicitly with `"text-domain": false`; missing or invalid metadata throws.
 *
 * A second, always-on patcher guards the Action Scheduler surface: a scoped file that still
 * carries a `<prefix>\as_*` reference — as a name token, a constant-string reference, or inside
 * a heredoc/nowdoc/interpolated string — fails the run. The `as_*` family is host-provided
 * (never bundled), excluded by scoper-base.inc.php's `/^as_/` `exclude-functions` regex; a
 * prefixed residue means the base config did not run and the call would be a runtime fatal.
 *
 * Callers (a consumer's `scoper.inc.php`) must forward the returned `patchers` into the base
 * config's `patchers` override, or neither the rewrite nor the guard ever runs.
 *
 * @throws \JsonException    If the consumer composer.json exists but cannot be parsed.
 * @throws \RuntimeException If glob() fails, the consumer composer.json is missing or cannot be read, or extra.text-domain is missing, invalid, or not an explicit opt-out.
 */
return static function ( string $vendor_dir, string $project_dir = '' ): array {
	$packages = \glob( $vendor_dir . '/ahegyes/wp-framework-*', GLOB_ONLYDIR );
	if ( false === $packages ) {
		throw new \RuntimeException( sprintf( 'glob() failed for %s', $vendor_dir ) );
	}

	if ( 0 === count( $packages ) ) {
		return array(
			'finders'  => array(),
			'patchers' => array(),
		);
	}

	$finder = Finder::create()
		->files()
		->ignoreVCS( true )
		->name( array( '*.php', 'LICENSE', 'composer.json' ) )
		->exclude( array( 'tests', 'changelog' ) );

	foreach ( $packages as $dir ) {
		$finder->in( $dir );
	}

	// Resolve the consumer's text domain from its composer.json (parent of vendor when not given).
	// Framework packages carry translatable strings, so the metadata is mandatory: a missing file
	// or a missing/invalid domain fails the scoping run rather than silently shipping framework
	// `wp-framework-*` domains no consumer catalog covers. `"text-domain": false` is the explicit
	// opt-out for non-plugin consumers that genuinely have no domain.
	$project_dir   = '' !== $project_dir ? $project_dir : \dirname( $vendor_dir );
	$patchers      = array();
	$composer_path = $project_dir . '/composer.json';
	if ( ! \is_file( $composer_path ) ) {
		throw new \RuntimeException( \sprintf( 'Framework packages are installed but no composer.json exists at %s — cannot resolve the consumer text domain for the framework-string rewrite.', $composer_path ) );
	}

	$composer_contents = file_get_contents( $composer_path ) ?: throw new \RuntimeException( sprintf( 'Could not read %s', $composer_path ) );
	$composer_config   = json_decode( $composer_contents, true, flags: JSON_THROW_ON_ERROR );

	$target_text_domain = $composer_config['extra']['text-domain'] ?? null;
	if ( false !== $target_text_domain ) {
		if ( ! \is_string( $target_text_domain ) || '' === $target_text_domain ) {
			throw new \RuntimeException( \sprintf( 'Framework packages are installed but "extra.text-domain" is missing or empty in %s. Declare the consumer text domain, or set it to false to opt out of the framework-string rewrite (non-plugin consumers only).', $composer_path ) );
		}
		if ( 1 !== \preg_match( '/^[a-z0-9][a-z0-9-]*$/', $target_text_domain ) ) {
			throw new \RuntimeException( \sprintf( 'Invalid "extra.text-domain" %s in %s — expected a lowercase slug (a-z, 0-9, hyphen).', \var_export( $target_text_domain, true ), $composer_path ) );
		}

		$framework_domain_prefix = 'wp-framework-';
		$patchers[]              = static function ( string $file_path, string $prefix, string $content ) use ( $framework_domain_prefix, $target_text_domain ): string {
			$replacement = \var_export( $target_text_domain, true );

			// The raw body of a constant-string token, after the b-prefix and quotes.
			$raw_value = static function ( string $lexeme ): string {
				if ( '' !== $lexeme && ( 'b' === $lexeme[0] || 'B' === $lexeme[0] ) ) {
					$lexeme = \substr( $lexeme, 1 );
				}
				return \substr( $lexeme, 1, -1 );
			};

			$decoded_value = static function ( string $lexeme ): string {
				if ( '' !== $lexeme && ( 'b' === $lexeme[0] || 'B' === $lexeme[0] ) ) {
					$lexeme = \substr( $lexeme, 1 );
				}

				$quote = $lexeme[0] ?? "'";
				$body  = \substr( $lexeme, 1, -1 );
				if ( '\'' === $quote ) {
					return \str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $body );
				}

				// stripcslashes approximates PHP's double-quote decoding — exact semantics are
				// not required for a fail-loud tripwire.
				return \stripcslashes( $body );
			};

			$throw_reserved_occurrence = static function ( string $where ) use ( $file_path ): void {
				throw new \RuntimeException(
					\sprintf( 'Reserved `wp-framework-` occurrence %s in %s is outside the framework lint guarantee: WPCS I18n text_domain guarantees plain reserved literals, so scope-time rewriting only accepts whole raw `wp-framework-*` constant strings.', $where, $file_path )
				);
			};

			$out = '';
			foreach ( \token_get_all( $content ) as $token ) {
				if ( ! \is_array( $token ) ) {
					$out .= $token;
					continue;
				}

				if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$raw = $raw_value( $token[1] );
					if ( \str_starts_with( $raw, $framework_domain_prefix ) ) {
						$out .= $replacement;
						continue;
					}

					if ( \str_contains( $decoded_value( $token[1] ), $framework_domain_prefix ) ) {
						$throw_reserved_occurrence( \sprintf( 'inside constant string %s', $token[1] ) );
					}
				} elseif ( T_ENCAPSED_AND_WHITESPACE === $token[0]
					&& ( \str_contains( $token[1], $framework_domain_prefix ) || \str_contains( \stripcslashes( $token[1] ), $framework_domain_prefix ) )
				) {
					$throw_reserved_occurrence( 'inside a heredoc/nowdoc/interpolated fragment' );
				}

				$out .= $token[1];
			}

			return $out;
		};
	}

	// Guard against a prefixed Action Scheduler reference surviving into the scoped output. The
	// `as_*` functions are host symbols excluded by scoper-base.inc.php's `/^as_/` regex; if the
	// base config is not composed, php-scoper prefixes the calls and the break only surfaces as a
	// runtime fatal — fail the scope run instead. Token-aware: name tokens catch rewritten calls,
	// constant-string tokens catch rewritten `function_exists`-style references, and
	// heredoc/interpolated fragments are checked on their DECODED value (they decode escapes at
	// runtime; a nowdoc body stays raw); comments never match. Matching is case-insensitive —
	// PHP resolves function and namespace names case-insensitively, so \Prefix\AS_… is the same
	// runtime fatal.
	$patchers[] = static function ( string $file_path, string $prefix, string $content ): string {
		$needle    = $prefix . '\\as_';
		$in_nowdoc = false;

		foreach ( \token_get_all( $content ) as $token ) {
			if ( ! \is_array( $token ) ) {
				continue;
			}

			$haystack = null;
			if ( T_START_HEREDOC === $token[0] ) {
				$in_nowdoc = \str_contains( $token[1], "'" );
			} elseif ( T_END_HEREDOC === $token[0] ) {
				$in_nowdoc = false;
			} elseif ( T_NAME_FULLY_QUALIFIED === $token[0] || T_NAME_QUALIFIED === $token[0] ) {
				$haystack = \ltrim( $token[1], '\\' );
			} elseif ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				// Constant strings carry php-scoper's doubled backslashes — normalise those.
				$haystack = \str_replace( '\\\\', '\\', $token[1] );
			} elseif ( T_ENCAPSED_AND_WHITESPACE === $token[0] ) {
				// Raw first: PHP keeps unknown escapes, so a verbatim `\as_` fragment survives —
				// stripcslashes' C semantics would eat that backslash. The decoded pass only
				// widens the net to hex/octal-obfuscated fragments.
				$haystack = $in_nowdoc || false !== \stripos( $token[1], $needle )
					? $token[1]
					: \stripcslashes( $token[1] );
			}

			if ( null === $haystack ) {
				continue;
			}

			$position = \stripos( $haystack, $needle );
			if ( false === $position ) {
				continue;
			}

			\preg_match( '/[A-Za-z0-9_\\\\]*/', \substr( $haystack, $position + \strlen( $needle ) ), $suffix_match );
			$reference = \substr( $haystack, $position, \strlen( $needle ) ) . ( $suffix_match[0] ?? '' );

			throw new \RuntimeException(
				\sprintf( 'Prefixed Action Scheduler reference "%s" in %s — the scope run is not composing scoper-base.inc.php, whose /^as_/ exclude-functions regex prevents php-scoper from prefixing host Action Scheduler calls. Check that scoper.inc.php loads the base config before merging contrib partials.', $reference, $file_path )
			);
		}

		return $content;
	};

	return array(
		'finders'  => array( $finder ),
		'patchers' => $patchers,
	);
};
