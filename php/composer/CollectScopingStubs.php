<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\InstalledVersions;
use DeepWebSolutions\Config\Composer\Internal\StubSymbolCollector;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Collects the WordPress, Action Scheduler and stub-declared symbols that scoping leaves global.
 */
final class CollectScopingStubs {
	// region METHODS

	/**
	 * Returns the WordPress symbols, the Action Scheduler patterns and the symbols of every stubs file the given packages declare under extra.scoping-stubs.
	 *
	 * @param   list<string> $package_dirs The directories of the packages to scope.
	 *
	 * @throws  \RuntimeException Thrown when a declared stubs file is not installed or a file cannot be read.
	 *
	 * @return  array{classes: list<string>, functions: list<string>, constants: list<string>}
	 */
	public static function collect( array $package_dirs ): array {
		$wordpress = ( InstalledVersions::getInstallPath( 'sniccowp/php-scoper-wordpress-excludes' ) ?? '' ) . '/generated/exclude-wordpress-';
		$symbols   = array(
			'classes'   => array( '/^ActionScheduler/', ...self::read_list( $wordpress . 'classes.json' ), ...self::read_list( $wordpress . 'interfaces.json' ), ...self::read_list( $wordpress . 'traits.json' ) ),
			'functions' => array( '/^as_/', ...self::read_list( $wordpress . 'functions.json' ) ),
			'constants' => self::read_list( $wordpress . 'constants.json' ),
		);

		$parser = new ParserFactory()->createForNewestSupportedVersion();
		foreach ( $package_dirs as $package_dir ) {
			foreach ( self::declared_stubs( $package_dir ) as $stubs_file ) {
				$collector = new StubSymbolCollector();
				new NodeTraverser( new NameResolver(), $collector )->traverse( $parser->parse( self::read( $stubs_file ) ) ?? array() );

				$symbols['classes']   = array( ...$symbols['classes'], ...$collector->classes );
				$symbols['functions'] = array( ...$symbols['functions'], ...$collector->functions );
				$symbols['constants'] = array( ...$symbols['constants'], ...$collector->constants );
			}
		}

		return $symbols;
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the stubs files a package declares, each as vendor/package for its conventional vendor/package/package.php file or as vendor/package:path.php.
	 *
	 * @param   string $package_dir The directory of the package.
	 *
	 * @throws  \RuntimeException Thrown when a declared stubs file is not installed or the package manifest cannot be read.
	 *
	 * @return  list<string>
	 */
	protected static function declared_stubs( string $package_dir ): array {
		$manifest = \json_decode( self::read( $package_dir . '/composer.json' ), true, flags: \JSON_THROW_ON_ERROR );
		$extra    = \is_array( $manifest ) && isset( $manifest['extra'] ) && \is_array( $manifest['extra'] ) ? $manifest['extra'] : array();

		$stubs_files = array();
		foreach ( (array) ( $extra['scoping-stubs'] ?? array() ) as $entry ) {
			$parts   = \explode( ':', \is_string( $entry ) ? $entry : '', 2 );
			$package = $parts[0];
			$file    = $parts[1] ?? \basename( $package ) . '.php';
			$install = InstalledVersions::isInstalled( $package ) ? InstalledVersions::getInstallPath( $package ) : null;
			$path    = \is_null( $install ) ? '' : $install . '/' . $file;
			if ( ! \is_file( $path ) ) {
				throw new \RuntimeException( \sprintf( "'%s/composer.json' declares the scoping stubs %s, which are not installed.", $package_dir, \var_export( $entry, true ) ) );
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
