<?php declare( strict_types = 1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use DeepWebSolutions\Config\Composer\ScopePhpDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( ScopePhpDependencies::class )]
final class ScopePhpDependenciesTest extends TestCase {

	private string $project_dir;
	private string $vendor_dir;

	protected function setUp(): void {
		$this->project_dir = sys_get_temp_dir() . '/dws-wp-configs-scope-' . uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		mkdir( $this->vendor_dir, 0755, true );

		putenv( 'COMPOSER=' . $this->project_dir . '/composer.json' );
	}

	protected function tearDown(): void {
		putenv( 'COMPOSER' );
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function pre_autoload_dump_creates_missing_autoload_files(): void {
		$this->writeComposerJson( array(
			'autoload' => array( 'files' => array( 'src/missing.php', 'src/other.php' ) ),
		) );

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/src/missing.php' );
		self::assertFileExists( $this->project_dir . '/src/other.php' );
	}

	#[Test]
	public function pre_autoload_dump_omits_dev_entries_in_non_dev_mode(): void {
		// Catches Coalesce + UnwrapArrayMerge on the autoload-dev fallback. In non-dev
		// mode, autoload-dev entries should NOT be created.
		$this->writeComposerJson( array(
			'autoload'     => array( 'files' => array( 'src/prod.php' ) ),
			'autoload-dev' => array( 'files' => array( 'tests/dev-only.php' ) ),
		) );

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: false ) );

		self::assertFileExists( $this->project_dir . '/src/prod.php' );
		self::assertFileDoesNotExist( $this->project_dir . '/tests/dev-only.php' );
	}

	#[Test]
	public function pre_autoload_dump_handles_composer_json_without_autoload_dev_section(): void {
		// Catches Coalesce on `$composer_config['autoload-dev']['files'] ?? array()`.
		// Without the coalesce, accessing the missing key would throw.
		$this->writeComposerJson( array(
			'autoload' => array( 'files' => array( 'src/only-prod.php' ) ),
		) );

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: true ) );

		self::assertFileExists( $this->project_dir . '/src/only-prod.php' );
	}

	#[Test]
	public function pre_autoload_dump_creates_missing_classmap_directories(): void {
		$this->writeComposerJson( array(
			'autoload' => array( 'classmap' => array( 'src/Components', 'lib/Helpers' ) ),
		) );

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertDirectoryExists( $this->project_dir . '/src/Components' );
		self::assertDirectoryExists( $this->project_dir . '/lib/Helpers' );
	}

	#[Test]
	public function pre_autoload_dump_is_idempotent_for_existing_paths(): void {
		mkdir( $this->project_dir . '/src', 0755, true );
		file_put_contents( $this->project_dir . '/src/existing.php', "<?php\n// keep this content\n" );

		$this->writeComposerJson( array(
			'autoload' => array( 'files' => array( 'src/existing.php' ) ),
		) );

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertSame( "<?php\n// keep this content\n", file_get_contents( $this->project_dir . '/src/existing.php' ) );
	}

	#[Test]
	public function pre_autoload_dump_includes_dev_entries_in_dev_mode(): void {
		$this->writeComposerJson( array(
			'autoload'     => array( 'files' => array( 'src/prod.php' ) ),
			'autoload-dev' => array( 'files' => array( 'tests/dev.php' ) ),
		) );

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: true ) );

		self::assertFileExists( $this->project_dir . '/src/prod.php' );
		self::assertFileExists( $this->project_dir . '/tests/dev.php' );
	}

	#[Test]
	public function post_autoload_dump_skips_in_non_dev_mode(): void {
		$io = new BufferIO();

		ScopePhpDependencies::postAutoloadDump( $this->event( devMode: false, io: $io ) );

		self::assertStringContainsString( 'not a development environment', $io->getOutput() );
	}

	#[Test]
	public function post_autoload_dump_skips_when_php_scoper_is_not_installed(): void {
		$io = new BufferIO();

		ScopePhpDependencies::postAutoloadDump( $this->event( io: $io ) );

		self::assertStringContainsString( 'PHP scoper is not installed', $io->getOutput() );
	}

	private function writeComposerJson( array $contents ): void {
		file_put_contents(
			$this->project_dir . '/composer.json',
			json_encode( $contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	private function event( bool $devMode = true, ?BufferIO $io = null ): Event {
		$composer = new Composer();
		$config   = new Config();
		$config->merge( array( 'config' => array( 'vendor-dir' => $this->vendor_dir ) ) );
		$composer->setConfig( $config );

		return new Event( 'post-autoload-dump', $composer, $io ?? new BufferIO(), $devMode );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
