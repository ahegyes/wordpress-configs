<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\Factory;
use Composer\InstalledVersions;
use Composer\Script\Event;
use DeepWebSolutions\Config\Composer\Visitors\ScopedSymbolCollector;
use Humbug\PhpScoper\Configuration\RegexChecker;
use JetBrains\PHPStormStub\PhpStormStubsMap;
use PhpParser\Error;
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
	// region MAGIC METHODS

	/**
	 * Constructor.
	 */
	protected function __construct() {}

	// endregion

	// region METHODS

	/**
	 * Scopes the dependencies of the project Composer runs in, after a development install only, so a production install keeps the scoped output it ships.
	 *
	 * @param   Event $event The Composer script event.
	 *
	 * @throws  \RuntimeException Thrown when the scope run fails.
	 * @throws  \JsonException Thrown when a manifest is not valid JSON.
	 *
	 * @return  void
	 */
	public static function postAutoloadDump( Event $event ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Consumer-facing Composer callback name.
		if ( $event->isDevMode() ) {
			self::scope( \dirname( Factory::getComposerFile() ) );
		}
	}

	/**
	 * Scopes a project's dependencies into its vendor-prefixed directory with the project's scoper.inc.php, writes vendor-prefixed/scoper-autoload.php, then checks that the scoped code declares every prefixed name it references.
	 *
	 * @internal
	 *
	 * @param   string $project_dir The root directory of the project.
	 *
	 * @throws  \RuntimeException Thrown when the project, its scoping prefix or php-scoper is missing, vendor-prefixed is a symbolic link, php-scoper fails, a scoped file does not parse, a scoped package declares a class outside the prefix, a prefixed name is declared by no scoped package, or a scoped package manifest cannot be read.
	 * @throws  \JsonException Thrown when a manifest is not valid JSON.
	 *
	 * @return  void
	 */
	public static function scope( string $project_dir ): void {
		$project_dir = \realpath( $project_dir ) ?: throw new \RuntimeException( "The project directory '$project_dir' does not exist." );
		$prefix      = Manifest::scoping_prefix( $project_dir );

		$output_dir  = $project_dir . '/vendor-prefixed';
		$scoping_dir = $output_dir . '.tmp';
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
		$command = array(
			\PHP_BINARY,
			'-d',
			'memory_limit=1G',
			( InstalledVersions::getInstallPath( 'humbug/php-scoper' ) ?? '' ) . '/bin/php-scoper',
			'add-prefix',
			'--working-dir=' . $project_dir,
			'--config=' . $project_dir . '/scoper.inc.php',
			'--output-dir=' . $scoping_dir,
			'--force',
			'--no-interaction',
		);

		// php-scoper's console wraps what it prints at the terminal width, which would split the paths and symbols in a failure message.
		$process = \proc_open( \implode( ' ', \array_map( 'escapeshellarg', $command ) ) . ' 2>&1', array( 1 => array( 'pipe', 'w' ) ), $pipes, null, array( 'COLUMNS' => '4096' ) + \getenv() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs in Composer, never on a WordPress server.
		if ( false === $process ) {
			throw new \RuntimeException( 'Could not start php-scoper.' );
		}

		$output = \stream_get_contents( $pipes[1] );
		if ( 0 !== \proc_close( $process ) ) {
			$filesystem->remove( $scoping_dir ); // php-scoper removes its output when it throws, but not when a fatal error or a signal ends it.
			throw new \RuntimeException( "php-scoper failed:\n" . $output );
		}

		// php-scoper writes every file below the deepest directory its inputs share, which drops the vendor directory of a single-vendor closure.
		$manifests = \glob( $scoping_dir . '/*/composer.json' );
		if ( false === $manifests ) {
			throw new \RuntimeException( "Could not list '$scoping_dir'." );
		}

		$dropped = match ( true ) {
			\is_file( $scoping_dir . '/composer.json' ) => self::package_name( $scoping_dir . '/composer.json' ),
			array() !== $manifests                      => \dirname( self::package_name( \current( $manifests ) ) ),
			default                                     => '',
		};

		// The build replaces the previous one only once php-scoper succeeds, so a failed run keeps the project loading.
		$filesystem->remove( $output_dir );
		$filesystem->mkdir( \dirname( $output_dir . '/' . $dropped ) );
		$filesystem->rename( $scoping_dir, \rtrim( $output_dir . '/' . $dropped, '/' ) );

		// The autoloader goes first, so a project whose scoped code fails the check still loads.
		$package_manifests = \glob( $output_dir . '/*/*/composer.json' );
		if ( false === $package_manifests || array() === $package_manifests ) {
			throw new \RuntimeException( "No scoped package exists in '$output_dir'." );
		}

		$classmap = array();
		$includes = array();
		$requires = array();
		foreach ( $package_manifests as $manifest ) {
			$package_dir = \dirname( $manifest );
			$decoded     = Manifest::read( $manifest );
			$autoload    = isset( $decoded['autoload'] ) && \is_array( $decoded['autoload'] ) ? $decoded['autoload'] : array();

			// A class map over the psr-4, psr-0 and classmap paths serves PEAR-style psr-0 classes too.
			$paths = self::strings( $autoload['classmap'] ?? array() );
			foreach ( array( 'psr-4', 'psr-0' ) as $standard ) {
				foreach ( (array) ( $autoload[ $standard ] ?? array() ) as $namespace_paths ) {
					\array_push( $paths, ...self::strings( $namespace_paths ) );
				}
			}

			$excluded       = null;
			$excluded_paths = self::strings( $autoload['exclude-from-classmap'] ?? array() );
			if ( array() !== $excluded_paths ) {
				// Composer's exclude-from-classmap paths take * and ** wildcards.
				$excluded = '#^' . \preg_quote( \strtr( $package_dir, '\\', '/' ), '#' ) . '/(?:' . \implode(
					'|',
					\array_map(
						static fn ( string $path ): string => \strtr(
							\preg_quote( \trim( (string) \preg_replace( '#^(?:\./)+#', '', \strtr( $path, '\\', '/' ) ), '/' ), '#' ),
							array(
								'\\*\\*' => '.+?',
								'\\*'    => '[^/]+?',
							)
						),
						$excluded_paths
					)
				) . ')(?:$|/)#';
			}

			$generator = new ClassMapGenerator();
			foreach ( $paths as $autoload_path ) {
				if ( \str_contains( $autoload_path, '*' ) || \file_exists( "$package_dir/$autoload_path" ) ) { // Composer skips an autoload path a package does not ship.
					$generator->scanPaths( "$package_dir/$autoload_path", $excluded );
				}
			}

			foreach ( $generator->getClassMap()->getMap() as $class => $file ) {
				$classmap[ $class ] = \substr( $file, \strlen( $output_dir ) );
			}

			$package_name              = self::package_name( $manifest );
			$relative                  = \substr( $package_dir, \strlen( $output_dir ) );
			$requires[ $package_name ] = \array_map( 'strval', \array_keys( (array) ( $decoded['require'] ?? array() ) ) );
			$includes[ $package_name ] = '';
			foreach ( self::strings( $autoload['files'] ?? array() ) as $file_entry ) {
				$includes[ $package_name ] .= \sprintf( "require_once __DIR__ . %s;\n", \var_export( "$relative/$file_entry", true ) );
			}
		}

		// Composer includes a package's files after those of the packages it requires, which they may call when loaded.
		$files = '';
		while ( array() !== $includes ) {
			$ready        = \array_filter( \array_keys( $includes ), static fn ( string $package_name ): bool => array() === \array_intersect( $requires[ $package_name ], \array_keys( $includes ) ) );
			$package_name = \current( $ready ) ?: \array_key_first( $includes ); // Packages that require each other keep their name order.
			$files       .= $includes[ $package_name ];
			unset( $includes[ $package_name ] );
		}

		\ksort( $classmap );
		$classes = '';
		foreach ( $classmap as $class_name => $class_file ) {
			$classes .= \sprintf( "\t%s => __DIR__ . %s,\n", \var_export( $class_name, true ), \var_export( $class_file, true ) );
		}

		$filesystem->dumpFile( $output_dir . '/scoper-autoload.php', "<?php declare( strict_types=1 );\n\n// Generated by ScopePhpDependencies on every scope run.\n\n\$loader = new \\Composer\\Autoload\\ClassLoader();\n\$loader->addClassMap( array(\n$classes) );\n\$loader->register();\n\n$files" );

		// The functions the run leaves global are those the config excludes and those PHP declares, whose map Composer's script autoloader does not load.
		$config     = ( static fn (): mixed => require $project_dir . '/scoper.inc.php' )();
		$stubs_path = InstalledVersions::getInstallPath( 'jetbrains/phpstorm-stubs' ) ?? '';
		require_once $stubs_path . '/PhpStormStubsMap.php';
		$global_functions = array( ...( \is_array( $config ) ? self::strings( $config['exclude-functions'] ?? array() ) : array() ), ...\array_keys( PhpStormStubsMap::FUNCTIONS ) );

		$parser    = new ParserFactory()->createForNewestSupportedVersion();
		$collector = new ScopedSymbolCollector( $prefix, $parser );
		foreach ( Finder::create()->files()->in( $output_dir )->name( '*.php' )->sortByName() as $scoped_file ) {
			$collector->file = $scoped_file->getPathname();
			try {
				$statements = $parser->parse( $scoped_file->getContents() ) ?? array();
			} catch ( Error $error ) {
				throw new \RuntimeException( "Could not parse the scoped file '" . $scoped_file->getPathname() . "': " . $error->getMessage(), 0, $error );
			}

			new NodeTraverser( new NameResolver(), new ParentConnectingVisitor(), $collector )->traverse( $statements );
		}

		// A class outside the prefix collides with the copy the host or another plugin loads, but for the polyfill of a PHP class, which php-scoper aliases to its global name.
		$php_classes = \array_change_key_case( PhpStormStubsMap::CLASSES );
		$outside     = array();
		foreach ( $collector->declared['class'] as $key => $class ) {
			if ( 0 !== \stripos( $class, $prefix . '\\' ) && ! isset( $php_classes[ $key ] ) ) {
				$separator = \strrpos( $class, '\\' );
				$outside[] = false === $separator ? 'the global namespace' : \substr( $class, 0, $separator );
			}
		}

		if ( array() !== $outside ) {
			throw new \RuntimeException( "The scoped packages declare classes in these namespaces outside the prefix, where the copy the host or another plugin loads can replace them. Drop the package that declares one with !vendor/package, or stop excluding the namespace in scoper.inc.php:\n" . \implode( "\n", \array_unique( $outside ) ) );
		}

		$namespaces = array();
		foreach ( \array_merge( ...\array_values( $collector->declared ) ) as $name ) {
			$namespace = '';
			foreach ( \array_slice( \explode( '\\', $name ), 0, -1 ) as $segment ) {
				$namespace                               = \ltrim( "$namespace\\$segment", '\\' );
				$namespaces[ \strtolower( $namespace ) ] = $namespace;
			}
		}

		// php-scoper reads the entries its regex checker accepts as patterns, which it makes case-insensitive, and the rest as names it compares without case.
		$regex_checker = new RegexChecker();
		$names         = array();
		$patterns      = array();
		foreach ( $global_functions as $function ) {
			if ( $regex_checker->isRegexLike( $function ) ) {
				$patterns[] = $function . 'i';
			} else {
				$names[ \strtolower( \trim( $function, '\\ ' ) ) ] = $function;
			}
		}

		// A function_exists() check of the prefixed copy of a function the run leaves global passes, because php-scoper adds one to every function_exists() guard of that function, and it is false while nothing declares the copy.
		$undeclared = array();
		foreach ( $collector->references as $reference ) {
			$function = \ltrim( \substr( \rtrim( $reference['name'], '\\' ), \strlen( $prefix ) + 1 ), '\\' );
			$global   = $reference['checked'] && ( isset( $names[ \strtolower( $function ) ] ) || \array_any( $patterns, static fn ( string $pattern ): bool => 1 === \preg_match( $pattern, $function ) ) );
			$declared = \array_any( $reference['keys'], static fn ( string $key, string $kind ): bool => 'namespace' === $kind ? isset( $namespaces[ $key ] ) : isset( $collector->declared[ $kind ][ $key ] ) );
			if ( ! $global && ! $declared ) {
				$undeclared[] = $reference['name'] . ' in ' . $reference['location'];
			}
		}

		if ( array() !== $undeclared ) {
			throw new \RuntimeException( "No scoped package declares these prefixed names, which would fail at runtime. Declare the host's stubs under extra.scoping-stubs, scope the package that declares the name, or exclude it in scoper.inc.php:\n" . \implode( "\n", \array_unique( $undeclared ) ) );
		}
	}

	// endregion

	// region HELPERS

	/**
	 * Returns the name a scoped package manifest declares.
	 *
	 * @param   string $file The path of the manifest.
	 *
	 * @throws  \RuntimeException Thrown when the manifest cannot be read or declares no name.
	 * @throws  \JsonException Thrown when the manifest is not valid JSON.
	 *
	 * @return  string
	 */
	protected static function package_name( string $file ): string {
		$name = Manifest::read( $file )['name'] ?? null;
		if ( ! \is_string( $name ) || ! \str_contains( $name, '/' ) ) {
			throw new \RuntimeException( "'$file' declares no package name." );
		}

		return $name;
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
