<?php declare( strict_types=1 );

namespace WordPressConfigs\Composer;

/**
 * Resolves the packages a project scopes from the names it gives and from Composer's record of the installed packages.
 */
final class ScopedPackages {
	// region METHODS

	/**
	 * Returns the directories of the named packages and of their runtime dependencies, and of the packages a name drops.
	 *
	 * Dependencies follow the require entries Composer recorded and never require-dev, so naming a library scopes what it needs at runtime.
	 *
	 * @param   string       $vendor_dir The project's Composer vendor directory.
	 * @param   list<string> $names      The packages to scope, as names or fnmatch() patterns, where a name that starts with ! drops the packages it matches.
	 *
	 * @throws  \RuntimeException Thrown when no name is given, installed.json is unreadable, a name matches no installed package, a dependency is not installed, or a scoped package is a symbolic link.
	 *
	 * @return  array{scoped: array<string, string>, dropped: array<string, string>}
	 */
	public static function resolve( string $vendor_dir, array $names ): array {
		if ( array() === $names ) {
			throw new \RuntimeException( 'Name at least one package to scope.' );
		}

		$installed = self::installed( $vendor_dir );
		$replacers = array();
		$provided  = array();
		foreach ( $installed as $name => $package ) {
			foreach ( \array_keys( (array) ( $package['replace'] ?? array() ) ) as $replaced ) {
				$replacers[ (string) $replaced ][] = $name;
			}
			foreach ( \array_keys( (array) ( $package['provide'] ?? array() ) ) as $virtual ) {
				$provided[ (string) $virtual ] = true;
			}
		}

		$included = array();
		$dropped  = array();
		foreach ( $names as $name ) {
			$pattern = \ltrim( $name, '!' );
			$matches = \array_values( \array_filter( \array_keys( $installed ), static fn ( string $installed_name ): bool => \fnmatch( $pattern, $installed_name ) ) );
			if ( array() === $matches ) {
				throw new \RuntimeException( "'$pattern' matches no package installed in '$vendor_dir'." );
			}
			if ( \str_starts_with( $name, '!' ) ) {
				\array_push( $dropped, ...$matches );
			} else {
				\array_push( $included, ...$matches );
			}
		}

		$scoped = array();
		$queue  = \array_values( \array_diff( $included, $dropped ) );
		while ( array() !== $queue ) {
			$name    = (string) \array_shift( $queue );
			$package = $installed[ $name ];
			if ( isset( $scoped[ $name ] ) || 'composer-plugin' === ( $package['type'] ?? '' ) ) { // Composer plugins run at build time and never ship.
				continue;
			}
			$scoped[ $name ] = $package;

			foreach ( \array_keys( (array) ( $package['require'] ?? array() ) ) as $required ) {
				$required = (string) $required;
				if ( ! \str_contains( $required, '/' ) || \in_array( $required, $dropped, true ) ) { // Platform requirements such as php and ext-json carry no vendor.
					continue;
				}
				$candidates = isset( $installed[ $required ] ) ? array( $required ) : \array_diff( $replacers[ $required ] ?? array(), $dropped );
				// A requirement another package only provides is a virtual package, such as an interface implementation the project picks itself.
				if ( array() === $candidates && ! isset( $provided[ $required ] ) && ! isset( $replacers[ $required ] ) ) {
					throw new \RuntimeException( "'$name' requires '$required', which is not installed in '$vendor_dir'." );
				}
				\array_push( $queue, ...$candidates );
			}
		}
		\ksort( $scoped );

		$directories = array(
			'scoped'  => array(),
			'dropped' => array(),
		);
		foreach ( $scoped as $name => $package ) {
			if ( 'metapackage' !== ( $package['type'] ?? '' ) ) {
				$directories['scoped'][ $name ] = self::directory( $vendor_dir, $name, $package, true );
			}
		}
		foreach ( \array_unique( $dropped ) as $name ) {
			if ( 'metapackage' !== ( $installed[ $name ]['type'] ?? '' ) ) {
				$directories['dropped'][ $name ] = self::directory( $vendor_dir, $name, $installed[ $name ], false );
			}
		}

		return $directories;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the packages Composer recorded in the vendor directory's installed.json, keyed by name.
	 *
	 * @param   string $vendor_dir The project's Composer vendor directory.
	 *
	 * @throws  \RuntimeException Thrown when installed.json is missing or lists no packages.
	 *
	 * @return  array<string, array<array-key, mixed>>
	 */
	protected static function installed( string $vendor_dir ): array {
		$file     = $vendor_dir . '/composer/installed.json';
		$contents = \is_file( $file ) ? \file_get_contents( $file ) : false;
		if ( false === $contents ) {
			throw new \RuntimeException( "Could not read '$file'. Install the project's dependencies first." );
		}
		$decoded = \json_decode( $contents, true, flags: \JSON_THROW_ON_ERROR );
		if ( ! \is_array( $decoded ) || ! \is_array( $decoded['packages'] ?? null ) ) {
			throw new \RuntimeException( "'$file' lists no packages." );
		}

		$installed = array();
		foreach ( $decoded['packages'] as $package ) {
			if ( \is_array( $package ) && \is_string( $package['name'] ?? null ) ) {
				$installed[ $package['name'] ] = $package;
			}
		}

		return $installed;
	}

	/**
	 * Returns the real directory a package is installed in.
	 *
	 * @param   string                  $vendor_dir   The project's Composer vendor directory.
	 * @param   string                  $name         The package name.
	 * @param   array<array-key, mixed> $package      The package's installed.json entry.
	 * @param   bool                    $refuse_links Whether a symbolic link fails the call.
	 *
	 * @throws  \RuntimeException Thrown when the package directory does not exist, or is a symbolic link that $refuse_links forbids.
	 *
	 * @return  string
	 */
	protected static function directory( string $vendor_dir, string $name, array $package, bool $refuse_links ): string {
		$path = $vendor_dir . '/composer/' . ( \is_string( $package['install-path'] ?? null ) ? \rtrim( $package['install-path'], '/' ) : '../' . $name );
		if ( $refuse_links && \is_link( $path ) ) { // php-scoper reads files by their real path, which would put them outside the vendor directory.
			throw new \RuntimeException( "'$path' is a symbolic link. Install path repositories with the symlink option set to false." );
		}

		return \realpath( $path ) ?: throw new \RuntimeException( "'$name' is not installed at '$path'." );
	}

	// endregion
}
