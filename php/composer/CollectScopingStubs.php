<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\InstalledVersions;
use DeepWebSolutions\Config\Composer\Internal\StubSymbolCollector;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/**
 * Collects the WordPress, Action Scheduler, stub-declared and dropped-package symbols that scoping leaves global.
 */
final class CollectScopingStubs {
	// region METHODS

	/**
	 * Returns the WordPress symbols, the Action Scheduler patterns, the symbols of every stubs file the project or a scoped package declares under extra.scoping-stubs, the symbols the dropped packages declare, and a pattern of their namespaces.
	 *
	 * @param   string       $project_dir  The root directory of the project.
	 * @param   list<string> $package_dirs The directories of the packages to scope.
	 * @param   list<string> $dropped_dirs The directories of the packages that stay unscoped.
	 *
	 * @throws  \RuntimeException Thrown when a declared stubs file is missing or a file cannot be read.
	 *
	 * @return  array{classes: list<string>, functions: list<string>, constants: list<string>, namespaces: list<string>}
	 */
	public static function collect( string $project_dir, array $package_dirs, array $dropped_dirs ): array {
		$wordpress = ( InstalledVersions::getInstallPath( 'sniccowp/php-scoper-wordpress-excludes' ) ?? '' ) . '/generated/exclude-wordpress-';
		$symbols   = array(
			'classes'   => array( '/^ActionScheduler/', ...self::read_list( $wordpress . 'classes.json' ), ...self::read_list( $wordpress . 'interfaces.json' ), ...self::read_list( $wordpress . 'traits.json' ) ),
			'functions' => array( '/^as_/', ...self::read_list( $wordpress . 'functions.json' ) ),
			'constants' => self::read_list( $wordpress . 'constants.json' ),
		);

		$files = array();
		foreach ( array( $project_dir, ...$package_dirs ) as $dir ) {
			\array_push( $files, ...self::declared_stubs( $dir ) );
		}
		foreach ( $dropped_dirs as $dir ) {
			foreach ( Finder::create()->files()->in( $dir )->exclude( array( 'tests', 'Tests' ) )->name( '*.php' ) as $file ) {
				$files[] = $file->getPathname();
			}
		}

		$parser = new ParserFactory()->createForNewestSupportedVersion();
		foreach ( \array_unique( $files ) as $file ) {
			$collector = new StubSymbolCollector();
			new NodeTraverser( new NameResolver(), $collector )->traverse( $parser->parse( self::read( $file ) ) ?? array() );

			$symbols['classes']   = array( ...$symbols['classes'], ...$collector->classes );
			$symbols['functions'] = array( ...$symbols['functions'], ...$collector->functions );
			$symbols['constants'] = array( ...$symbols['constants'], ...$collector->constants );
		}

		// php-scoper keeps a class name in a string literal or a namespace import global only when its namespace is excluded, whatever the class list says.
		$namespaces = array();
		foreach ( array( ...$symbols['classes'], ...$symbols['functions'], ...$symbols['constants'] ) as $symbol ) {
			$separator = \strrpos( $symbol, '\\' );
			if ( ! \str_starts_with( $symbol, '/' ) && false !== $separator ) {
				$namespaces[ \preg_quote( \ltrim( \substr( $symbol, 0, $separator ), '\\' ), '/' ) ] = true;
			}
		}
		$symbols['namespaces'] = array() === $namespaces ? array() : array( '/^(?:' . \implode( '|', \array_keys( $namespaces ) ) . ')$/i' );

		return $symbols;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the stubs files a manifest declares: ./path.php relative to the manifest, vendor/package for that package's conventional package.php file, or vendor/package:path.php.
	 *
	 * @param   string $dir The directory of the manifest.
	 *
	 * @throws  \RuntimeException Thrown when a declared stubs file is missing or the manifest cannot be read.
	 *
	 * @return  list<string>
	 */
	protected static function declared_stubs( string $dir ): array {
		$manifest = \json_decode( self::read( $dir . '/composer.json' ), true, flags: \JSON_THROW_ON_ERROR );
		$extra    = \is_array( $manifest ) && isset( $manifest['extra'] ) && \is_array( $manifest['extra'] ) ? $manifest['extra'] : array();

		$stubs_files = array();
		foreach ( (array) ( $extra['scoping-stubs'] ?? array() ) as $entry ) {
			$entry = \is_string( $entry ) ? $entry : '';
			if ( \str_starts_with( $entry, './' ) ) {
				$path = $dir . \substr( $entry, 1 );
			} else {
				$parts   = \explode( ':', $entry, 2 );
				$install = InstalledVersions::isInstalled( $parts[0] ) ? InstalledVersions::getInstallPath( $parts[0] ) : null;
				$path    = \is_null( $install ) ? '' : $install . '/' . ( $parts[1] ?? \basename( $parts[0] ) . '.php' );
			}
			if ( ! \is_file( $path ) ) {
				throw new \RuntimeException( \sprintf( "'%s/composer.json' declares the scoping stubs %s, which do not exist.", $dir, \var_export( $entry, true ) ) );
			}
			$stubs_files[] = $path;
		}

		return $stubs_files;
	}

	/**
	 * Returns the strings of a JSON list file.
	 *
	 * @param   string $file The path of the file.
	 *
	 * @throws  \RuntimeException Thrown when the file cannot be read or holds no list.
	 *
	 * @return  list<string>
	 */
	protected static function read_list( string $file ): array {
		$list = \json_decode( self::read( $file ), true, flags: \JSON_THROW_ON_ERROR );
		if ( ! \is_array( $list ) ) {
			throw new \RuntimeException( "'$file' holds no list." );
		}

		return \array_values( \array_filter( $list, 'is_string' ) );
	}

	/**
	 * Returns the contents of a file.
	 *
	 * @param   string $file The path of the file.
	 *
	 * @throws  \RuntimeException Thrown when the file cannot be read.
	 *
	 * @return  string
	 */
	protected static function read( string $file ): string {
		$contents = \file_get_contents( $file );
		if ( false === $contents ) {
			throw new \RuntimeException( "Could not read '$file'." );
		}

		return $contents;
	}

	// endregion
}
