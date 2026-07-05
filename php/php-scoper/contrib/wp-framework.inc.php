<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * Scoping partial for `ahegyes/wp-framework-*` packages. Auto-detects which
 * are installed in the vendor folder; composer require dictates what gets scoped.
 *
 * When framework packages are present, the consumer's `composer.json` (at `$project_dir`,
 * defaulting to the parent of `$vendor_dir`) MUST declare `extra.text-domain` — the partial
 * returns a patcher that rewrites the reserved `wp-framework-*` source text domains (e.g.
 * `wp-framework-bootstrap`) to the consumer's domain at scope time, so framework strings ship
 * under the consumer's domain. The rewrite follows the PLAIN-ARGUMENT RULE: it happens only
 * when the DOMAIN argument of a gettext call (per-function position — `__` 2nd, `_x` 3rd, `_n`
 * 4th, `_nx` 5th, …) consists of exactly one plain constant string between its delimiters.
 * Reserved literals everywhere else fail the scope run: outside a domain position
 * (message/context arguments included), inside a compound expression at a domain position
 * (concatenation, ternary, arrow-fn — a compound with no reserved participant is left alone,
 * so a scoped third-party `__()` cannot fail the run), in a gettext call using named arguments
 * (labels defeat positional detection), escape-obfuscated at a domain position, and — as a
 * DELIBERATELY stricter substring rule, since these can never be rewritten at all — any
 * `wp-framework-` occurrence inside a heredoc/nowdoc or interpolated double-quoted string.
 * Throw checks match on the RUNTIME value: constant strings are decoded per quote style
 * (b-prefix stripped, escapes resolved), heredoc/interpolated fragments decode their escapes,
 * and a nowdoc body — which PHP never decodes — is checked raw. An escape-obfuscated reserved
 * literal cannot slip through. A non-plugin consumer (e.g. a test fixture with no translation
 * catalog) opts out explicitly with `"text-domain": false`; missing or invalid metadata throws.
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

	// Decodes double-quote-context escape sequences (\x/\X hex, \u{}, octal with PHP's
	// overflow-modulo-256 semantics, the control escapes; an unknown escape keeps its
	// backslash, matching PHP). Shared by both patchers: double-quoted constant strings,
	// interpolated-string fragments, and heredoc bodies all decode these at runtime, so
	// reserved-literal checks must match on the decoded value. The & 0xFF masks bound every
	// chr() argument to a byte.
	$decode_escapes = static function ( string $body ): string {
		return (string) \preg_replace_callback(
			'/\\\\(?:u\{([0-9A-Fa-f]+)\}|[xX]([0-9A-Fa-f]{1,2})|([0-7]{1,3})|(.))/s',
			static function ( array $matches ): string {
				if ( '' !== $matches[1] ) {
					$codepoint = (int) \hexdec( $matches[1] );
					if ( $codepoint < 0x80 ) {
						return \chr( $codepoint & 0x7F );
					}
					if ( $codepoint < 0x800 ) {
						return \chr( ( 0xC0 | $codepoint >> 6 ) & 0xFF ) . \chr( ( 0x80 | $codepoint & 0x3F ) & 0xFF );
					}
					if ( $codepoint < 0x10000 ) {
						return \chr( ( 0xE0 | $codepoint >> 12 ) & 0xFF ) . \chr( ( 0x80 | $codepoint >> 6 & 0x3F ) & 0xFF ) . \chr( ( 0x80 | $codepoint & 0x3F ) & 0xFF );
					}
					return \chr( ( 0xF0 | $codepoint >> 18 ) & 0xFF ) . \chr( ( 0x80 | $codepoint >> 12 & 0x3F ) & 0xFF ) . \chr( ( 0x80 | $codepoint >> 6 & 0x3F ) & 0xFF ) . \chr( ( 0x80 | $codepoint & 0x3F ) & 0xFF );
				}
				if ( '' !== ( $matches[2] ?? '' ) ) {
					return \chr( (int) \hexdec( $matches[2] ) & 0xFF );
				}
				if ( '' !== ( $matches[3] ?? '' ) ) {
					return \chr( (int) \octdec( $matches[3] ) & 0xFF );
				}
				$known    = array(
					'n'  => "\n",
					't'  => "\t",
					'r'  => "\r",
					'v'  => "\v",
					'f'  => "\f",
					'e'  => "\e",
					'\\' => '\\',
					'$'  => '$',
					'"'  => '"',
				);
				$fallback = $matches[4] ?? '';
				return $known[ $fallback ] ?? '\\' . $fallback;
			},
			$body
		);
	};

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

		// Rewrites the reserved `wp-framework-*` textdomains to the consumer's domain under the
		// plain-argument rule: only when the DOMAIN argument of a gettext call is exactly one
		// constant string between its delimiters, tracked via a call-frame stack over the token
		// stream (per-frame argument counting; commas inside nested calls, array literals, and
		// closure bodies belong to their own frame or bracket level). Comments are untouched and
		// non-PHP finder files (composer.json, LICENSE) tokenize as a single inert T_INLINE_HTML
		// token. Any other reserved literal throws — out of position, in a compound domain
		// expression (only when a reserved literal participates), in a named-arguments gettext
		// call, escape-obfuscated at a domain position, or inside encapsed strings: the framework
		// reserves that literal space for text domains (hooks and options use the `dws_` prefix),
		// so each of those shapes is either a convention violation or a new gettext wrapper this
		// position map must learn — both need a human, not a silent rewrite.
		$gettext_domain_positions = array(
			'__'                      => 2,
			'_e'                      => 2,
			'esc_attr__'              => 2,
			'esc_attr_e'              => 2,
			'esc_html__'              => 2,
			'esc_html_e'              => 2,
			'translate'               => 2,
			'_x'                      => 3,
			'_ex'                     => 3,
			'esc_attr_x'              => 3,
			'esc_html_x'              => 3,
			'_n_noop'                 => 3,
			'translate_nooped_plural' => 3,
			'_n'                      => 4,
			'_nx_noop'                => 4,
			'_nx'                     => 5,
		);

		$framework_domain_prefix = 'wp-framework-';
		$patchers[]              = static function ( string $file_path, string $prefix, string $content ) use ( $framework_domain_prefix, $target_text_domain, $gettext_domain_positions, $decode_escapes ): string {
			$replacement   = \var_export( $target_text_domain, true );
			$tokens        = \token_get_all( $content );
			$token_count   = \count( $tokens );
			$insignificant = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

			// The next non-whitespace/comment token after $index (null at end of stream) — needed
			// for the plain-argument test (the literal must run up against `,` or `)`).
			$next_significant = static function ( int $index ) use ( $tokens, $token_count, $insignificant ): array|string|null {
				for ( $j = $index + 1; $j < $token_count; $j++ ) {
					if ( \is_array( $tokens[ $j ] ) && \in_array( $tokens[ $j ][0], $insignificant, true ) ) {
						continue;
					}
					return $tokens[ $j ];
				}
				return null;
			};

			// The string VALUE of a constant-string token: strips a binary `b`/`B` prefix and the
			// quotes, then decodes escape sequences per quote style (single-quoted knows only \\
			// and \'; double-quoted delegates to the shared escape decoder). The throw checks
			// match on this decoded value so an escape-obfuscated reserved literal
			// ("\x77p-framework-…") cannot slip by.
			$decoded_value = static function ( string $lexeme ) use ( $decode_escapes ): string {
				if ( '' !== $lexeme && ( 'b' === $lexeme[0] || 'B' === $lexeme[0] ) ) {
					$lexeme = \substr( $lexeme, 1 );
				}
				$quote = $lexeme[0] ?? "'";
				$body  = \substr( $lexeme, 1, -1 );
				if ( '\'' === $quote ) {
					return \str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $body );
				}

				return $decode_escapes( $body );
			};

			// The raw (undecoded) body of a constant-string token, after the b-prefix and quotes.
			$raw_value = static function ( string $lexeme ): string {
				if ( '' !== $lexeme && ( 'b' === $lexeme[0] || 'B' === $lexeme[0] ) ) {
					$lexeme = \substr( $lexeme, 1 );
				}
				return \substr( $lexeme, 1, -1 );
			};

			// Whether a tracked gettext call uses ANY named-argument label among its own arguments
			// (paren depth 1): scans from the call's opening paren to its close. A label is a
			// T_STRING that follows `(` or `,` and is itself followed by `:` — a ternary's middle
			// operand follows `?`, a static call's class precedes `::` (one token), so neither
			// matches. With labels present, positional domain semantics are unreliable.
			$call_uses_named_arguments = static function ( int $open_paren_index ) use ( $tokens, $token_count, $insignificant, $next_significant ): bool {
				$depth            = 1;
				$prev_significant = '(';
				for ( $j = $open_paren_index + 1; $j < $token_count && $depth > 0; $j++ ) {
					$candidate = $tokens[ $j ];
					if ( \is_array( $candidate ) && \in_array( $candidate[0], $insignificant, true ) ) {
						continue;
					}
					if ( '(' === $candidate ) {
						++$depth;
					} elseif ( ')' === $candidate ) {
						--$depth;
					} elseif ( 1 === $depth
						&& \is_array( $candidate )
						&& T_STRING === $candidate[0]
						&& ( '(' === $prev_significant || ',' === $prev_significant )
						&& ':' === $next_significant( $j ) ) {
						return true;
					}
					$prev_significant = $candidate;
				}
				return false;
			};

			$out         = '';
			$previous    = null;
			$penultimate = null;

			// The active call frame, held in scalars so the counters stay plain ints; entering a
			// nested call suspends the current frame onto the stack, `)` restores it. `$in_call`
			// distinguishes "inside some call" from top-level code. `$in_nowdoc` marks a nowdoc
			// body (opener carries a quoted label), whose fragments do NOT decode at runtime.
			$frame_stack = array();
			$in_call     = false;
			$domain_pos  = null;
			$has_named   = false;
			$arg_index   = 0;
			$square      = 0;
			$curly       = 0;
			$in_nowdoc   = false;

			for ( $i = 0; $i < $token_count; $i++ ) {
				$token = $tokens[ $i ];

				if ( '(' === $token ) {
					// The callee is the significant name token right before the paren — unless that
					// name is a method/static/constructor target (->, ?->, ::, new) or a function
					// DECLARATION name (function __(…) declares, it does not call), none of which is
					// a WP gettext call; anything else (control structure, grouping, `array(`,
					// closure call) has no gettext callee either.
					$callee = null;
					if ( \is_array( $previous ) && \in_array( $previous[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) ) {
						$is_member_or_declaration = \is_array( $penultimate ) && \in_array( $penultimate[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION ), true );
						if ( ! $is_member_or_declaration ) {
							$callee = \strtolower( \ltrim( $previous[1], '\\' ) );
						}
					}
					$frame_stack[] = array( $in_call, $domain_pos, $has_named, $arg_index, $square, $curly );
					$in_call       = true;
					$domain_pos    = null !== $callee ? ( $gettext_domain_positions[ $callee ] ?? null ) : null;
					$has_named     = null !== $domain_pos && $call_uses_named_arguments( $i );
					$arg_index     = 1;
					$square        = 0;
					$curly         = 0;
				} elseif ( ')' === $token ) {
					if ( array() !== $frame_stack ) {
						list( $in_call, $domain_pos, $has_named, $arg_index, $square, $curly ) = \array_pop( $frame_stack );
					} else {
						$in_call    = false;
						$domain_pos = null;
						$has_named  = false;
					}
				} elseif ( '[' === $token ) {
					++$square;
				} elseif ( ']' === $token ) {
					--$square;
				} elseif ( '{' === $token ) {
					++$curly;
				} elseif ( '}' === $token ) {
					--$curly;
				} elseif ( ',' === $token ) {
					if ( $in_call && 0 === $square && 0 === $curly ) {
						++$arg_index;
					}
				} elseif ( \is_array( $token ) && T_ATTRIBUTE === $token[0] ) {
					// `#[` opens a bracket closed by a plain `]`.
					++$square;
				} elseif ( \is_array( $token ) && ( T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) ) {
					// Interpolation braces are closed by a plain `}`.
					++$curly;
				} elseif ( \is_array( $token ) && T_START_HEREDOC === $token[0] ) {
					$in_nowdoc = \str_contains( $token[1], "'" );
				} elseif ( \is_array( $token ) && T_END_HEREDOC === $token[0] ) {
					$in_nowdoc = false;
				} elseif ( \is_array( $token ) && T_ENCAPSED_AND_WHITESPACE === $token[0] ) {
					// Heredoc bodies and the literal parts of interpolated strings cannot be
					// rewritten at all, so — deliberately stricter than the starts_with rule for
					// constant strings — ANY occurrence, even mid-string, is flagged for a human.
					// Heredoc/interpolated fragments decode escapes at runtime, so the check runs
					// on the decoded value; a nowdoc body stays raw, matching its runtime value.
					$fragment = $in_nowdoc ? $token[1] : $decode_escapes( $token[1] );
					if ( \str_contains( $fragment, $framework_domain_prefix ) ) {
						throw new \RuntimeException(
							\sprintf( 'Reserved `wp-framework-` occurrence inside a heredoc/nowdoc/interpolated string in %s — it cannot be safely rewritten to the consumer text domain. Move the domain into a plain constant string at a gettext domain position, or rename the string if it is not an i18n domain (hooks/options use the dws_ prefix).', $file_path )
						);
					}
				} elseif ( \is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$reserved = \str_starts_with( $decoded_value( $token[1] ), $framework_domain_prefix );

					// Named-argument labels anywhere in the tracked call make positional domain
					// semantics unreliable — fail loud on any reserved literal instead of trying
					// to track labels (canonical-order named arguments included).
					if ( $in_call && $has_named && $reserved ) {
						throw new \RuntimeException(
							\sprintf( 'Reserved literal %s in a gettext call using named arguments in %s — named arguments defeat positional domain detection. If this literal is the intended text domain, switch the call to positional arguments; if it is a message or context string, rename it off the reserved wp-framework- prefix (hooks/options use the dws_ prefix).', $token[1], $file_path )
						);
					}

					$at_domain = $in_call
						&& null !== $domain_pos
						&& $arg_index === $domain_pos
						&& 0 === $square
						&& 0 === $curly;

					if ( $at_domain ) {
						// THE PLAIN-ARGUMENT RULE: rewrite only when the domain argument is exactly
						// one constant string between its delimiters. Any compound expression there
						// (concatenation, ternary, arrow-fn body, …) throws when a reserved literal
						// participates — rewriting a fragment would corrupt the expression, and a
						// runtime-built domain defeats the scope-time rewrite. Compound expressions
						// with no reserved literal are left alone: a scoped third-party library may
						// define its own __()/translate() and must not fail the consumer's run.
						$plain = ( '(' === $previous || ',' === $previous )
							&& \in_array( $next_significant( $i ), array( ',', ')' ), true );

						if ( $plain && $reserved ) {
							// An escape-obfuscated reserved domain is flagged, not silently
							// normalised — legitimate framework source writes plain literals.
							if ( ! \str_starts_with( $raw_value( $token[1] ), $framework_domain_prefix ) ) {
								throw new \RuntimeException(
									\sprintf( 'Escape-obfuscated reserved literal %s at a gettext domain position in %s — write the domain as a plain string literal so the scope-time rewrite can act on it.', $token[1], $file_path )
								);
							}
							$out        .= $replacement;
							$penultimate = $previous;
							$previous    = $token;
							continue;
						}
						if ( ! $plain && $reserved ) {
							throw new \RuntimeException(
								\sprintf( 'Reserved literal %s participates in a compound expression at a gettext domain position in %s — the domain argument must be a single plain string literal for the scope-time rewrite.', $token[1], $file_path )
							);
						}
					} elseif ( $reserved ) {
						throw new \RuntimeException(
							\sprintf( 'Reserved literal %s in %s sits outside a gettext domain position — `wp-framework-*` literals are text domains only. Rename the string (hooks/options use the dws_ prefix) or extend the gettext position map.', $token[1], $file_path )
						);
					}
				}

				$out .= \is_array( $token ) ? $token[1] : $token;
				if ( \is_array( $token ) && \in_array( $token[0], $insignificant, true ) ) {
					continue;
				}
				$penultimate = $previous;
				$previous    = $token;
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
	$patchers[] = static function ( string $file_path, string $prefix, string $content ) use ( $decode_escapes ): string {
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
				$haystack = $in_nowdoc ? $token[1] : $decode_escapes( $token[1] );
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
