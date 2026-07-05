<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\Script\Event;
use Composer\Util\ProcessExecutor;
use DeepWebSolutions\Config\Composer\Internal\GenerateScopedAutoload;

/**
 * Composer event handlers for the dependency-scoping pipeline.
 *
 * `preAutoloadDump` creates placeholder files/directories for the consumer's
 * `autoload.files`/`autoload.classmap` paths that sit under its
 * `extra.scoped-dependencies-dir`, so the autoloader dump doesn't fail before
 * scoping has populated that not-yet-generated scoped output.
 *
 * `postAutoloadDump` and `run` construct and execute the php-scoper command from
 * the consumer's root `composer.json`: `extra.scoped-dependencies-dir`,
 * `extra.scoping-prefix`, optional `extra.scoping-flags`, and the required
 * root `scoper.inc.php`. A successful scoper run is always followed by scoped
 * autoload generation.
 */
final class ScopePhpDependencies {
	/**
	 * Public pipeline script name consumers bind to `ScopePhpDependencies::run`.
	 *
	 * @var string
	 */
	public const string PIPELINE_SCRIPT = 'scope-php-dependencies';

	/**
	 * Creates placeholders for the scoped `autoload.files`/`classmap` paths before Composer dumps the
	 * autoloader. A fresh clone has not run php-scoper yet, so those paths are missing and the dump
	 * would fatal with a 'file-not-found' error before scoping ever runs.
	 *
	 * @param   Event $event  Composer event object.
	 *
	 * @throws  \JsonException      If the composer.json file cannot be parsed.
	 * @throws  \RuntimeException   If composer.json cannot be read, or a file or directory cannot be created.
	 *
	 * @return  void
	 */
	public static function preAutoloadDump( Event $event ): void {
		$console_io    = $event->getIO();
		$composer_file = \Composer\Factory::getComposerFile();
		$project_dir   = \dirname( $composer_file );

		$composer_contents = \file_get_contents( $composer_file ) ?: throw new \RuntimeException( \sprintf( 'Could not read composer.json at %s', $composer_file ) );
		$composer_config   = \json_decode( $composer_contents, true, flags: JSON_THROW_ON_ERROR );

		// Placeholders cover only the not-yet-generated scoped output under the consumer's
		// scoped-dependencies-dir. A consumer that does not scope has nothing to pre-create, and
		// placeholdering its real autoload paths would mask a typo as an empty file.
		$scoped_dir = $composer_config['extra']['scoped-dependencies-dir'] ?? null;
		if ( ! \is_string( $scoped_dir ) || '' === $scoped_dir ) {
			return;
		}
		self::assert_project_relative( $scoped_dir );
		$scoped_dir_normalised = \rtrim( self::normalise_for_match( $scoped_dir ), '/' );

		$console_io->write( 'Making sure scoped autoload paths exist...' );

		$autoloaded_files       = $composer_config['autoload']['files'] ?? array();
		$autoloaded_directories = $composer_config['autoload']['classmap'] ?? array();
		if ( $event->isDevMode() ) {
			$autoloaded_files       = \array_merge( $autoloaded_files, $composer_config['autoload-dev']['files'] ?? array() );
			$autoloaded_directories = \array_merge( $autoloaded_directories, $composer_config['autoload-dev']['classmap'] ?? array() );
		}

		foreach ( $autoloaded_files as $file ) {
			if ( ! \is_string( $file ) || ! self::is_under_scoped_dir( $file, $scoped_dir_normalised ) ) {
				continue;
			}
			self::assert_project_relative( $file );

			$file = $project_dir . DIRECTORY_SEPARATOR . $file;
			if ( ! \file_exists( $file ) ) {
				$file_directory = \dirname( $file );
				\is_dir( $file_directory ) || \mkdir( $file_directory, 0755, true ) || \is_dir( $file_directory ) || throw new \RuntimeException( \sprintf( 'Directory "%s" was not created', $file_directory ) );
				\touch( $file ) || throw new \RuntimeException( \sprintf( 'File "%s" was not created', $file ) );
			}
		}

		foreach ( $autoloaded_directories as $directory ) {
			if ( ! \is_string( $directory ) || ! self::is_under_scoped_dir( $directory, $scoped_dir_normalised ) ) {
				continue;
			}
			self::assert_project_relative( $directory );

			$directory = $project_dir . DIRECTORY_SEPARATOR . $directory;
			\is_dir( $directory ) || \mkdir( $directory, 0755, true ) || \is_dir( $directory ) || throw new \RuntimeException( \sprintf( 'Directory "%s" was not created', $directory ) );
		}
	}

	/**
	 * Reports whether an autoload entry sits at or under the scoped-dependencies-dir.
	 *
	 * @param   string $path                  Autoload entry from `composer.json`.
	 * @param   string $scoped_dir_normalised Scoped-dependencies-dir, forward-slashed, no trailing slash.
	 *
	 * @return  bool
	 */
	private static function is_under_scoped_dir( string $path, string $scoped_dir_normalised ): bool {
		$normalised = self::normalise_for_match( $path );

		return $normalised === $scoped_dir_normalised || \str_starts_with( $normalised, $scoped_dir_normalised . '/' );
	}

	/**
	 * Forward-slashes a path and strips a single leading `./`.
	 *
	 * @param   string $path Path to normalise.
	 *
	 * @return  string
	 */
	private static function normalise_for_match( string $path ): string {
		$normalised = \str_replace( '\\', '/', $path );

		return \str_starts_with( $normalised, './' ) ? \substr( $normalised, 2 ) : $normalised;
	}

	/**
	 * Rejects autoload entries that escape the project root via absolute paths or parent-directory traversal.
	 *
	 * @param   string $path Autoload entry from `composer.json`.
	 *
	 * @throws  \RuntimeException If `$path` is absolute or contains a `..` segment.
	 *
	 * @return  void
	 */
	private static function assert_project_relative( string $path ): void {
		$normalised = \str_replace( '\\', '/', $path );
		if ( \str_starts_with( $normalised, '/' ) || 1 === \preg_match( '#^[A-Za-z]:/#', $normalised ) ) {
			throw new \RuntimeException( \sprintf( 'Refusing absolute autoload path "%s" - must be project-relative.', $path ) );
		}

		foreach ( \explode( '/', $normalised ) as $segment ) {
			if ( '..' === $segment ) {
				throw new \RuntimeException( \sprintf( 'Refusing autoload path "%s" - parent-directory traversal not allowed.', $path ) );
			}
		}
	}

	/**
	 * Scopes dependencies in dev mode when php-scoper is installed and the consumer declares
	 * `extra.scoped-dependencies-dir`.
	 *
	 * @param   Event $event  Composer event object.
	 *
	 * @throws  \RuntimeException  If the consumer's scoping configuration is inconsistent, php-scoper fails, or scoped-autoload generation fails.
	 *
	 * @return  void
	 */
	public static function postAutoloadDump( Event $event ): void {
		$console_io = $event->getIO();
		$scoper_bin = self::scoper_binary( $event );

		if ( ! $event->isDevMode() ) {
			$console_io->warning( 'Not scoping dependencies because this is not a development environment.' );
			return;
		}
		if ( ! \is_file( $scoper_bin ) ) {
			$console_io->write( 'Not scoping dependencies because the PHP scoper is not installed.' );
			return;
		}

		$scoped_dir = self::resolve_scoped_dir( $event );
		if ( null === $scoped_dir ) {
			$console_io->write( 'Not scoping dependencies because extra.scoped-dependencies-dir is not declared.' );
			return;
		}

		self::scope_and_generate( $event, $scoped_dir, $scoper_bin );
	}

	/**
	 * Public entry point for the consumer's `scope-php-dependencies` script.
	 *
	 * @param   Event $event  Composer event object.
	 *
	 * @throws  \RuntimeException  If php-scoper is not installed, the scoping configuration is inconsistent, php-scoper fails, or scoped-autoload generation fails.
	 *
	 * @return  void
	 */
	public static function run( Event $event ): void {
		$scoper_bin = self::scoper_binary( $event );

		if ( ! \is_file( $scoper_bin ) ) {
			throw new \RuntimeException( 'Cannot scope dependencies - humbug/php-scoper is not installed (require-dev it and run a dev-mode composer install).' );
		}

		$scoped_dir = self::resolve_scoped_dir( $event );
		if ( null === $scoped_dir ) {
			throw new \RuntimeException(
				\sprintf(
					'Missing extra.scoped-dependencies-dir - declare the project-relative scoped output directory before running "%s".',
					self::PIPELINE_SCRIPT
				)
			);
		}

		self::scope_and_generate( $event, $scoped_dir, $scoper_bin );
	}

	/**
	 * Executes php-scoper, then regenerates the scoped autoload from the scoped output tree.
	 *
	 * @param   Event  $event      Composer event object.
	 * @param   string $scoped_dir Validated `extra.scoped-dependencies-dir`.
	 * @param   string $scoper_bin Absolute path to `vendor/bin/php-scoper`.
	 *
	 * @throws  \RuntimeException If the scoping config is invalid, php-scoper fails, or the autoload generator fails.
	 *
	 * @return  void
	 */
	private static function scope_and_generate( Event $event, string $scoped_dir, string $scoper_bin ): void {
		$console_io       = $event->getIO();
		$project_dir      = \dirname( \Composer\Factory::getComposerFile() );
		$dependencies_dir = $project_dir . DIRECTORY_SEPARATOR . \ltrim( $scoped_dir, '/\\' );
		$config_file      = $project_dir . DIRECTORY_SEPARATOR . 'scoper.inc.php';

		$prefix = self::resolve_scoping_prefix( $event );
		$flags  = self::resolve_scoping_flags( $event );
		self::assert_scoper_config_exists( $config_file );

		$console_io->write( 'Scoping dependencies...' );

		$process   = new ProcessExecutor( $console_io );
		$command   = self::build_scoper_command( $scoper_bin, $prefix, $config_file, $dependencies_dir, $flags );
		$output    = null;
		$exit_code = $process->execute( $command, $output, $project_dir );

		if ( 0 !== $exit_code ) {
			$message = \sprintf( 'php-scoper failed with exit code %d; not generating the scoped autoload.', $exit_code );
			$stderr  = \trim( $process->getErrorOutput() );
			if ( '' !== $stderr ) {
				$message .= "\n" . $stderr;
			}

			throw new \RuntimeException( $message );
		}

		$output_path = GenerateScopedAutoload::generate( $dependencies_dir );
		$console_io->write( \sprintf( 'Wrote %s', $output_path ) );
	}

	/**
	 * Builds the argv vector for php-scoper.
	 *
	 * @param   string       $scoper_bin       Absolute path to `vendor/bin/php-scoper`.
	 * @param   string       $prefix           Scoping namespace prefix.
	 * @param   string       $config_file      Absolute path to `scoper.inc.php`.
	 * @param   string       $dependencies_dir Absolute output directory.
	 * @param   list<string> $flags            Extra php-scoper flags from `extra.scoping-flags`.
	 *
	 * @return  non-empty-list<string>
	 */
	private static function build_scoper_command( string $scoper_bin, string $prefix, string $config_file, string $dependencies_dir, array $flags ): array {
		return \array_merge(
			array(
				PHP_BINARY,
				$scoper_bin,
				'add-prefix',
				'--prefix=' . $prefix,
				'--config=' . $config_file,
				'--output-dir=' . $dependencies_dir,
				'--force',
				'--quiet',
			),
			$flags
		);
	}

	/**
	 * Returns the php-scoper binary path under the Composer vendor directory.
	 *
	 * @param   Event $event Composer event object.
	 *
	 * @return  string
	 */
	private static function scoper_binary( Event $event ): string {
		$vendor_dir = $event->getComposer()->getConfig()->get( 'vendor-dir' );

		return $vendor_dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php-scoper';
	}

	/**
	 * Returns the root package's `extra` metadata.
	 *
	 * @param   Event $event Composer event object.
	 *
	 * @return  array<array-key, mixed>
	 */
	private static function root_extra( Event $event ): array {
		return $event->getComposer()->getPackage()->getExtra();
	}

	/**
	 * Resolves `extra.scoped-dependencies-dir` to a validated project-relative path, or null
	 * when absent/empty.
	 *
	 * @param   Event $event Composer event object.
	 *
	 * @throws  \RuntimeException If the declared path is absolute or contains a `..` segment.
	 *
	 * @return  string|null
	 */
	private static function resolve_scoped_dir( Event $event ): ?string {
		$scoped_dir = self::root_extra( $event )['scoped-dependencies-dir'] ?? null;
		if ( ! \is_string( $scoped_dir ) || '' === $scoped_dir ) {
			return null;
		}

		self::assert_project_relative( $scoped_dir );

		return $scoped_dir;
	}

	/**
	 * Resolves and validates the required scoping prefix.
	 *
	 * @param   Event $event Composer event object.
	 *
	 * @throws  \RuntimeException If `extra.scoping-prefix` is missing or not a valid namespace prefix.
	 *
	 * @return  string
	 */
	private static function resolve_scoping_prefix( Event $event ): string {
		$prefix = self::root_extra( $event )['scoping-prefix'] ?? null;
		if ( ! \is_string( $prefix ) ) {
			throw new \RuntimeException( 'Missing extra.scoping-prefix - declare the PHP namespace prefix passed to php-scoper (for example, "MyPlugin\\\\Scoped").' );
		}

		$prefix = \trim( $prefix, '\\' );
		if ( '' === $prefix || 1 !== \preg_match( '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $prefix ) ) {
			throw new \RuntimeException(
				\sprintf(
					'Invalid extra.scoping-prefix "%s" - expected a non-empty, valid PHP namespace prefix such as "MyPlugin\\\\Scoped".',
					$prefix
				)
			);
		}

		return $prefix;
	}

	/**
	 * Resolves and validates extra php-scoper CLI flags.
	 *
	 * @param   Event $event Composer event object.
	 *
	 * @throws  \RuntimeException If `extra.scoping-flags` is malformed or tries to override pipeline-owned flags.
	 *
	 * @return  list<string>
	 */
	private static function resolve_scoping_flags( Event $event ): array {
		$extra = self::root_extra( $event );
		if ( ! \array_key_exists( 'scoping-flags', $extra ) ) {
			return array();
		}

		$flags = $extra['scoping-flags'];
		if ( ! \is_array( $flags ) ) {
			throw new \RuntimeException(
				\sprintf( 'extra.scoping-flags must be a list of strings; got %s.', \get_debug_type( $flags ) )
			);
		}
		if ( ! \array_is_list( $flags ) ) {
			throw new \RuntimeException( 'extra.scoping-flags must be a list of strings; associative keys are not supported.' );
		}

		foreach ( $flags as $flag ) {
			if ( ! \is_string( $flag ) ) {
				throw new \RuntimeException(
					\sprintf( 'extra.scoping-flags must contain only strings; got %s.', \get_debug_type( $flag ) )
				);
			}
			if ( self::is_pipeline_owned_flag( $flag ) ) {
				throw new \RuntimeException(
					\sprintf( 'extra.scoping-flags entry "%s" is not allowed because the pipeline owns the output-dir, prefix, and config flags (long and short forms).', $flag )
				);
			}
		}

		return $flags;
	}

	/**
	 * Reports whether a CLI flag is owned by the pipeline rather than the consumer.
	 *
	 * @param   string $flag CLI flag from `extra.scoping-flags`.
	 *
	 * @return  bool
	 */
	private static function is_pipeline_owned_flag( string $flag ): bool {
		foreach ( array( '--output-dir', '--prefix', '--config' ) as $owned ) {
			if ( $flag === $owned || \str_starts_with( $flag, $owned . '=' ) ) {
				return true;
			}
		}

		// Short forms too: -o/-p/-c take a glued or separate value, so any flag starting
		// with one of them is a pipeline-owned knob.
		foreach ( array( '-o', '-p', '-c' ) as $owned_short ) {
			if ( \str_starts_with( $flag, $owned_short ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Requires the consumer's scoper config to be explicit.
	 *
	 * @param   string $config_file Absolute path to `scoper.inc.php`.
	 *
	 * @throws  \RuntimeException If the config file does not exist.
	 *
	 * @return  void
	 */
	private static function assert_scoper_config_exists( string $config_file ): void {
		if ( \is_file( $config_file ) ) {
			return;
		}

		throw new \RuntimeException(
			\sprintf(
				'Cannot scope dependencies - missing %s. Create scoper.inc.php at the project root; php-scoper without an explicit config would scope the whole project.',
				$config_file
			)
		);
	}
}
