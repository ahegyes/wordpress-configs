<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

/**
 * Emits a single `dependencies/scoper-autoload.php` from each scoped package's
 * `autoload.psr-4` and `autoload.files`. Host plugins reference this one file
 * via their root `autoload.files`; new scoped packages flow in automatically
 * without host composer.json edits.
 *
 * Deterministic: no `class_alias` / `expose-*`. The generated file is purely a
 * function of the scoped tree and the prefix, so it regenerates byte-identical.
 */
final class GenerateScopedAutoload {

	/**
	 * Generates `dependencies/scoper-autoload.php` from the scoped tree.
	 *
	 * Scoped composer.json `autoload.psr-4` keys are already prefixed by php-scoper —
	 * the generator emits them verbatim. `$prefix` is a sanity check; a mismatch
	 * throws to catch pipeline drift between the consumer's prefix declaration and
	 * what php-scoper actually applied.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
	 *
	 * @param   string $dependencies_dir Absolute path to the php-scoper output dir (typically `<project>/dependencies`).
	 * @param   string $prefix           Scoping prefix passed to php-scoper (e.g. `DeepWebSolutions\\InternalComments\\Scoped`).
	 *
	 * @throws  \JsonException     If a scoped composer.json exists but cannot be parsed.
	 * @throws  \RuntimeException  If a scoped composer.json or the output file cannot be read or written, or if a psr-4 key doesn't start with the declared prefix.
	 *
	 * @return  string Absolute path to the generated scoper-autoload.php.
	 */
	public static function generate( string $dependencies_dir, string $prefix ): string {
		$packages = self::find_scoped_packages( $dependencies_dir );

		$prefix_normalised = self::rtrim_backslash( $prefix );

		$psr4  = array();
		$files = array();

		foreach ( $packages as $pkg ) {
			$autoload = self::read_autoload( $pkg );
			if ( array() === $autoload ) {
				continue;
			}

			$pkg_rel = self::relative_path( $dependencies_dir, $pkg );

			// classmap support not implemented; fail loud rather than silently drop pkgs.
			if ( isset( $autoload['classmap'] ) && array() !== $autoload['classmap'] ) {
				throw new \RuntimeException(
					\sprintf(
						'Scoped package %s declares autoload.classmap, which the scoper-autoload generator does not yet support. Convert to psr-4 in the package, or extend GenerateScopedAutoload to scan classmap directories.',
						$pkg_rel
					)
				);
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

		$content = self::render( $psr4, $files );

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
	 * @return  array{psr-4?: array<string, string|list<string>>, classmap?: list<string>, files?: list<string>}
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
	 * @param   array<int, array{namespace: string, path: string}> $psr4  PSR-4 entries to emit.
	 * @param   array<int, string>                                 $files Files to require at bootstrap.
	 *
	 * @return  string
	 */
	private static function render( array $psr4, array $files ): string {
		\usort(
			$psr4,
			static fn( array $a, array $b ): int => \strcmp( $a['namespace'], $b['namespace'] ) ?: \strcmp( $a['path'], $b['path'] )
		);
		\sort( $files );

		$psr4_lines = array();
		$file_lines = array();

		foreach ( $psr4 as $entry ) {
			// Escape each `\` as `\\` for the single-quoted PHP literal we emit.
			$psr4_lines[] = \sprintf(
				"\$loader->addPsr4( '%s', __DIR__ . '/%s' );",
				\str_replace( '\\', '\\\\', $entry['namespace'] ),
				$entry['path']
			);
		}
		foreach ( $files as $entry ) {
			$file_lines[] = \sprintf( "require_once __DIR__ . '/%s';", $entry );
		}

		$body  = "<?php declare( strict_types=1 );\n";
		$body .= "\n";
		$body .= "// Generated by GenerateScopedAutoload. Regenerated every scoping run — do not edit.\n";
		$body .= "\n";
		$body .= "\$loaders = \\Composer\\Autoload\\ClassLoader::getRegisteredLoaders();\n";
		$body .= "if ( array() === \$loaders ) {\n";
		$body .= "\treturn;\n";
		$body .= "}\n";
		$body .= "\$loader = \\reset( \$loaders );\n";

		if ( array() !== $psr4_lines ) {
			$body .= "\n";
			foreach ( $psr4_lines as $line ) {
				$body .= $line . "\n";
			}
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
