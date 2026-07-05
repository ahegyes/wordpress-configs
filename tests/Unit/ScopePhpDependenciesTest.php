<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use DeepWebSolutions\Config\Composer\ScopePhpDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( ScopePhpDependencies::class )]
final class ScopePhpDependenciesTest extends TestCase {

	private string $project_dir;
	private string $vendor_dir;

	protected function setUp(): void {
		$this->project_dir = \sys_get_temp_dir() . '/dws-wp-configs-scope-' . \uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		\mkdir( $this->vendor_dir, 0755, true );

		\putenv( 'COMPOSER=' . $this->project_dir . '/composer.json' );
	}

	protected function tearDown(): void {
		\putenv( 'COMPOSER' );
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function pre_autoload_dump_creates_missing_scoped_paths(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/scoper-autoload.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/dependencies/scoper-autoload.php' );
	}

	#[Test]
	public function pre_autoload_dump_does_not_create_paths_outside_the_scoped_dir(): void {
		// A typo'd real autoload path must stay visible to Composer's own autoload validation.
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/scoper-autoload.php', 'src/boostrap.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertFileDoesNotExist( $this->project_dir . '/src/boostrap.php' );
	}

	#[Test]
	public function pre_autoload_dump_is_a_noop_without_a_scoped_dependencies_dir(): void {
		$this->writeComposerJson(
			array(
				'autoload' => array( 'files' => array( 'src/missing.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileDoesNotExist( $this->project_dir . '/src/missing.php' );
	}

	#[Test]
	public function pre_autoload_dump_creates_missing_scoped_classmap_directories(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'classmap' => array( 'dependencies/scoped-pkg' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertDirectoryExists( $this->project_dir . '/dependencies/scoped-pkg' );
	}

	#[Test]
	public function pre_autoload_dump_is_idempotent_for_existing_scoped_paths(): void {
		\mkdir( $this->project_dir . '/dependencies', 0755, true );
		\file_put_contents( $this->project_dir . '/dependencies/scoper-autoload.php', "<?php\n// keep this content\n" );

		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/scoper-autoload.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertSame( "<?php\n// keep this content\n", \file_get_contents( $this->project_dir . '/dependencies/scoper-autoload.php' ) );
	}

	#[Test]
	public function pre_autoload_dump_omits_dev_scoped_entries_in_non_dev_mode(): void {
		$this->writeComposerJson(
			array(
				'extra'        => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload'     => array( 'files' => array( 'dependencies/prod.php' ) ),
				'autoload-dev' => array( 'files' => array( 'dependencies/dev-only.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: false ) );

		self::assertFileExists( $this->project_dir . '/dependencies/prod.php' );
		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/dev-only.php' );
	}

	#[Test]
	public function pre_autoload_dump_includes_dev_scoped_entries_in_dev_mode(): void {
		$this->writeComposerJson(
			array(
				'extra'        => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload'     => array( 'files' => array( 'dependencies/prod.php' ) ),
				'autoload-dev' => array( 'files' => array( 'dependencies/dev.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: true ) );

		self::assertFileExists( $this->project_dir . '/dependencies/prod.php' );
		self::assertFileExists( $this->project_dir . '/dependencies/dev.php' );
	}

	#[Test]
	public function pre_autoload_dump_handles_composer_json_without_autoload_dev_section(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/only-prod.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: true ) );

		self::assertFileExists( $this->project_dir . '/dependencies/only-prod.php' );
	}

	#[Test]
	public function pre_autoload_dump_rejects_scoped_dependencies_dir_with_traversal(): void {
		$this->writeComposerJson(
			array(
				'extra' => array( 'scoped-dependencies-dir' => '../escape' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/parent-directory traversal/' );
		ScopePhpDependencies::preAutoloadDump( $this->event() );
	}

	#[Test]
	public function pre_autoload_dump_rejects_absolute_scoped_dependencies_dir(): void {
		$this->writeComposerJson(
			array(
				'extra' => array( 'scoped-dependencies-dir' => '/etc/escape' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/absolute autoload path/' );
		ScopePhpDependencies::preAutoloadDump( $this->event() );
	}

	#[Test]
	public function pre_autoload_dump_rejects_scoped_path_with_parent_traversal(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/../../escape.php' ) ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/parent-directory traversal/' );
		ScopePhpDependencies::preAutoloadDump( $this->event() );
	}

	#[Test]
	public function pre_autoload_dump_creates_scoped_path_with_leading_dot_slash(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( './dependencies/scoper-autoload.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/dependencies/scoper-autoload.php' );
	}

	#[Test]
	public function pre_autoload_dump_creates_classmap_dir_equal_to_the_scoped_dir(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'classmap' => array( 'dependencies' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertDirectoryExists( $this->project_dir . '/dependencies' );
	}

	#[Test]
	public function post_autoload_dump_skips_in_non_dev_mode(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson() );

		$io = new BufferIO();
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( $io, devMode: false ) );

		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertNotSame( '', $io->getOutput() );
	}

	#[Test]
	public function post_autoload_dump_skips_when_php_scoper_is_not_installed(): void {
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson() );

		$io = new BufferIO();
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( $io ) );

		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertNotSame( '', $io->getOutput() );
	}

	#[Test]
	public function post_autoload_dump_skips_when_scoped_dependencies_dir_is_not_declared(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson(
			array(
				'extra' => array( 'scoping-prefix' => 'MyPlugin\\Scoped' ),
			)
		);

		$io = new BufferIO();
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( $io ) );

		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertNotSame( '', $io->getOutput() );
	}

	#[Test]
	public function post_autoload_dump_runs_php_scoper_command_then_generates_autoload(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson(
			$this->scopingComposerJson(
				scopedDir: 'custom dependencies',
				flags: array( '--flag=two words', '--ansi' )
			)
		);

		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );

		$recorded = $this->readRecordedCommand();
		self::assertSame( \realpath( $this->project_dir ), $recorded['cwd'] );
		self::assertSame(
			array(
				$this->vendor_dir . '/bin/php-scoper',
				'add-prefix',
				'--prefix=MyPlugin\\Scoped',
				'--config=' . $this->project_dir . '/scoper.inc.php',
				'--output-dir=' . $this->project_dir . '/custom dependencies',
				'--force',
				'--quiet',
				'--flag=two words',
				'--ansi',
			),
			$recorded['argv']
		);

		$generated = (string) \file_get_contents( $this->project_dir . '/custom dependencies/scoper-autoload.php' );
		self::assertStringContainsString( 'MyPlugin\\\\Scoped\\\\Acme\\\\Lib', $generated );
	}

	#[Test]
	public function post_autoload_dump_throws_when_scoper_config_is_missing(): void {
		$this->installFakePhpScoper();
		$this->writeComposerJson( $this->scopingComposerJson() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/scoper\.inc\.php/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_scoped_dir_has_no_prefix(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson(
			array(
				'extra' => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/extra\.scoping-prefix/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_scoping_prefix_is_invalid(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( prefix: '9Bad\\Prefix' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/valid PHP namespace prefix/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	#[DataProvider( 'forbiddenScopingFlags' )]
	public function post_autoload_dump_rejects_pipeline_owned_scoping_flags( string $flag ): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( flags: array( $flag ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/pipeline owns/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function forbiddenScopingFlags(): array {
		return array(
			'output dir long'  => array( '--output-dir=other' ),
			'output dir short' => array( '-oother' ),
			'prefix long'      => array( '--prefix=Other\\Prefix' ),
			'config long'      => array( '--config=other.inc.php' ),
		);
	}

	#[Test]
	public function post_autoload_dump_rejects_non_list_scoping_flags(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson(
			$this->scopingComposerJson(
				flags: array( 'named' => '--ansi' )
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be a list/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_non_string_scoping_flags(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( flags: array( true ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must contain only strings/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_php_scoper_fails_and_includes_stderr(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( flags: array( '--fail-scoper' ) ) );

		try {
			ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
			self::fail( 'Expected php-scoper failure to throw.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'php-scoper failed with exit code 37', $exception->getMessage() );
			self::assertStringContainsString( 'intentional scoper failure', $exception->getMessage() );
		}
	}

	#[Test]
	public function post_autoload_dump_propagates_generator_failure(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( flags: array( '--empty-output' ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/No scoped packages found/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_scoped_dependencies_dir_with_traversal(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( scopedDir: '../escape' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/parent-directory traversal/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_absolute_scoped_dependencies_dir(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( scopedDir: '/etc/escape' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/absolute autoload path/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_skips_for_empty_scoped_dependencies_dir(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson( scopedDir: '' ) );

		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );

		self::assertFileDoesNotExist( $this->project_dir . '/scoper-autoload.php' );
	}

	#[Test]
	public function run_executes_the_full_pipeline(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson() );

		ScopePhpDependencies::run( $this->factoryEvent( new BufferIO() ) );

		$generated = (string) \file_get_contents( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertStringContainsString( 'MyPlugin\\\\Scoped\\\\Acme\\\\Lib', $generated );
	}

	#[Test]
	public function run_throws_when_php_scoper_is_not_installed(): void {
		$this->writeScoperConfig();
		$this->writeComposerJson( $this->scopingComposerJson() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/php-scoper is not installed/' );
		ScopePhpDependencies::run( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function run_throws_when_scoped_dependencies_dir_is_not_declared(): void {
		$this->installFakePhpScoper();
		$this->writeScoperConfig();
		$this->writeComposerJson(
			array(
				'extra' => array( 'scoping-prefix' => 'MyPlugin\\Scoped' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/extra\.scoped-dependencies-dir/' );
		ScopePhpDependencies::run( $this->factoryEvent( new BufferIO() ) );
	}

	/**
	 * @param array<string, mixed> $contents
	 */
	private function writeComposerJson( array $contents ): void {
		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode( $contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	private function writeScoperConfig(): void {
		\file_put_contents( $this->project_dir . '/scoper.inc.php', "<?php\nreturn array();\n" );
	}

	/**
	 * @param  list<mixed>|array<string, mixed> $flags
	 *
	 * @return array<string, mixed>
	 */
	private function scopingComposerJson( string $scopedDir = 'dependencies', string $prefix = 'MyPlugin\\Scoped', array $flags = array() ): array {
		$extra = array(
			'scoped-dependencies-dir' => $scopedDir,
			'scoping-prefix'          => $prefix,
		);
		if ( array() !== $flags ) {
			$extra['scoping-flags'] = $flags;
		}

		return array(
			'extra' => $extra,
		);
	}

	private function event( bool $devMode = true, ?BufferIO $io = null ): Event {
		$composer = new Composer();
		$config   = new Config();
		$config->merge( array( 'config' => array( 'vendor-dir' => $this->vendor_dir ) ) );
		$composer->setConfig( $config );

		return new Event( 'post-autoload-dump', $composer, $io ?? new BufferIO(), $devMode );
	}

	private function installFakePhpScoper(): void {
		\mkdir( $this->vendor_dir . '/bin', 0755, true );
		\file_put_contents(
			$this->vendor_dir . '/bin/php-scoper',
			<<<'PHP'
			<?php declare(strict_types=1);

			$project = getcwd();
			file_put_contents(
				$project . '/recorded-command.json',
				json_encode(
					array(
						'cwd'  => $project,
						'argv' => $argv,
					),
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
				)
			);

			if ( in_array( '--fail-scoper', $argv, true ) ) {
				fwrite( STDERR, "intentional scoper failure\n" );
				exit( 37 );
			}

			$output_dir = null;
			$prefix     = null;
			foreach ( $argv as $arg ) {
				if ( str_starts_with( $arg, '--output-dir=' ) ) {
					$output_dir = substr( $arg, strlen( '--output-dir=' ) );
				}
				if ( str_starts_with( $arg, '--prefix=' ) ) {
					$prefix = substr( $arg, strlen( '--prefix=' ) );
				}
			}

			if ( null === $output_dir ) {
				fwrite( STDERR, "missing output dir\n" );
				exit( 2 );
			}
			if ( null === $prefix ) {
				fwrite( STDERR, "missing prefix\n" );
				exit( 2 );
			}

			mkdir( $output_dir, 0755, true );
			if ( in_array( '--empty-output', $argv, true ) ) {
				exit( 0 );
			}

			$pkg_dir = $output_dir . '/acme/lib';
			mkdir( $pkg_dir, 0755, true );
			file_put_contents(
				$pkg_dir . '/composer.json',
				json_encode(
					array(
						'name'     => 'acme/lib',
						'autoload' => array(
							'psr-4' => array( $prefix . '\\Acme\\Lib\\' => 'src/' ),
						),
					),
					JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
				)
			);
			PHP
		);
	}

	/**
	 * Builds an event from a real Composer the Factory assembles from the test composer.json.
	 */
	private function factoryEvent( BufferIO $io, bool $devMode = true ): Event {
		$composer = \Composer\Factory::create( $io, $this->project_dir . '/composer.json', true );

		return new Event( 'post-autoload-dump', $composer, $io, $devMode );
	}

	/**
	 * @return array{cwd: string, argv: list<string>}
	 */
	private function readRecordedCommand(): array {
		$contents = \file_get_contents( $this->project_dir . '/recorded-command.json' );
		self::assertIsString( $contents );

		return \json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );
	}

	private function rrmdir( string $dir ): void {
		// SAFETY: never follow symlinks because is_dir() returns true for symlink-to-dir.
		if ( \is_link( $dir ) ) {
			\unlink( $dir );
			return;
		}
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		foreach ( \scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( \is_link( $path ) ) {
				\unlink( $path );
			} elseif ( \is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				\unlink( $path );
			}
		}
		\rmdir( $dir );
	}
}
