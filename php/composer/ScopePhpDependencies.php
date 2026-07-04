<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\Script\Event;

/**
 * Composer event handlers wrapping the php-scoper invocation.
 *
 * `preAutoloadDump` creates placeholder files/directories for the consumer's
 * `autoload.files`/`autoload.classmap` paths that sit under its
 * `extra.scoped-dependencies-dir`, so the autoloader dump doesn't fail before
 * scoping has populated that not-yet-generated scoped output.
 *
 * `postAutoloadDump` and `run` (the consumer's public `scope-php-dependencies`
 * script) both execute the full pipeline: dispatch the consumer's raw
 * `scope-php-dependencies:raw` script (the `php-scoper add-prefix` invocation),
 * then — when `extra.scoping-prefix` is declared, the autoload generator's
 * opt-in — regenerate the scoped autoload, so a manual scoping run can never
 * leave a stale `scoper-autoload.php` behind. Without the prefix a run scopes
 * but skips generation.
 *
 * `extra.scoped-dependencies-dir` is the single source for the scoped output
 * directory: the pipeline derives php-scoper's `--output-dir` from it and passes
 * the flag to the raw script as a dispatched argument. A raw script that carries
 * its own `--output-dir` is rejected loudly — two knobs for one directory is how
 * the autoload generator ends up scanning a directory php-scoper never wrote.
 */
final class ScopePhpDependencies {
	/**
	 * Public pipeline script name consumers bind to `ScopePhpDependencies::run`.
	 *
	 * @var string
	 */
	public const string PIPELINE_SCRIPT = 'scope-php-dependencies';

	/**
	 * Internal raw script name holding the consumer's bare `php-scoper add-prefix`
	 * invocation (prefix + config + flags — never `--output-dir`).
	 *
	 * @var string
	 */
	public const string RAW_SCOPER_SCRIPT = 'scope-php-dependencies:raw';

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
		// scoped-dependencies-dir. A consumer that does not scope has nothing to pre-create — and
		// placeholdering its real autoload paths would mask a typo as an empty file instead of
		// letting Composer's autoload dump fail loudly on the missing source.
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
	 * Reports whether an autoload entry sits at or under the scoped-dependencies-dir — i.e. is one
	 * of the not-yet-generated scoped paths to pre-create. Tolerates a leading `./` and an exact
	 * directory match, while keeping the `dir/` boundary so `dependencies-foo/` is not mistaken for
	 * `dependencies/`.
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
	 * Forward-slashes a path and strips a single leading `./`, so the scoped dir and the autoload
	 * entries are compared on the same footing whichever spelling composer.json uses.
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
			throw new \RuntimeException( \sprintf( 'Refusing absolute autoload path "%s" — must be project-relative.', $path ) );
		}

		foreach ( \explode( '/', $normalised ) as $segment ) {
			if ( '..' === $segment ) {
				throw new \RuntimeException( \sprintf( 'Refusing autoload path "%s" — parent-directory traversal not allowed.', $path ) );
			}
		}
	}

	/**
	 * Scopes the dependencies — only in dev mode and only when php-scoper is installed — then emits the
	 * optional scoped autoload. php-scoper and the to-be-scoped packages exist only in the dev environment.
	 *
	 * @param   Event $event  Composer event object.
	 *
	 * @throws  \RuntimeException  If the consumer's scoping configuration is inconsistent, the raw scoper script fails, or scoped-autoload generation fails.
	 *
	 * @return  void
	 */
	public static function postAutoloadDump( Event $event ): void {
		$console_io = $event->getIO();
		$vendor_dir = $event->getComposer()->getConfig()->get( 'vendor-dir' );

		if ( ! $event->isDevMode() ) {
			$console_io->warning( 'Not scoping dependencies because this is not a development environment.' );
			return;
		}
		if ( ! \is_file( $vendor_dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php-scoper' ) ) {
			$console_io->write( 'Not scoping dependencies because the PHP scoper is not installed.' );
			return;
		}

		$scripts    = self::root_scripts( $event );
		$scoped_dir = self::resolve_scoped_dir( $event );

		// Runs before the not-opted-in early return so an un-migrated consumer (raw invocation
		// still parked under the public name, extras possibly absent) fails loudly here instead
		// of silently skipping the scoping it used to get.
		self::assert_public_script_is_not_raw_scoper( $scripts );

		if ( ! isset( $scripts[ self::RAW_SCOPER_SCRIPT ] ) && null === $scoped_dir ) {
			$console_io->write( \sprintf( 'Not scoping dependencies — neither the %s script nor extra.scoped-dependencies-dir is declared.', self::RAW_SCOPER_SCRIPT ) );
			return;
		}

		self::scope_and_generate( $event, $scripts, $scoped_dir );
	}

	/**
	 * Public entry point for the consumer's `scope-php-dependencies` script: runs the full
	 * pipeline (raw php-scoper dispatch, then scoped-autoload regeneration), so a manual
	 * scoping run never leaves the generated autoload stale. Unlike the post-autoload-dump
	 * hook, an explicit invocation fails loudly when scoping cannot run at all.
	 *
	 * @param   Event $event  Composer event object.
	 *
	 * @throws  \RuntimeException  If php-scoper is not installed, the scoping configuration is inconsistent, the raw scoper script fails, or scoped-autoload generation fails.
	 *
	 * @return  void
	 */
	public static function run( Event $event ): void {
		$vendor_dir = $event->getComposer()->getConfig()->get( 'vendor-dir' );

		if ( ! \is_file( $vendor_dir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php-scoper' ) ) {
			throw new \RuntimeException( 'Cannot scope dependencies — humbug/php-scoper is not installed (require-dev it and run a dev-mode composer install).' );
		}

		self::scope_and_generate( $event, self::root_scripts( $event ), self::resolve_scoped_dir( $event ) );
	}

	/**
	 * Executes the scoping pipeline: validates the single-source contract, dispatches the raw
	 * scoper script with the derived `--output-dir`, then regenerates the scoped autoload.
	 *
	 * @param   Event                       $event      Composer event object.
	 * @param   array<string, list<string>> $scripts    Root-package scripts, listeners normalised to lists.
	 * @param   string|null                 $scoped_dir Validated `extra.scoped-dependencies-dir`, or null when not declared.
	 *
	 * @throws  \RuntimeException  If the raw script or the scoped-dir declaration is missing, the public script is not bound to `run`, a script carries `--output-dir`/`-o`, the raw script fails, or it produces no output directory.
	 *
	 * @return  void
	 */
	private static function scope_and_generate( Event $event, array $scripts, ?string $scoped_dir ): void {
		$console_io  = $event->getIO();
		$project_dir = \dirname( \Composer\Factory::getComposerFile() );

		self::assert_public_script_is_not_raw_scoper( $scripts );

		if ( ! isset( $scripts[ self::RAW_SCOPER_SCRIPT ] ) ) {
			throw new \RuntimeException(
				\sprintf(
					'Missing the "%1$s" script. Declare the raw `php-scoper add-prefix` invocation (prefix + config + flags, WITHOUT --output-dir) under "%1$s", and point "%2$s" at %3$s::run for manual full-pipeline runs.',
					self::RAW_SCOPER_SCRIPT,
					self::PIPELINE_SCRIPT,
					self::class
				)
			);
		}

		self::assert_public_script_runs_the_pipeline( $scripts );

		if ( null === $scoped_dir ) {
			throw new \RuntimeException(
				'Missing extra.scoped-dependencies-dir — the scoping pipeline derives php-scoper\'s --output-dir from it. Declare it (e.g. "dependencies") in composer.json.'
			);
		}

		// Single-source enforcement: the output dir lives ONLY in extra.scoped-dependencies-dir.
		// A --output-dir (or php-scoper's short -o, in its spaced/=/glued/quote-wrapped value
		// forms — the shell strips quotes, so '-o./dir' is a live flag) left in a script would
		// either silently lose to the appended derived flag or, under the public name, silently
		// diverge from what the generator scans. Matching `-o` after whitespace/start/quote
		// broadly is safe — long flags are preceded by `-`, not whitespace or a quote.
		foreach ( array( self::RAW_SCOPER_SCRIPT, self::PIPELINE_SCRIPT ) as $script_name ) {
			foreach ( $scripts[ $script_name ] ?? array() as $listener ) {
				if ( \str_contains( $listener, '--output-dir' ) || 1 === \preg_match( '/(?:^|[\s\'"])-o/', $listener ) ) {
					throw new \RuntimeException(
						\sprintf( 'The "%s" script must not carry --output-dir (or -o) — the pipeline derives it from extra.scoped-dependencies-dir. Remove the flag.', $script_name )
					);
				}
			}
		}

		$dependencies_dir = $project_dir . DIRECTORY_SEPARATOR . \ltrim( $scoped_dir, '/\\' );

		$console_io->write( 'Scoping dependencies...' );

		$exit_code = $event->getComposer()->getEventDispatcher()->dispatchScript(
			self::RAW_SCOPER_SCRIPT,
			$event->isDevMode(),
			array( '--output-dir=' . $dependencies_dir )
		);

		// A PHP-callback scope script that returns false maps to a non-zero code Composer hands
		// back here (only shell-command failures throw on their own); stop before regenerating the
		// scoped autoload over partial or stale output.
		if ( 0 !== $exit_code ) {
			throw new \RuntimeException(
				\sprintf( 'The %s script failed with exit code %d; not generating the scoped autoload.', self::RAW_SCOPER_SCRIPT, $exit_code )
			);
		}

		// Unconditional: a successful raw run that produced nothing is pipeline breakage whether
		// or not the consumer opted into the autoload generator.
		if ( ! \is_dir( $dependencies_dir ) ) {
			throw new \RuntimeException(
				\sprintf( 'The %s script succeeded but produced no output at %s (the directory extra.scoped-dependencies-dir points at). Check the scoper config\'s finders and that the raw script forwards the dispatched --output-dir argument to php-scoper.', self::RAW_SCOPER_SCRIPT, $dependencies_dir )
			);
		}

		$prefix = self::root_extra( $event )['scoping-prefix'] ?? null;
		if ( ! \is_string( $prefix ) ) {
			// The autoload generator is opt-in via extra.scoping-prefix; scoping alone is complete here.
			$console_io->write( 'Skipping scoper-autoload generation — extra.scoping-prefix is not declared.' );
			return;
		}

		$output_path = GenerateScopedAutoload::generate( $dependencies_dir, $prefix );
		$console_io->write( \sprintf( 'Wrote %s', $output_path ) );
	}

	/**
	 * Rejects a raw `php-scoper add-prefix` invocation parked under the public pipeline script
	 * name. There it would run WITHOUT the autoload regeneration on a manual invocation —
	 * exactly the stale-scoper-autoload trap the split into a raw script exists to close.
	 *
	 * @param   array<string, list<string>> $scripts Root-package scripts, listeners normalised to lists.
	 *
	 * @throws  \RuntimeException If a `scope-php-dependencies` listener is a php-scoper invocation.
	 *
	 * @return  void
	 */
	private static function assert_public_script_is_not_raw_scoper( array $scripts ): void {
		foreach ( $scripts[ self::PIPELINE_SCRIPT ] ?? array() as $listener ) {
			if ( \str_contains( $listener, 'add-prefix' ) ) {
				throw new \RuntimeException(
					\sprintf(
						'The "%1$s" script must run the full pipeline, not php-scoper directly. Move the `add-prefix` invocation (without --output-dir) to "%2$s" and bind "%1$s" to %3$s::run.',
						self::PIPELINE_SCRIPT,
						self::RAW_SCOPER_SCRIPT,
						self::class
					)
				);
			}
		}
	}

	/**
	 * Requires the public pipeline script to be bound to `ScopePhpDependencies::run` — the
	 * positive half of the public-script contract. A missing binding, an alias onto the raw
	 * script, or any other wrapper would let a manual `composer scope-php-dependencies` scope
	 * without regenerating the scoped autoload.
	 *
	 * @param   array<string, list<string>> $scripts Root-package scripts, listeners normalised to lists.
	 *
	 * @throws  \RuntimeException If the public script is missing or any listener is not the `run` binding.
	 *
	 * @return  void
	 */
	private static function assert_public_script_runs_the_pipeline( array $scripts ): void {
		$listeners = $scripts[ self::PIPELINE_SCRIPT ] ?? array();

		$bound = array() !== $listeners;
		foreach ( $listeners as $listener ) {
			if ( ! \str_contains( $listener, 'ScopePhpDependencies::run' ) ) {
				$bound = false;
				break;
			}
		}

		if ( ! $bound ) {
			throw new \RuntimeException(
				\sprintf(
					'The "%1$s" script must be bound to "%2$s::run" (the full scope + autoload pipeline) — not an alias or wrapper. Declare "%1$s": "%2$s::run" and keep the raw php-scoper invocation under "%3$s".',
					self::PIPELINE_SCRIPT,
					self::class,
					self::RAW_SCOPER_SCRIPT
				)
			);
		}
	}

	/**
	 * Returns the root package's scripts with each script's listeners normalised to a list.
	 *
	 * @param   Event $event Composer event object.
	 *
	 * @return  array<string, list<string>>
	 */
	private static function root_scripts( Event $event ): array {
		$scripts = array();
		foreach ( $event->getComposer()->getPackage()->getScripts() as $name => $listeners ) {
			$scripts[ $name ] = \array_values( \array_filter( (array) $listeners, \is_string( ... ) ) );
		}

		return $scripts;
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
	 * when absent/empty. Confinement mirrors every other path in this class — the scoped
	 * output must not escape the project via an absolute path or `..`.
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
}
