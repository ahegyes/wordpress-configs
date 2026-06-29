<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

/**
 * Emits a single `dependencies/scoper-autoload.php` from each scoped package's
 * `autoload.psr-4`, `autoload.classmap` and `autoload.files`. Host plugins
 * reference this one file via their root `autoload.files`; new scoped packages
 * flow in automatically without host composer.json edits.
 *
 * Deterministic: no `class_alias` / `expose-*`, and every entry is sorted before
 * emission. The generated file is purely a function of the scoped tree and the
 * prefix, so it regenerates byte-identical.
 */
final class GenerateScopedAutoload {

	/**
	 * Generates `dependencies/scoper-autoload.php` from the scoped tree.
	 *
	 * Scoped composer.json `autoload.psr-4` keys are already prefixed by php-scoper —
	 * the generator emits them verbatim. `$prefix` is a sanity check; a mismatch
	 * throws to catch pipeline drift between the consumer's prefix declaration and
	 * what php-scoper actually applied. `autoload.classmap` directories are scanned
	 * for their (already-prefixed) class declarations and emitted via `addClassMap`;
	 * a class-free classmap (a functions-only package) contributes nothing.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $dependencies_dir Absolute path to the php-scoper output dir (typically `<project>/dependencies`).
	 * @param   string $prefix           Scoping prefix passed to php-scoper (e.g. `DeepWebSolutions\\InternalComments\\Scoped`).
	 *
	 * @throws  \JsonException     If a scoped composer.json exists but cannot be parsed.
	 * @throws  \RuntimeException  If a scoped composer.json or the output file cannot be read or written, if a psr-4 key doesn't start with the declared prefix, if a package declares autoload.exclude-from-classmap or autoload.psr-0 (both unsupported), if no class-map scanner is available, or if a classmap class maps to more than one file.
	 *
	 * @return  string Absolute path to the generated scoper-autoload.php.
	 */
	public static function generate( string $dependencies_dir, string $prefix ): string {
		$packages = self::find_scoped_packages( $dependencies_dir );

		$prefix_normalised = self::rtrim_backslash( $prefix );

		$psr4       = array();
		$files      = array();
		$scan_roots = array();

		foreach ( $packages as $pkg ) {
			$autoload = self::read_autoload( $pkg );
			if ( array() === $autoload ) {
				continue;
			}

			$pkg_rel = self::relative_path( $dependencies_dir, $pkg );

			// exclude-from-classmap would require honouring Composer's exclusion globs, which the
			// generator does not implement; fail loud rather than register an excluded class anyway.
			if ( array() !== ( $autoload['exclude-from-classmap'] ?? array() ) ) {
				throw new \RuntimeException(
					\sprintf(
						'Scoped package %s declares autoload.exclude-from-classmap, which the scoped-autoload generator does not apply. Add exclusion support before scoping a package that declares it.',
						$pkg_rel
					)
				);
			}

			// psr-0 is unsupported (its directory-from-namespace mapping differs from psr-4); fail
			// loud rather than silently drop the package's classes from the generated autoload.
			if ( array() !== ( $autoload['psr-0'] ?? array() ) ) {
				throw new \RuntimeException(
					\sprintf(
						'Scoped package %s declares autoload.psr-0, which the scoped-autoload generator does not support. Convert it to psr-4/classmap before scoping, or add psr-0 support.',
						$pkg_rel
					)
				);
			}

			foreach ( $autoload['classmap'] ?? array() as $classmap_entry ) {
				if ( ! \is_string( $classmap_entry ) ) {
					continue;
				}
				$scan_roots[] = $pkg . '/' . \trim( \str_replace( '\\', '/', $classmap_entry ), '/' );
			}

			foreach ( $autoload['psr-4'] ?? array() as $namespace => $sources ) {
				if ( ! \is_string( $namespace ) ) {
					continue;
				}
				// Pipeline-drift check — scoper ran with a different prefix than declared.
				if ( ! \str_starts_with( $namespace, $prefix_normalised . '\\' ) ) {
					throw new \RuntimeException(
						\sprintf(
							'Scoped package %s has PSR-4 key "%s" that does not start with the declared prefix "%s". Did php-scoper run with a different prefix?',
							$pkg_rel,
							$namespace,
							$prefix_normalised
						)
					);
				}
				foreach ( (array) $sources as $source ) {
					if ( ! \is_string( $source ) ) {
						continue;
					}
					$psr4[] = array(
						'namespace' => $namespace,
						'path'      => self::join_rel( $pkg_rel, $source ),
					);
				}
			}

			foreach ( $autoload['files'] ?? array() as $file ) {
				if ( ! \is_string( $file ) ) {
					continue;
				}
				$files[] = self::join_rel( $pkg_rel, $file );
			}
		}

		$classmap = self::scan_classmap_roots( $scan_roots, $dependencies_dir );
		$content  = self::render( $psr4, $files, $classmap );

		$output_path = $dependencies_dir . '/scoper-autoload.php';
		\file_put_contents( $output_path, $content ) ?: throw new \RuntimeException( \sprintf( 'Could not write %s', $output_path ) );

		return $output_path;
	}

	/**
	 * Finds every `composer.json` at `dependencies/<vendor>/<pkg>/composer.json`.
	 *
	 * @param   string $dependencies_dir Absolute path to the scoped output directory.
	 *
	 * @return  list<string> Absolute paths to package directories that contain a `composer.json`.
	 */
	private static function find_scoped_packages( string $dependencies_dir ): array {
		if ( ! \is_dir( $dependencies_dir ) ) {
			return array();
		}

		$packages = array();
		foreach ( \scandir( $dependencies_dir ) as $vendor_entry ) {
			if ( '.' === $vendor_entry || '..' === $vendor_entry ) {
				continue;
			}
			$vendor_dir = $dependencies_dir . '/' . $vendor_entry;
			if ( ! \is_dir( $vendor_dir ) ) {
				continue;
			}
			foreach ( \scandir( $vendor_dir ) as $pkg_entry ) {
				if ( '.' === $pkg_entry || '..' === $pkg_entry ) {
					continue;
				}
				$pkg_dir = $vendor_dir . '/' . $pkg_entry;
				if ( \is_dir( $pkg_dir ) && \is_file( $pkg_dir . '/composer.json' ) ) {
					$packages[] = $pkg_dir;
				}
			}
		}

		\sort( $packages );
		return $packages;
	}

	/**
	 * Reads and decodes a package's `autoload` block.
	 *
	 * @param   string $package_dir Absolute path to a scoped package directory.
	 *
	 * @return  array{psr-4?: array<string, string|list<string>>, psr-0?: array<string, string|list<string>>, classmap?: list<string>, exclude-from-classmap?: list<string>, files?: list<string>}
	 *
	 * @throws  \JsonException    If composer.json is malformed.
	 * @throws  \RuntimeException If composer.json cannot be read.
	 */
	private static function read_autoload( string $package_dir ): array {
		$composer_json = $package_dir . '/composer.json';
		$contents      = \file_get_contents( $composer_json ) ?: throw new \RuntimeException( \sprintf( 'Could not read %s', $composer_json ) );
		$data          = \json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );

		if ( ! \is_array( $data ) ) {
			return array();
		}
		$autoload = $data['autoload'] ?? array();
		return \is_array( $autoload ) ? $autoload : array();
	}

	/**
	 * Scans the collected classmap roots for class declarations via Composer's own scanner.
	 *
	 * The generator runs inside Composer (`post-autoload-dump`) and under Composer's autoloader
	 * in tests, so `composer/class-map-generator` — which Composer 2.4+ uses for its own dump —
	 * is loaded; the scanner reads each file's already-scoped class declarations and the FQNs
	 * are emitted verbatim. A single scanner spans all roots so a class defined in two scanned
	 * files surfaces as ambiguous (the path filter is disabled so every duplicate fails loud,
	 * not just non-test paths); an ambiguous class would otherwise resolve by filesystem order
	 * and break byte-identical regeneration. `autoload.exclude-from-classmap` is rejected upstream
	 * in generate() — the generator does not apply exclusion globs, so it fails loud rather than
	 * register an excluded class.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   list<string> $scan_roots       Absolute classmap directories or files inside scoped packages.
	 * @param   string       $dependencies_dir Absolute path to the scoped output directory the paths are relative to.
	 *
	 * @throws  \RuntimeException If Composer's class-map scanner cannot be resolved, or a class maps to more than one file.
	 *
	 * @return  array<int, array{class: string, path: string}> Class-map entries (fully-qualified name + dependencies-relative path).
	 */
	private static function scan_classmap_roots( array $scan_roots, string $dependencies_dir ): array {
		if ( array() === $scan_roots ) {
			return array();
		}
		if ( ! \class_exists( \Composer\ClassMapGenerator\ClassMapGenerator::class ) ) {
			throw new \RuntimeException( "Composer's class-map scanner is unavailable; cannot resolve autoload.classmap entries." );
		}

		$scanner = new \Composer\ClassMapGenerator\ClassMapGenerator();
		$scanner->avoidDuplicateScans();
		foreach ( $scan_roots as $scan_root ) {
			$scanner->scanPaths( $scan_root );
		}

		$class_map = $scanner->getClassMap();

		// false disables the default test/fixture/example/stub path filter, so EVERY duplicate
		// fails loud — no first-scanned-file resolution by filesystem order leaks in.
		$ambiguous = $class_map->getAmbiguousClasses( false );
		if ( array() !== $ambiguous ) {
			$ambiguous_classes = \array_keys( $ambiguous );
			\sort( $ambiguous_classes );
			throw new \RuntimeException(
				\sprintf(
					'Ambiguous class definitions across scoped autoload.classmap entries: %s. Each class must map to a single file.',
					\implode( ', ', $ambiguous_classes )
				)
			);
		}

		$classmap = array();
		foreach ( $class_map->getMap() as $fqcn => $class_file ) {
			$classmap[] = array(
				'class' => $fqcn,
				'path'  => self::relative_path( $dependencies_dir, $class_file ),
			);
		}
		return $classmap;
	}

	/**
	 * Computes the path of `$target` relative to `$base`, normalised with forward
	 * slashes so the generated `__DIR__ . '/<...>'` references work cross-platform.
	 *
	 * @param   string $base   Absolute base path.
	 * @param   string $target Absolute path to express relative to `$base`.
	 *
	 * @return  string
	 */
	private static function relative_path( string $base, string $target ): string {
		$base   = self::normalise( $base );
		$target = self::normalise( $target );

		if ( 0 === \strncmp( $target, $base . '/', \strlen( $base ) + 1 ) ) {
			return \substr( $target, \strlen( $base ) + 1 );
		}
		return $target;
	}

	/**
	 * Normalises a filesystem path to forward slashes and strips trailing slashes.
	 *
	 * @param   string $path Path to normalise.
	 *
	 * @return  string
	 */
	private static function normalise( string $path ): string {
		return \rtrim( \str_replace( '\\', '/', $path ), '/' );
	}

	/**
	 * Joins a package-relative subpath onto the package's dependencies-relative path.
	 *
	 * @param   string $pkg_rel  Package directory relative to dependencies/.
	 * @param   string $sub_path Path inside the package.
	 *
	 * @return  string
	 */
	private static function join_rel( string $pkg_rel, string $sub_path ): string {
		$sub = \trim( \str_replace( '\\', '/', $sub_path ), '/' );
		return '' === $sub ? $pkg_rel : $pkg_rel . '/' . $sub;
	}

	/**
	 * Strips trailing backslashes from a PSR-4 namespace key for clean joining.
	 *
	 * @param   string $psr4_namespace Namespace string with or without trailing backslashes.
	 *
	 * @return  string
	 */
	private static function rtrim_backslash( string $psr4_namespace ): string {
		return \rtrim( $psr4_namespace, '\\' );
	}

	/**
	 * Renders the generated PHP source. Entries are sorted for deterministic diffs.
	 *
	 * @param   array<int, array{namespace: string, path: string}> $psr4     PSR-4 entries to emit.
	 * @param   array<int, string>                                 $files    Files to require at bootstrap.
	 * @param   array<int, array{class: string, path: string}>     $classmap Class-map entries to emit.
	 *
	 * @return  string
	 */
	private static function render( array $psr4, array $files, array $classmap ): string {
		\usort(
			$psr4,
			static fn( array $a, array $b ): int => \strcmp( $a['namespace'], $b['namespace'] ) ?: \strcmp( $a['path'], $b['path'] )
		);
		\usort(
			$classmap,
			static fn( array $a, array $b ): int => \strcmp( $a['class'], $b['class'] ) ?: \strcmp( $a['path'], $b['path'] )
		);
		// Files are sorted for byte-identical regeneration. This does NOT preserve Composer's
		// dependency-load order, so a scoped package whose autoload.files relies on another scoped
		// package's files loading first is unsupported — no framework package has such a
		// cross-package file dependency; revisit if a consumer ever needs explicit ordering.
		\sort( $files );

		$psr4_lines     = array();
		$classmap_lines = array();
		$file_lines     = array();

		// var_export emits a safely-escaped single-quoted literal, so a namespace or path that
		// contains a quote cannot break out of — or inject into — the generated source.
		foreach ( $psr4 as $entry ) {
			$psr4_lines[] = \sprintf(
				'$loader->addPsr4( %s, __DIR__ . %s );',
				\var_export( $entry['namespace'], true ),
				\var_export( '/' . $entry['path'], true )
			);
		}
		foreach ( $classmap as $entry ) {
			$classmap_lines[] = \sprintf(
				'%s => __DIR__ . %s,',
				\var_export( $entry['class'], true ),
				\var_export( '/' . $entry['path'], true )
			);
		}
		foreach ( $files as $entry ) {
			$file_lines[] = \sprintf( 'require_once __DIR__ . %s;', \var_export( '/' . $entry, true ) );
		}

		$body  = "<?php declare( strict_types=1 );\n";
		$body .= "\n";
		$body .= "// Generated by GenerateScopedAutoload. Regenerated every scoping run — do not edit.\n";

		if ( array() !== $psr4_lines || array() !== $classmap_lines ) {
			// A dedicated loader registers the scoped PSR-4/classmap mappings independently of
			// which Composer loader happens to be first in the global registry — in a multi-plugin
			// request the host plugin's loader is not guaranteed to be that one.
			$body .= "\n";
			$body .= "\$loader = new \\Composer\\Autoload\\ClassLoader();\n";

			if ( array() !== $psr4_lines ) {
				$body .= "\n";
				foreach ( $psr4_lines as $line ) {
					$body .= $line . "\n";
				}
			}

			if ( array() !== $classmap_lines ) {
				$body .= "\n";
				$body .= "\$loader->addClassMap( array(\n";
				foreach ( $classmap_lines as $line ) {
					$body .= "\t" . $line . "\n";
				}
				$body .= ") );\n";
			}

			$body .= "\n";
			$body .= "\$loader->register();\n";
		}

		if ( array() !== $file_lines ) {
			$body .= "\n";
			foreach ( $file_lines as $line ) {
				$body .= $line . "\n";
			}
		}

		return $body;
	}
}
