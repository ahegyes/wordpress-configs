<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

/**
 * Composer post-autoload-dump hook. Walks `vendor/<vendor>/<package>/composer.json`
 * + the project root, reads each `extra.scoping-stubs` array, parses every stubs
 * file each declared entry resolves to, and writes the unioned class/function/constant
 * symbol set to `scoping-exclusions.json`.
 *
 * Each `extra.scoping-stubs` entry takes one of two forms:
 *
 *  - `vendor/package` — resolves the package's `autoload.files` plus the conventional
 *    `vendor/<vendor>/<package>/<package>.php` path whenever it exists.
 *  - `vendor/package:relative/path/to/file.php` — resolves exactly that one file inside
 *    the package dir, for a secondary catalog the package ships but does not list in its
 *    `autoload.files` (e.g. `php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php`,
 *    the catalog declaring the Action Scheduler `as_*` functions). The separator is the
 *    first `:`; package names cannot contain one, so the split is unambiguous. The file
 *    part must be a safe relative path (no leading slash, no `..` segment, `.php`-suffixed)
 *    and is realpath-confined to the package dir.
 *
 * Declaration format:
 *
 *     "extra": {
 *         "scoping-stubs": [
 *             "php-stubs/wordpress-stubs",
 *             "php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php"
 *         ]
 *     }
 *
 * The php-scoper base config reads the output JSON into its `exclude-classes`,
 * `exclude-functions`, and `exclude-constants` keys.
 */
final class CollectScopingStubs {
	/**
	 * Composer's official package-name regex (matches `vendor/name`). Used to reject
	 * stray `extra.scoping-stubs` values before they reach path-building, so a
	 * malformed entry can't be probed against the filesystem as a path segment.
	 *
	 * @var string
	 */
	private const PACKAGE_NAME_REGEX = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#';

	/**
	 * Composer event handler for `post-autoload-dump`. See class docblock for what it does.
	 *
	 * @param \Composer\Script\Event $event Composer event object.
	 *
	 * @throws \JsonException    If JSON parsing or encoding fails.
	 * @throws \PhpParser\Error  If the PHP parser fails to initialise.
	 * @throws \RuntimeException If a declared stubs file or a vendor composer.json cannot be read, or the output file cannot be written.
	 */
	public static function postAutoloadDump( \Composer\Script\Event $event ): void {
		$console_io  = $event->getIO();
		$vendor_dir  = $event->getComposer()->getConfig()->get( 'vendor-dir' );
		$project_dir = \dirname( \Composer\Factory::getComposerFile() );

		if ( ! $event->isDevMode() ) {
			$console_io->write( 'Not collecting scoping stubs due to not being in dev mode.' );
			return;
		}

		$declared = self::collect_declarations( $project_dir, $vendor_dir );

		$classes   = array();
		$functions = array();
		$constants = array();

		if ( \count( $declared ) > 0 ) {
			$parser = new \PhpParser\ParserFactory()->createForNewestSupportedVersion();

			foreach ( $declared as $entry ) {
				$stubs_paths = self::resolve_stubs_paths( $vendor_dir, $entry, $console_io );
				if ( array() === $stubs_paths ) {
					// Explicit-file entries already wrote their own precise note inside the resolver.
					if ( ! \str_contains( $entry, ':' ) ) {
						$console_io->write( \sprintf( 'Skipping declared stubs package "%s" — no stubs file found in its autoload.files and the conventional path is absent.', $entry ) );
					}
					continue;
				}

				foreach ( $stubs_paths as $stubs_path ) {
					$contents = \file_get_contents( $stubs_path ) ?: throw new \RuntimeException( \sprintf( 'Could not read stubs file %s', $stubs_path ) );
					$parsed   = $parser->parse( $contents );
					if ( null === $parsed ) {
						$console_io->write( \sprintf( 'Skipping stubs file %s — could not parse.', $stubs_path ) );
						continue;
					}

					$visitor   = new class() extends \PhpParser\NodeVisitorAbstract {
						/**
						 * Fully-qualified class names collected from the parsed stubs.
						 *
						 * @var list<string>
						 */
						public array $classes = array();

						/**
						 * Fully-qualified function names collected from the parsed stubs.
						 *
						 * @var list<string>
						 */
						public array $functions = array();

						/**
						 * Fully-qualified constant names collected from the parsed stubs.
						 * Includes both `const FOO = ...;` declarations and `define('FOO', ...)` calls.
						 *
						 * @var list<string>
						 */
						public array $constants = array();

						/**
						 * {@inheritDoc}
						 *
						 * @param \PhpParser\Node $node Node being visited.
						 */
						public function enterNode( \PhpParser\Node $node ): int|null {
							switch ( \get_class( $node ) ) {
								// Class-like declarations all flow into `$classes` — php-scoper's `exclude-classes` covers all of them.
								case \PhpParser\Node\Stmt\Class_::class:
								case \PhpParser\Node\Stmt\Interface_::class:
								case \PhpParser\Node\Stmt\Trait_::class:
								case \PhpParser\Node\Stmt\Enum_::class:
									if ( null !== $node->namespacedName ) {
										$this->classes[] = $node->namespacedName->toString();
									}
									return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
								case \PhpParser\Node\Stmt\Function_::class:
									if ( null !== $node->namespacedName ) {
										$this->functions[] = $node->namespacedName->toString();
									}
									break;
								case \PhpParser\Node\Stmt\Const_::class:
									foreach ( $node->consts as $const ) {
										if ( null !== $const->namespacedName ) {
											$this->constants[] = $const->namespacedName->toString();
										}
									}
									break;
								case \PhpParser\Node\Expr\FuncCall::class:
									if (
										$node->name instanceof \PhpParser\Node\Name
										&& 'define' === $node->name->toString()
										&& isset( $node->args[0] )
										&& $node->args[0] instanceof \PhpParser\Node\Arg
										&& $node->args[0]->value instanceof \PhpParser\Node\Scalar\String_
									) {
										$this->constants[] = $node->args[0]->value->value;
									}
									break;
							}
							return null;
						}
					};
					$traverser = new \PhpParser\NodeTraverser();
					// NameResolver populates `namespacedName` on Class_, Function_, Const_ — needed for FQCN distinction across namespaced stubs.
					$traverser->addVisitor( new \PhpParser\NodeVisitor\NameResolver() );
					$traverser->addVisitor( $visitor );
					$traverser->traverse( $parsed );

					$classes   = \array_merge( $classes, $visitor->classes );
					$functions = \array_merge( $functions, $visitor->functions );
					$constants = \array_merge( $constants, $visitor->constants );
				}
			}
		}

		$output_dir  = self::resolve_output_dir( $project_dir, $vendor_dir );
		$output_file = self::resolve_output_file();

		self::write_atomically(
			"$output_dir/$output_file",
			\json_encode(
				array(
					'classes'   => \array_values( \array_unique( $classes ) ),
					'functions' => \array_values( \array_unique( $functions ) ),
					'constants' => \array_values( \array_unique( $constants ) ),
				),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Walks the vendor directory + project root, collecting all
	 * `extra.scoping-stubs` package declarations.
	 *
	 * @param string $project_dir Project root directory.
	 * @param string $vendor_dir  Composer vendor directory.
	 *
	 * @throws \JsonException    If a composer.json file cannot be parsed.
	 * @throws \RuntimeException If a composer.json file exists but cannot be read.
	 *
	 * @return list<string>
	 */
	private static function collect_declarations( string $project_dir, string $vendor_dir ): array {
		$declared = self::read_declaration( $project_dir . '/composer.json' );

		if ( \is_dir( $vendor_dir ) ) {
			// Walk packages installed at the standard composer layout:
			// `vendor/<vendor>/<package>/composer.json`. Finder's depth is 0-indexed,
			// so depth 2 is the file two directories below the search root.
			//
			// Consumers using `extra.installer-paths` to redirect packages to a
			// non-standard depth inside vendor would be missed by this walk —
			// scoped deps generally don't go through custom installer-paths, so
			// this trade-off favours noise reduction (no false matches from
			// bundled sub-project composer.json files deeper in the tree).
			$finder = \Symfony\Component\Finder\Finder::create()
				->in( $vendor_dir )
				->files()
				->name( 'composer.json' )
				->depth( 2 );

			foreach ( $finder as $file ) {
				$declared = \array_merge( $declared, self::read_declaration( $file->getPathname() ) );
			}
		}

		return \array_values( \array_unique( $declared ) );
	}

	/**
	 * Reads `extra.scoping-stubs` from a single composer.json file.
	 *
	 * @param string $composer_json_path Path to the composer.json file.
	 *
	 * @return list<string>
	 *
	 * @throws \JsonException    If the file exists but cannot be parsed.
	 * @throws \RuntimeException If the file exists but cannot be read.
	 */
	private static function read_declaration( string $composer_json_path ): array {
		if ( ! \is_file( $composer_json_path ) ) {
			return array();
		}

		$contents = \file_get_contents( $composer_json_path ) ?: throw new \RuntimeException( \sprintf( 'Could not read %s', $composer_json_path ) );
		$data     = \json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );

		$declared = $data['extra']['scoping-stubs'] ?? array();
		if ( ! \is_array( $declared ) ) {
			return array();
		}

		return \array_values(
			\array_filter(
				$declared,
				static fn ( mixed $entry ): bool => \is_string( $entry ) && self::is_valid_declaration( $entry )
			)
		);
	}

	/**
	 * Validates one `extra.scoping-stubs` entry before it reaches path-building.
	 *
	 * An entry is either a bare `vendor/package` (the package part matching Composer's
	 * package-name regex) or the explicit-file form `vendor/package:relative/file.php`.
	 * Composer package names cannot contain `:`, so the first `:` unambiguously splits
	 * the package from the file. The file part must be a safe relative path — non-empty,
	 * not anchored at `/` or `\`, free of any `..` segment, and `.php`-suffixed — so a
	 * malicious entry cannot be probed against the filesystem outside the package dir.
	 *
	 * @param string $entry A single `extra.scoping-stubs` value.
	 *
	 * @return bool
	 */
	private static function is_valid_declaration( string $entry ): bool {
		$parts   = \explode( ':', $entry, 2 );
		$package = $parts[0];
		$file    = $parts[1] ?? null;

		if ( 1 !== \preg_match( self::PACKAGE_NAME_REGEX, $package ) ) {
			return false;
		}

		if ( null === $file ) {
			return true;
		}

		return self::is_safe_relative_path( $file );
	}

	/**
	 * Reports whether a relative path is safe to append to a package directory.
	 *
	 * Rejects empty strings, paths carrying a NUL byte (which would make `realpath()`
	 * throw a `ValueError` and abort the hook), paths carrying a colon (Windows drive
	 * `C:/...` and NTFS alternate-data-stream `file:stream` shapes never appear in a
	 * legitimate relative stub path — left to `realpath()` they invite drive/ADS semantics),
	 * paths anchored at `/` or `\`, any `..` path segment (the traversal vector), and
	 * non-`.php` files. Segment-checking on both separators catches `..` whichever slash a
	 * hand-written entry uses.
	 *
	 * @param string $path Relative path drawn from a `package:file` declaration.
	 *
	 * @return bool
	 */
	private static function is_safe_relative_path( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}

		if ( \str_contains( $path, "\0" ) ) {
			return false;
		}

		if ( \str_contains( $path, ':' ) ) {
			return false;
		}

		if ( \str_starts_with( $path, '/' ) || \str_starts_with( $path, '\\' ) ) {
			return false;
		}

		if ( ! \str_ends_with( $path, '.php' ) ) {
			return false;
		}

		// Segment-check on both separators so `..` is caught whichever slash the entry uses.
		foreach ( \explode( '/', \str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolves the output directory for `scoping-exclusions.json`.
	 *
	 * The `SCOPING_EXCLUSIONS_OUTPUT_DIR` env var is an opt-in test seam (and documented
	 * override). Its value must resolve to an existing directory under the project root —
	 * an attacker who can set environment variables during a composer run cannot redirect
	 * the write outside the project tree.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $project_dir Absolute path to the project root.
	 * @param   string $vendor_dir  Absolute path to the composer vendor directory.
	 *
	 * @throws  \RuntimeException If the env-var-supplied directory is missing or escapes the project root.
	 *
	 * @return  string Absolute path to the resolved output directory.
	 */
	private static function resolve_output_dir( string $project_dir, string $vendor_dir ): string {
		$override = \getenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR' );
		if ( false === $override || '' === $override ) {
			return \dirname( $vendor_dir );
		}

		$resolved_override = \realpath( $override );
		if ( false === $resolved_override || ! \is_dir( $resolved_override ) ) {
			throw new \RuntimeException( \sprintf( 'SCOPING_EXCLUSIONS_OUTPUT_DIR "%s" does not resolve to an existing directory.', $override ) );
		}

		$resolved_project = \realpath( $project_dir ) ?: $project_dir;
		if ( $resolved_override !== $resolved_project && ! \str_starts_with( $resolved_override . DIRECTORY_SEPARATOR, $resolved_project . DIRECTORY_SEPARATOR ) ) {
			throw new \RuntimeException( \sprintf( 'SCOPING_EXCLUSIONS_OUTPUT_DIR "%s" must be inside the project root "%s".', $override, $resolved_project ) );
		}

		return $resolved_override;
	}

	/**
	 * Resolves the output filename for `scoping-exclusions.json`.
	 *
	 * `SCOPING_EXCLUSIONS_OUTPUT_FILE` is a filename, not a path — rejecting separators
	 * prevents the env var from being used to escape the output directory via `../`.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @throws  \RuntimeException If the env-var-supplied value contains a path separator.
	 *
	 * @return  string Filename to write inside the resolved output directory.
	 */
	private static function resolve_output_file(): string {
		$override = \getenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE' );
		if ( false === $override || '' === $override ) {
			return 'scoping-exclusions.json';
		}

		if ( \str_contains( $override, '/' ) || \str_contains( $override, '\\' ) ) {
			throw new \RuntimeException( \sprintf( 'SCOPING_EXCLUSIONS_OUTPUT_FILE must be a filename, not a path; got "%s".', $override ) );
		}

		return $override;
	}

	/**
	 * Writes `$payload` to `$output_path` via a temp-file-and-rename so a concurrent
	 * composer run can't read the file mid-write. PID + 4 random bytes in the temp
	 * filename prevents collisions across PID-namespace-reusing container runtimes.
	 *
	 * @infection-ignore-all
	 *
	 * @param string $output_path Target path.
	 * @param string $payload     Bytes to write.
	 *
	 * @throws \RuntimeException If the temp file cannot be written or the rename fails.
	 */
	private static function write_atomically( string $output_path, string $payload ): void {
		$temp_path = $output_path . '.tmp.' . \getmypid() . '.' . \bin2hex( \random_bytes( 4 ) );
		\file_put_contents( $temp_path, $payload ) ?: throw new \RuntimeException( \sprintf( 'Could not write %s', $temp_path ) );
		\rename( $temp_path, $output_path ) ?: throw new \RuntimeException( \sprintf( 'Could not rename %s to %s', $temp_path, $output_path ) );
	}

	/**
	 * Resolves one `extra.scoping-stubs` entry to its stubs-file paths.
	 *
	 * A bare `vendor/package` reads the package's `autoload.files` (multi-file catalogs
	 * like `php-stubs/woocommerce-stubs`) plus the conventional `<name>/<name>.php` path
	 * whenever it exists (minimal hand-rolled catalogs ship only that). The explicit-file form
	 * `vendor/package:relative/file.php` resolves that one named file inside the package
	 * dir — for a secondary catalog the package ships but does not list in its
	 * `autoload.files` (e.g. woocommerce-stubs' `woocommerce-packages-stubs.php`, which
	 * declares the Action Scheduler `as_*` functions).
	 *
	 * Every candidate — each `autoload.files` entry, the conventional `<name>.php` path, and the
	 * explicit-file form — is realpath-confined to the package dir via `confine_to_package`,
	 * so a compromised package declaring a traversal `autoload.files` entry or shipping a
	 * symlink that escapes its own dir cannot have an outside file's symbols harvested.
	 *
	 * @param string                   $vendor_dir Composer vendor directory.
	 * @param string                   $entry      Declaration entry: `vendor/package` or `vendor/package:relative/file.php`.
	 * @param \Composer\IO\IOInterface $console_io Composer console for skip notes.
	 *
	 * @throws \JsonException    If the package's composer.json exists but cannot be parsed.
	 * @throws \RuntimeException If the package's composer.json exists but cannot be read.
	 *
	 * @return list<string> Existing-file paths in order; empty if nothing found.
	 */
	private static function resolve_stubs_paths( string $vendor_dir, string $entry, \Composer\IO\IOInterface $console_io ): array {
		if ( \str_contains( $entry, ':' ) ) {
			[ $package, $file ] = \explode( ':', $entry, 2 );
			return self::resolve_explicit_file( $vendor_dir . '/' . $package, $file, $console_io );
		}

		$package = $entry;
		$parts   = \explode( '/', $package );
		if ( 2 !== \count( $parts ) ) {
			return array();
		}

		$package_dir   = $vendor_dir . '/' . $package;
		$composer_json = $package_dir . '/composer.json';

		$candidates = array();

		if ( \is_file( $composer_json ) ) {
			$contents = \file_get_contents( $composer_json ) ?: throw new \RuntimeException( \sprintf( 'Could not read %s', $composer_json ) );
			$data     = \json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );

			$files = $data['autoload']['files'] ?? array();
			if ( \is_array( $files ) ) {
				foreach ( $files as $relative ) {
					if ( \is_string( $relative ) ) {
						$candidates[] = $package_dir . '/' . $relative;
					}
				}
			}
		}

		$candidates[] = $package_dir . '/' . $parts[1] . '.php';

		$existing = array();
		foreach ( $candidates as $candidate ) {
			$confined = self::confine_to_package( $candidate, $package_dir );
			if ( null !== $confined && ! \in_array( $confined, $existing, true ) ) {
				$existing[] = $confined;
			}
		}

		return $existing;
	}

	/**
	 * Realpath-confines a candidate file to a package directory.
	 *
	 * Resolves both the candidate and the package dir, and accepts the candidate only when
	 * it exists, is a regular file, and resolves to a path strictly under the real package
	 * dir. A regular file can never equal the package dir itself, so containment requires a
	 * leading `<real package dir>/` prefix on the resolved candidate — closing both the `..`
	 * traversal vector and the in-package-symlink-pointing-outward vector.
	 *
	 * @param string $candidate   Absolute candidate path (package dir joined with a relative file).
	 * @param string $package_dir Absolute path to the package directory inside vendor.
	 *
	 * @return string|null The resolved, confined file path, or null if it cannot be safely resolved.
	 */
	private static function confine_to_package( string $candidate, string $package_dir ): ?string {
		$real_candidate = \realpath( $candidate );
		$real_package   = \realpath( $package_dir );

		if ( false === $real_candidate || false === $real_package ) {
			return null;
		}

		if ( ! \str_starts_with( $real_candidate, $real_package . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		if ( ! \is_file( $real_candidate ) ) {
			return null;
		}

		return $real_candidate;
	}

	/**
	 * Resolves the explicit-file declaration form: the single named file inside a package.
	 *
	 * Containment is delegated to `confine_to_package` (shared with the bare-package path):
	 * a missing package, a missing file, a candidate that escapes the package dir, or a
	 * non-regular file yields an empty result + a skip note. `is_safe_relative_path` already
	 * rejects `..` at validation, so this is the second line of defence — symlinks inside the
	 * package can still point outward, and the realpath confinement catches them.
	 *
	 * @param string                   $package_dir Absolute path to the package directory inside vendor.
	 * @param string                   $file        Validated relative path to the stubs file.
	 * @param \Composer\IO\IOInterface $console_io  Composer console for skip notes.
	 *
	 * @return list<string> Single-element list with the resolved file, or empty if it cannot be safely resolved.
	 */
	private static function resolve_explicit_file( string $package_dir, string $file, \Composer\IO\IOInterface $console_io ): array {
		$confined = self::confine_to_package( $package_dir . '/' . $file, $package_dir );

		if ( null === $confined ) {
			$console_io->write( \sprintf( 'Skipping declared stubs file "%s" — it does not resolve to a regular file inside its package directory "%s".', $file, $package_dir ) );
			return array();
		}

		return array( $confined );
	}
}
