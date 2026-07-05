<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use DeepWebSolutions\Config\Composer\Internal\StubSymbolCollector;

/**
 * Composer post-autoload-dump hook. Reads each `extra.scoping-stubs` array from the
 * root package and every installed package — straight off Composer's in-memory package
 * metadata, so a path-repository package symlinked into vendor (monorepo dev) is included
 * exactly like a normally-installed one. It parses every stubs file each declared entry
 * resolves to and writes the unioned class/function/constant symbol set to
 * `scoping-exclusions.json`. The class/function/constant lists are sorted before writing,
 * so the output regenerates byte-identical regardless of package iteration order.
 *
 * Each `extra.scoping-stubs` entry takes one of two forms:
 *
 *  - `vendor/package` — resolves the package's `autoload.files` plus the conventional
 *    `<install-path>/<package-name>.php` path via
 *    `InstallationManager::getInstallPath()` whenever it exists.
 *  - `vendor/package:relative/path/to/file.php` — resolves exactly that one file inside
 *    the package dir, for a secondary stubs file the package ships but does not list in its
 *    `autoload.files` (e.g. `php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php`).
 *    The separator is the first `:`; package names cannot contain one, so the split is
 *    unambiguous. The file part must be a safe relative path (no leading slash, no `..`
 *    segment, `.php`-suffixed) and is realpath-confined to the package dir.
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
	 * @throws \JsonException    If encoding the output JSON fails.
	 * @throws \PhpParser\Error  If a declared stubs file cannot be parsed.
	 * @throws \RuntimeException If the root `extra.scoping-stubs` is malformed, a declared stubs file cannot be read, or the output file cannot be written.
	 */
	public static function postAutoloadDump( \Composer\Script\Event $event ): void {
		$composer    = $event->getComposer();
		$console_io  = $event->getIO();
		$project_dir = \dirname( \Composer\Factory::getComposerFile() );

		if ( ! $event->isDevMode() ) {
			$console_io->write( 'Not collecting scoping stubs due to not being in dev mode.' );
			return;
		}

		$declared             = self::collect_declarations( $composer, $console_io );
		$packages_by_name     = self::index_packages_by_name( $composer );
		$installation_manager = $composer->getInstallationManager();

		$classes   = array();
		$functions = array();
		$constants = array();

		if ( \count( $declared ) > 0 ) {
			$parser = new \PhpParser\ParserFactory()->createForNewestSupportedVersion();

			foreach ( $declared as $entry ) {
				$stubs_paths = self::resolve_stubs_paths( $entry, $packages_by_name, $installation_manager, $console_io );
				if ( array() === $stubs_paths ) {
					// Explicit-file entries already wrote their own precise note inside the resolver.
					if ( ! \str_contains( $entry, ':' ) ) {
						$console_io->write( \sprintf( 'Skipping declared stubs package "%s" — no stubs file found in its autoload.files and the conventional path is absent.', $entry ) );
					}
					continue;
				}

				foreach ( $stubs_paths as $stubs_path ) {
					$contents = \file_get_contents( $stubs_path );
					if ( false === $contents ) {
						throw new \RuntimeException( \sprintf( 'Could not read stubs file %s', $stubs_path ) );
					}
					$parsed = $parser->parse( $contents );
					if ( null === $parsed ) {
						$console_io->write( \sprintf( 'Skipping stubs file %s — could not parse.', $stubs_path ) );
						continue;
					}

					$collector = new StubSymbolCollector();
					$traverser = new \PhpParser\NodeTraverser();
					// NameResolver populates `namespacedName` on Class_, Function_, Const_ — needed for FQCN distinction across namespaced stubs.
					$traverser->addVisitor( new \PhpParser\NodeVisitor\NameResolver() );
					$traverser->addVisitor( $collector );
					$traverser->traverse( $parsed );

					$classes   = \array_merge( $classes, $collector->classes );
					$functions = \array_merge( $functions, $collector->functions );
					$constants = \array_merge( $constants, $collector->constants );
				}
			}
		}

		// Sort each list so the output is a deterministic function of the declared symbol
		// set, independent of package iteration order — the file regenerates byte-identical.
		$classes   = \array_unique( $classes );
		$functions = \array_unique( $functions );
		$constants = \array_unique( $constants );
		\sort( $classes );
		\sort( $functions );
		\sort( $constants );

		$output_dir  = self::resolve_output_dir( $project_dir );
		$output_file = self::resolve_output_file();

		self::write_atomically(
			"$output_dir/$output_file",
			\json_encode(
				array(
					'classes'   => $classes,
					'functions' => $functions,
					'constants' => $constants,
				),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Collects every `extra.scoping-stubs` declaration across the root package and all
	 * installed packages, reading Composer's in-memory metadata rather than walking vendor.
	 *
	 * The root package's declaration is validated strictly — it is the consumer's own file,
	 * so a malformed shape or entry throws instead of silently shrinking the exclusion set.
	 * Installed packages are third-party metadata the consumer cannot fix, so their malformed
	 * declarations are skipped with a console warning. Composer's package list carries
	 * path-repository packages (symlinked into vendor in monorepo dev) the same as
	 * normally-installed ones, so a symlinked package that declares stubs is included.
	 * The result is deduplicated and sorted so the downstream symbol union
	 * is order-independent.
	 *
	 * @param \Composer\Composer       $composer   Composer instance for the current run.
	 * @param \Composer\IO\IOInterface $console_io Composer console for malformed-declaration warnings.
	 *
	 * @throws \RuntimeException If the root package's `extra.scoping-stubs` is malformed.
	 *
	 * @return list<string>
	 */
	private static function collect_declarations( \Composer\Composer $composer, \Composer\IO\IOInterface $console_io ): array {
		$declared = self::extract_root_declarations( $composer->getPackage()->getExtra() );

		foreach ( $composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package ) {
			$declared = \array_merge( $declared, self::extract_package_declarations( $package, $console_io ) );
		}

		$declared = \array_values( \array_unique( $declared ) );
		\sort( $declared );

		return $declared;
	}

	/**
	 * Validates and returns the root package's `extra.scoping-stubs` entries.
	 *
	 * The root declaration is load-bearing input the consumer owns, so every malformed shape
	 * fails loudly: a non-array value (scalar or object) and any entry that is not a valid
	 * `vendor/package` or `vendor/package:relative/file.php` string throw instead of being
	 * filtered into an empty exclusion set that only fails at runtime. An absent key is the
	 * one legitimate quiet case — a consumer whose exclusions all come from its dependencies.
	 *
	 * @param array<array-key, mixed> $extra The root package's `extra` metadata.
	 *
	 * @throws \RuntimeException If `scoping-stubs` is not a list of valid declaration strings.
	 *
	 * @return list<string>
	 */
	private static function extract_root_declarations( array $extra ): array {
		if ( ! \array_key_exists( 'scoping-stubs', $extra ) ) {
			return array();
		}

		$declared = $extra['scoping-stubs'];
		if ( ! \is_array( $declared ) ) {
			throw new \RuntimeException(
				\sprintf( 'The root extra.scoping-stubs must be an array of "vendor/package" or "vendor/package:relative/file.php" strings; got %s.', \get_debug_type( $declared ) )
			);
		}

		$valid = array();
		foreach ( $declared as $entry ) {
			if ( ! \is_string( $entry ) || ! self::is_valid_declaration( $entry ) ) {
				throw new \RuntimeException(
					\sprintf(
						'Invalid root extra.scoping-stubs entry %s — expected "vendor/package" or "vendor/package:relative/file.php" (relative, no "..", ".php"-suffixed).',
						\var_export( $entry, true )
					)
				);
			}
			$valid[] = $entry;
		}

		return $valid;
	}

	/**
	 * Filters one installed package's `extra` array down to its valid `extra.scoping-stubs`
	 * entries, warning about every malformed shape it drops.
	 *
	 * Third-party metadata stays tolerated — the consumer cannot edit an installed package's
	 * composer.json, so a hard failure here would brick installs on someone else's typo — but
	 * each dropped shape is surfaced as a console warning instead of vanishing silently.
	 *
	 * @param \Composer\Package\PackageInterface $package    An installed package.
	 * @param \Composer\IO\IOInterface           $console_io Composer console for the warnings.
	 *
	 * @return list<string>
	 */
	private static function extract_package_declarations( \Composer\Package\PackageInterface $package, \Composer\IO\IOInterface $console_io ): array {
		$extra = $package->getExtra();
		if ( ! \array_key_exists( 'scoping-stubs', $extra ) ) {
			return array();
		}

		$declared = $extra['scoping-stubs'];
		if ( ! \is_array( $declared ) ) {
			$console_io->warning(
				\sprintf( 'Ignoring malformed extra.scoping-stubs in package %s — expected an array, got %s.', $package->getName(), \get_debug_type( $declared ) )
			);
			return array();
		}

		$valid = array();
		foreach ( $declared as $entry ) {
			if ( \is_string( $entry ) && self::is_valid_declaration( $entry ) ) {
				$valid[] = $entry;
				continue;
			}
			$console_io->warning(
				\sprintf( 'Ignoring invalid extra.scoping-stubs entry %s in package %s.', \var_export( $entry, true ), $package->getName() )
			);
		}

		return $valid;
	}

	/**
	 * Indexes the installed packages by name, for resolving a declared entry's `autoload.files`
	 * from in-memory metadata instead of re-reading each package's composer.json from disk.
	 *
	 * @param \Composer\Composer $composer Composer instance for the current run.
	 *
	 * @return array<string, \Composer\Package\PackageInterface>
	 */
	private static function index_packages_by_name( \Composer\Composer $composer ): array {
		$packages_by_name = array();
		foreach ( $composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package ) {
			$packages_by_name[ $package->getName() ] = $package;
		}

		return $packages_by_name;
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
	 * @param   string $project_dir Absolute path to the project root.
	 *
	 * @throws  \RuntimeException If the env-var-supplied directory is missing or escapes the project root.
	 *
	 * @return  string Absolute path to the resolved output directory.
	 */
	private static function resolve_output_dir( string $project_dir ): string {
		$override = \getenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR' );
		if ( false === $override || '' === $override ) {
			return $project_dir;
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
	 * @throws  \RuntimeException If the env-var-supplied value contains a path separator.
	 *
	 * @return  string Filename to write inside the resolved output directory.
	 */
	private static function resolve_output_file(): string {
		$override = \getenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE' );
		if ( false === $override || '' === $override ) {
			return 'scoping-exclusions.json';
		}

		if ( '.' === $override || '..' === $override || \str_contains( $override, '/' ) || \str_contains( $override, '\\' ) ) {
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
		if ( false === \file_put_contents( $temp_path, $payload ) ) {
			throw new \RuntimeException( \sprintf( 'Could not write %s', $temp_path ) );
		}

		try {
			\rename( $temp_path, $output_path ) || throw new \RuntimeException( \sprintf( 'Could not rename %s to %s', $temp_path, $output_path ) );
		} catch ( \Throwable $throwable ) {
			if ( \is_file( $temp_path ) ) {
				\unlink( $temp_path );
			}
			throw $throwable;
		}
	}

	/**
	 * Resolves one `extra.scoping-stubs` entry to its stubs-file paths.
	 *
	 * A bare `vendor/package` reads the package's `autoload.files` (multi-file stubs
	 * packages like `php-stubs/woocommerce-stubs`) from its in-memory metadata plus the conventional
	 * `<install-path>/<package-name>.php` path via `InstallationManager::getInstallPath()` whenever
	 * it exists (minimal hand-rolled stubs packages ship only that). The explicit-file form
	 * `vendor/package:relative/file.php` resolves that one named
	 * file inside the package dir — for a secondary stubs file the package ships but does not list
	 * in its `autoload.files` (e.g. woocommerce-stubs' `woocommerce-packages-stubs.php`).
	 *
	 * The package directory is Composer's own `InstallationManager::getInstallPath()` for the
	 * matched package — the canonical install location, which honours `target-dir` and custom
	 * installer/plugin relocations, and is the symlink location for a path-repository package
	 * (so realpath confinement resolves through the symlink to the real source). A declared
	 * package absent from the local repository (declared but not installed), or one with no
	 * install path (a metapackage), yields nothing. Every candidate — each `autoload.files`
	 * entry, the conventional `<package-name>.php` path under that install path, and the
	 * explicit-file form — is realpath-confined
	 * to that directory via `confine_to_package`, so a compromised package declaring a traversal
	 * `autoload.files` entry or shipping a symlink that escapes its own dir cannot have an outside
	 * file's symbols harvested.
	 *
	 * @param string                                            $entry                Declaration entry: `vendor/package` or `vendor/package:relative/file.php`.
	 * @param array<string, \Composer\Package\PackageInterface> $packages_by_name     Installed packages indexed by name.
	 * @param \Composer\Installer\InstallationManager           $installation_manager Composer's install-path resolver.
	 * @param \Composer\IO\IOInterface                          $console_io           Composer console for skip notes.
	 *
	 * @return list<string> Existing-file paths in order; empty if nothing found.
	 */
	private static function resolve_stubs_paths( string $entry, array $packages_by_name, \Composer\Installer\InstallationManager $installation_manager, \Composer\IO\IOInterface $console_io ): array {
		if ( \str_contains( $entry, ':' ) ) {
			[ $package, $file ] = \explode( ':', $entry, 2 );
		} else {
			$package = $entry;
			$file    = null;
		}

		// A bare entry's skip note is emitted by the caller; an explicit-file entry, which
		// otherwise owns its note inside resolve_explicit_file, notes here so the reason is not lost.
		$declared_package = $packages_by_name[ $package ] ?? null;
		if ( null === $declared_package ) {
			if ( null !== $file ) {
				$console_io->write( \sprintf( 'Skipping declared stubs file "%s" — its package "%s" is not installed.', $file, $package ) );
			}
			return array();
		}

		try {
			$package_dir = $installation_manager->getInstallPath( $declared_package );
		} catch ( \InvalidArgumentException ) {
			if ( null !== $file ) {
				$console_io->write( \sprintf( 'Skipping declared stubs file "%s" — its package "%s" has no installation path.', $file, $package ) );
			} else {
				$console_io->write( \sprintf( 'Skipping declared stubs package "%s" — it has no installation path.', $package ) );
			}
			return array();
		}
		if ( null === $package_dir ) {
			// A metapackage (or anything with nothing on disk) has no install path.
			if ( null !== $file ) {
				$console_io->write( \sprintf( 'Skipping declared stubs file "%s" — its package "%s" has no installation path.', $file, $package ) );
			}
			return array();
		}

		if ( null !== $file ) {
			return self::resolve_explicit_file( $package_dir, $file, $console_io );
		}

		$candidates = array();

		$files = $declared_package->getAutoload()['files'] ?? array();
		if ( \is_array( $files ) ) {
			foreach ( $files as $relative ) {
				if ( \is_string( $relative ) ) {
					$candidates[] = $package_dir . '/' . $relative;
				}
			}
		}

		$parts = \explode( '/', $package );
		if ( 2 === \count( $parts ) ) {
			$candidates[] = $package_dir . '/' . $parts[1] . '.php';
		}

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
