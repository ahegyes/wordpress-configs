<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\Factory;
use Composer\InstalledVersions;
use Composer\Script\Event;
use DeepWebSolutions\Config\Composer\Internal\ScopedSymbolCollector;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Scopes a project's dependencies into its vendor-prefixed directory with php-scoper and writes their autoloader.
 */
final class ScopePhpDependencies {
	// region METHODS

	/**
	 * Scopes the dependencies of the project Composer runs in, after a development install only, so a production install keeps the scoped output it ships.
	 *
	 * @param   Event $event The Composer script event.
	 *
	 * @throws  \RuntimeException Thrown when the scope run fails.
	 */
	public static function postAutoloadDump( Event $event ): void {
		if ( $event->isDevMode() ) {
			self::scope( \dirname( Factory::getComposerFile() ) );
		}
	}

	/**
	 * Scopes a project's dependencies into its vendor-prefixed directory with the project's scoper.inc.php, writes vendor-prefixed/scoper-autoload.php, then checks that the scoped code declares every prefixed name it references.
	 *
	 * @param   string $project_dir The root directory of the project.
	 *
	 * @throws  \RuntimeException Thrown when the project, its scoping prefix or php-scoper is missing, vendor-prefixed is a symbolic link, php-scoper fails, a prefixed name is declared by no scoped package, or a scoped package manifest cannot be read.
	 */
	public static function scope( string $project_dir ): void {
		$project_dir = \realpath( $project_dir ) ?: throw new \RuntimeException( "The project directory '$project_dir' does not exist." );
		$prefix      = self::extra( $project_dir . '/composer.json' )['scoping-prefix'] ?? null;
		$prefix      = \is_string( $prefix ) ? \trim( $prefix, '\\' ) : '';
		if ( '' === $prefix ) {
			throw new \RuntimeException( "'$project_dir/composer.json' declares no extra.scoping-prefix." );
		}

		$output_dir = $project_dir . '/vendor-prefixed';
		if ( \is_link( $output_dir ) ) {
			throw new \RuntimeException( "Refusing to replace '$output_dir', which is a symbolic link." );
		}
		if ( ! InstalledVersions::isInstalled( 'humbug/php-scoper' ) ) {
			throw new \RuntimeException( 'Install humbug/php-scoper to scope dependencies.' );
		}

		$filesystem = new Filesystem();
		if ( ! \is_file( $output_dir . '/scoper-autoload.php' ) ) {
			$filesystem->dumpFile( $output_dir . '/scoper-autoload.php', "<?php\n" ); // The project's autoloader requires this file, and php-scoper boots through that autoloader.
		}

		// Loading the config parses the declared stubs files, and large ones exceed PHP's default memory limit.
		$command = array( \PHP_BINARY, '-d', 'memory_limit=1G', ( InstalledVersions::getInstallPath( 'humbug/php-scoper' ) ?? '' ) . '/bin/php-scoper', 'add-prefix', '--working-dir=' . $project_dir, '--config=' . $project_dir . '/scoper.inc.php', '--output-dir=' . $output_dir, '--force', '--no-interaction' );
		// php-scoper's console wraps what it prints at the terminal width, which would split the paths and symbols in a failure message.
		$process = \proc_open( \implode( ' ', \array_map( 'escapeshellarg', $command ) ) . ' 2>&1', array( 1 => array( 'pipe', 'w' ) ), $pipes, null, array( 'COLUMNS' => '4096' ) + \getenv() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs in Composer, never on a WordPress server.
		if ( false === $process ) {
			throw new \RuntimeException( 'Could not start php-scoper.' );
		}
		$output = \stream_get_contents( $pipes[1] );
		if ( 0 !== \proc_close( $process ) ) {
			throw new \RuntimeException( "php-scoper failed:\n" . $output );
		}

		// php-scoper writes every file below the deepest directory its inputs share, which drops the vendor directory of a single-vendor closure.
		$manifests = \glob( $output_dir . '/*/composer.json' );
		if ( false === $manifests ) {
			throw new \RuntimeException( "Could not list '$output_dir'." );
		}
		$dropped = match ( true ) {
			\is_file( $output_dir . '/composer.json' ) => self::package_name( $output_dir . '/composer.json' ),
			array() !== $manifests                     => \dirname( self::package_name( \current( $manifests ) ) ),
			default                                    => '',
		};
		if ( '' !== $dropped ) {
			$filesystem->rename( $output_dir, $output_dir . '.nesting' );
			$filesystem->mkdir( \dirname( $output_dir . '/' . $dropped ) );
			$filesystem->rename( $output_dir . '.nesting', $output_dir . '/' . $dropped );
		}

		// The autoloader goes first, so a project whose scoped code fails the check still loads.
		$filesystem->dumpFile( $output_dir . '/scoper-autoload.php', self::autoload( $output_dir ) );
		self::verify( $output_dir, $prefix );
	}

	// endregion

	// region HELPERS

	/**
	 * Fails when the scoped code references a prefixed name it does not declare, which would fail at runtime: a host symbol no stubs file declares, a dependency outside the scoped packages, or a string php-scoper took for a class name.
	 *
	 * @param   string $output_dir The vendor-prefixed directory.
	 * @param   string $prefix     The scoping prefix.
	 *
	 * @throws  \RuntimeException Thrown when a prefixed name is declared by no scoped package.
	 */
	protected static function verify( string $output_dir, string $prefix ): void {
		$parser    = new ParserFactory()->createForNewestSupportedVersion();
		$collector = new ScopedSymbolCollector( $prefix, $parser );
		foreach ( Finder::create()->files()->in( $output_dir )->name( '*.php' )->sortByName() as $file ) {
			$collector->file = $file->getPathname();
			new NodeTraverser( new NameResolver(), new ParentConnectingVisitor(), $collector )->traverse( $parser->parse( $file->getContents() ) ?? array() );
		}

		$namespaces = array();
		foreach ( $collector->declared as $name ) {
			$namespace = '';
			foreach ( \array_slice( \explode( '\\', $name ), 0, -1 ) as $segment ) {
				$namespace                = \ltrim( "$namespace\\$segment", '\\' );
				$namespaces[ $namespace ] = $namespace;
			}
		}

		$undeclared = array();
		foreach ( $collector->references as $reference ) {
			$name = \strtolower( \rtrim( $reference['name'], '\\' ) );
			if ( ! isset( $collector->declared[ $name ] ) && ! isset( $namespaces[ $name ] ) ) {
				$undeclared[] = $reference['name'] . ' in ' . $reference['location'];
			}
		}
		if ( array() !== $undeclared ) {
			throw new \RuntimeException( "No scoped package declares these prefixed names, which would fail at runtime. Declare the host's stubs under extra.scoping-stubs, scope the package that declares the name, or exclude it in scoper.inc.php:\n" . \implode( "\n", \array_unique( $undeclared ) ) );
		}
	}

	/**
	 * Returns the autoloader of the scoped packages: a class map over each package's psr-4, psr-0 and classmap paths, which serves PEAR-style psr-0 classes too, followed by its files entries.
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

		$classmap = array();
		$includes = array();
		$requires = array();
		foreach ( $manifests as $manifest ) {
			$package_dir = \dirname( $manifest );
			$relative    = \substr( $package_dir, \strlen( $output_dir ) );
			$decoded     = self::manifest( $manifest );
			$autoload    = isset( $decoded['autoload'] ) && \is_array( $decoded['autoload'] ) ? $decoded['autoload'] : array();

			$paths = self::strings( $autoload['classmap'] ?? array() );
			foreach ( array( 'psr-4', 'psr-0' ) as $standard ) {
				foreach ( (array) ( $autoload[ $standard ] ?? array() ) as $namespace_paths ) {
					\array_push( $paths, ...self::strings( $namespace_paths ) );
				}
			}
			$excluded  = self::excluded( $package_dir, self::strings( $autoload['exclude-from-classmap'] ?? array() ) );
			$generator = new ClassMapGenerator();
			foreach ( $paths as $path ) {
				if ( \str_contains( $path, '*' ) || \file_exists( "$package_dir/$path" ) ) { // Composer skips an autoload path a package does not ship.
					$generator->scanPaths( "$package_dir/$path", $excluded );
				}
			}
			foreach ( $generator->getClassMap()->getMap() as $class => $file ) {
				$classmap[ $class ] = \substr( $file, \strlen( $output_dir ) );
			}
			$name              = self::package_name( $manifest );
			$requires[ $name ] = \array_map( 'strval', \array_keys( (array) ( $decoded['require'] ?? array() ) ) );
			$includes[ $name ] = '';
			foreach ( self::strings( $autoload['files'] ?? array() ) as $path ) {
				$includes[ $name ] .= \sprintf( "require_once __DIR__ . %s;\n", \var_export( "$relative/$path", true ) );
			}
		}

		// Composer includes a package's files after those of the packages it requires, which they may call when loaded.
		$files = '';
		while ( array() !== $includes ) {
			$ready  = \array_filter( \array_keys( $includes ), static fn ( string $name ): bool => array() === \array_intersect( $requires[ $name ], \array_keys( $includes ) ) );
			$name   = \current( $ready ) ?: \array_key_first( $includes ); // Packages that require each other keep their name order.
			$files .= $includes[ $name ];
			unset( $includes[ $name ] );
		}

		\ksort( $classmap );
		$classes = '';
		foreach ( $classmap as $class => $file ) {
			$classes .= \sprintf( "\t%s => __DIR__ . %s,\n", \var_export( $class, true ), \var_export( $file, true ) );
		}

		return "<?php declare( strict_types=1 );\n\n// Generated by ScopePhpDependencies on every scope run.\n\n\$loader = new \\Composer\\Autoload\\ClassLoader();\n\$loader->addClassMap( array(\n$classes) );\n\$loader->register();\n\n$files";
	}

	/**
	 * Returns the pattern of a package's exclude-from-classmap paths, with Composer's * and ** wildcards, or null when it excludes none.
	 *
	 * @param   string       $package_dir The directory of the scoped package.
	 * @param   list<string> $paths       The paths, relative to the package.
	 *
	 * @return  non-empty-string|null
	 */
	protected static function excluded( string $package_dir, array $paths ): ?string {
		if ( array() === $paths ) {
			return null;
		}
		$patterns = \array_map(
			static fn ( string $path ): string => \strtr(
				\preg_quote( \trim( (string) \preg_replace( '#^(?:\./)+#', '', \strtr( $path, '\\', '/' ) ), '/' ), '#' ),
				array(
					'\\*\\*' => '.+?',
					'\\*'    => '[^/]+?',
				)
			),
			$paths
		);

		return '#^' . \preg_quote( \strtr( $package_dir, '\\', '/' ), '#' ) . '/(?:' . \implode( '|', $patterns ) . ')(?:$|/)#';
	}

	/**
	 * Returns the extra entries a manifest declares.
	 *
	 * @param   string $file The path of the manifest.
	 *
	 * @throws  \RuntimeException Thrown when the manifest cannot be read.
	 *
	 * @return  array<array-key, mixed>
	 */
	protected static function extra( string $file ): array {
		$extra = self::manifest( $file )['extra'] ?? array();

		return \is_array( $extra ) ? $extra : array();
	}

	/**
	 * Returns the name a scoped package manifest declares.
	 *
	 * @param   string $file The path of the manifest.
	 *
	 * @throws  \RuntimeException Thrown when the manifest cannot be read or declares no name.
	 *
	 * @return  string
	 */
	protected static function package_name( string $file ): string {
		$name = self::manifest( $file )['name'] ?? null;
		if ( ! \is_string( $name ) || ! \str_contains( $name, '/' ) ) {
			throw new \RuntimeException( "'$file' declares no package name." );
		}

		return $name;
	}

	/**
	 * Returns the decoded contents of a package manifest.
	 *
	 * @param   string $file The path of the manifest.
	 *
	 * @throws  \RuntimeException Thrown when the manifest cannot be read.
	 *
	 * @return  array<array-key, mixed>
	 */
	protected static function manifest( string $file ): array {
		$contents = \file_get_contents( $file );
		if ( false === $contents ) {
			throw new \RuntimeException( "Could not read '$file'." );
		}
		$decoded = \json_decode( $contents, true, flags: \JSON_THROW_ON_ERROR );

		return \is_array( $decoded ) ? $decoded : array();
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
