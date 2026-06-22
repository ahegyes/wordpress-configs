<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

use Composer\Script\Event;

/**
 * Composer event handlers wrapping the php-scoper invocation.
 *
 * `preAutoloadDump` creates placeholder files/directories for any `dependencies/`
 * paths declared in the consumer's `autoload.files`/`autoload.classmap`, so the
 * autoloader dump doesn't fail before scoping has populated those paths.
 *
 * `postAutoloadDump` dispatches the consumer's `scope-php-dependencies` script
 * once `humbug/php-scoper` is installed and dev mode is on. The script itself
 * (defined per-consumer) runs the actual `php-scoper add-prefix`.
 */
final class ScopePhpDependencies {
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

		$console_io->write( 'Making sure autoloaded files exist...' );

		$composer_contents = \file_get_contents( $composer_file ) ?: throw new \RuntimeException( \sprintf( 'Could not read composer.json at %s', $composer_file ) );
		$composer_config   = \json_decode( $composer_contents, true, flags: JSON_THROW_ON_ERROR );

		$autoloaded_files       = $composer_config['autoload']['files'] ?? array();
		$autoloaded_directories = $composer_config['autoload']['classmap'] ?? array();
		if ( $event->isDevMode() ) {
			$autoloaded_files       = \array_merge( $autoloaded_files, $composer_config['autoload-dev']['files'] ?? array() );
			$autoloaded_directories = \array_merge( $autoloaded_directories, $composer_config['autoload-dev']['classmap'] ?? array() );
		}

		foreach ( $autoloaded_files as $file ) {
			self::assert_project_relative( $file );

			$file = $project_dir . DIRECTORY_SEPARATOR . $file;
			if ( ! \file_exists( $file ) ) {
				$file_directory = \dirname( $file );
				\is_dir( $file_directory ) || \mkdir( $file_directory, 0755, true ) || \is_dir( $file_directory ) || throw new \RuntimeException( \sprintf( 'Directory "%s" was not created', $file_directory ) );
				\touch( $file ) || throw new \RuntimeException( \sprintf( 'File "%s" was not created', $file ) );
			}
		}

		foreach ( $autoloaded_directories as $directory ) {
			self::assert_project_relative( $directory );

			$directory = $project_dir . DIRECTORY_SEPARATOR . $directory;
			\is_dir( $directory ) || \mkdir( $directory, 0755, true ) || \is_dir( $directory ) || throw new \RuntimeException( \sprintf( 'Directory "%s" was not created', $directory ) );
		}
	}

	/**
	 * Rejects autoload entries that escape the project root via absolute paths or parent-directory traversal.
	 *
	 * @since   2.0.0
	 * @version 2.0.0
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

		$console_io->write( 'Scoping dependencies...' );

		$event_dispatcher = $event->getComposer()->getEventDispatcher();
		$event_dispatcher->dispatchScript( 'scope-php-dependencies', $event->isDevMode() );

		self::generate_scoped_autoload( $event );
	}

	/**
	 * Generates `dependencies/scoper-autoload.php` when both `extra.scoped-dependencies-dir`
	 * and `extra.scoping-prefix` are set in the consumer's composer.json.
	 *
	 * @param Event $event Composer event object.
	 *
	 * @throws \JsonException     If composer.json or a scoped composer.json cannot be parsed.
	 * @throws \RuntimeException  If composer.json cannot be read or the generated file cannot be written.
	 *
	 * @return void
	 */
	private static function generate_scoped_autoload( Event $event ): void {
		$console_io    = $event->getIO();
		$composer_file = \Composer\Factory::getComposerFile();
		$project_dir   = \dirname( $composer_file );

		$composer_contents = \file_get_contents( $composer_file ) ?: throw new \RuntimeException( \sprintf( 'Could not read composer.json at %s', $composer_file ) );
		$composer_config   = \json_decode( $composer_contents, true, flags: JSON_THROW_ON_ERROR );

		$dependencies_rel = $composer_config['extra']['scoped-dependencies-dir'] ?? null;
		$prefix           = $composer_config['extra']['scoping-prefix'] ?? null;

		if ( ! \is_string( $dependencies_rel ) || ! \is_string( $prefix ) ) {
			return;
		}

		$dependencies_dir = $project_dir . DIRECTORY_SEPARATOR . \ltrim( $dependencies_rel, '/\\' );
		if ( ! \is_dir( $dependencies_dir ) ) {
			$console_io->write( \sprintf( 'Skipping scoper-autoload generation — %s does not exist (php-scoper may not have produced output).', $dependencies_dir ) );
			return;
		}

		$output_path = GenerateScopedAutoload::generate( $dependencies_dir, $prefix );
		$console_io->write( \sprintf( 'Wrote %s', $output_path ) );
	}
}
