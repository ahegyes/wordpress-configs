<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\InstalledVersions;
use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Scopes a project's dependencies into its vendor-prefixed directory with php-scoper and writes their autoloader.
 */
final class ScopePhpDependencies {
	// region METHODS

	/**
	 * Scopes the dependencies of the project Composer runs in.
	 *
	 * @param   Event $event The Composer script event.
	 *
	 * @throws  \RuntimeException Thrown when the scope run fails.
	 */
	public static function run( Event $event ): void {
		$vendor_dir = $event->getComposer()->getConfig()->get( 'vendor-dir' );

		self::scope( \dirname( \is_string( $vendor_dir ) ? $vendor_dir : '' ) );
	}

	/**
	 * Scopes the dependencies after a development install only, so a production install keeps the scoped output it ships.
	 *
	 * @param   Event $event The Composer script event.
	 *
	 * @throws  \RuntimeException Thrown when the scope run fails.
	 */
	public static function postAutoloadDump( Event $event ): void {
		if ( $event->isDevMode() ) {
			self::run( $event );
		}
	}

	/**
	 * Scopes a project's dependencies into its vendor-prefixed directory with the project's scoper.inc.php, then writes vendor-prefixed/scoper-autoload.php.
	 *
	 * @param   string $project_dir The root directory of the project.
	 *
	 * @throws  \RuntimeException Thrown when the project or php-scoper is missing, vendor-prefixed is a symbolic link, or php-scoper fails.
	 */
	public static function scope( string $project_dir ): void {
		$project_dir = \realpath( $project_dir ) ?: throw new \RuntimeException( "The project directory '$project_dir' does not exist." );

		$output_dir = $project_dir . '/vendor-prefixed';
		if ( \is_link( $output_dir ) ) {
			throw new \RuntimeException( "Refusing to replace '$output_dir', which is a symbolic link." );
		}
		if ( ! InstalledVersions::isInstalled( 'humbug/php-scoper' ) ) {
			throw new \RuntimeException( 'Install humbug/php-scoper to scope dependencies.' );
		}

		$filesystem = new Filesystem();
		$filesystem->remove( $output_dir );
		$filesystem->dumpFile( $output_dir . '/scoper-autoload.php', "<?php\n" ); // The project's autoloader requires this file, and php-scoper boots through that autoloader.

		// Loading the config parses the WooCommerce stubs, which comes close to PHP's default memory limit.
		$command = array( \PHP_BINARY, '-d', 'memory_limit=1G', ( InstalledVersions::getInstallPath( 'humbug/php-scoper' ) ?? '' ) . '/bin/php-scoper', 'add-prefix', '--working-dir=' . $project_dir, '--config=' . $project_dir . '/scoper.inc.php', '--force', '--no-interaction' );
		\exec( \implode( ' ', \array_map( 'escapeshellarg', $command ) ) . ' 2>&1', $output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Runs in Composer, never on a WordPress server.
		if ( 0 !== $exit_code ) {
			throw new \RuntimeException( "php-scoper failed:\n" . \implode( "\n", $output ) );
		}

		$filesystem->dumpFile( $output_dir . '/scoper-autoload.php', self::autoload( $output_dir ) );
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the autoloader of the scoped packages, which registers their psr-4 and classmap entries on a class loader of its own and requires their files entries.
	 *
	 * @param   string $output_dir The vendor-prefixed directory.
	 *
	 * @throws  \RuntimeException Thrown when no scoped package manifest exists or one cannot be read.
	 *
	 * @return  string
	 */
	protected static function autoload( string $output_dir ): string {
		$manifests = \glob( $output_dir . '/*/*/composer.json' );
		if ( false === $manifests || array() === $manifests ) {
			throw new \RuntimeException( "No scoped package exists in '$output_dir'." );
		}

		$psr4     = '';
		$classmap = array();
		$files    = '';
		foreach ( $manifests as $manifest ) {
			$package_dir = \dirname( $manifest );
			$relative    = \substr( $package_dir, \strlen( $output_dir ) );
			$contents    = \file_get_contents( $manifest );
			if ( false === $contents ) {
				throw new \RuntimeException( "Could not read '$manifest'." );
			}
			$decoded  = \json_decode( $contents, true, flags: \JSON_THROW_ON_ERROR );
			$autoload = \is_array( $decoded ) && isset( $decoded['autoload'] ) && \is_array( $decoded['autoload'] ) ? $decoded['autoload'] : array();

			foreach ( (array) ( $autoload['psr-4'] ?? array() ) as $namespace => $paths ) {
				foreach ( self::strings( $paths ) as $path ) {
					$psr4 .= \sprintf( "\$loader->addPsr4( %s, __DIR__ . %s );\n", \var_export( (string) $namespace, true ), \var_export( "$relative/$path", true ) );
				}
			}
			foreach ( self::strings( $autoload['classmap'] ?? array() ) as $path ) {
				foreach ( ClassMapGenerator::createMap( "$package_dir/$path" ) as $class => $file ) {
					$classmap[ $class ] = \substr( $file, \strlen( $output_dir ) );
				}
			}
			foreach ( self::strings( $autoload['files'] ?? array() ) as $path ) {
				$files .= \sprintf( "require_once __DIR__ . %s;\n", \var_export( "$relative/$path", true ) );
			}
		}

		\ksort( $classmap );
		$classes = '';
		foreach ( $classmap as $class => $file ) {
			$classes .= \sprintf( "\t%s => __DIR__ . %s,\n", \var_export( $class, true ), \var_export( $file, true ) );
		}

		return "<?php declare( strict_types=1 );\n\n// Generated by ScopePhpDependencies on every scope run.\n\n\$loader = new \\Composer\\Autoload\\ClassLoader();\n$psr4\$loader->addClassMap( array(\n$classes) );\n\$loader->register();\n\n$files";
	}

	/**
	 * Returns the strings of a value that holds one string or a list of them.
	 *
	 * @param   mixed $value The value.
	 *
	 * @return  list<string>
	 */
	protected static function strings( mixed $value ): array {
		return \array_values( \array_filter( (array) $value, 'is_string' ) );
	}

	// endregion
}
