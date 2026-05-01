<?php declare( strict_types = 1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Script\Event;
use DeepWebSolutions\Config\Composer\CollectScopingStubs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( CollectScopingStubs::class )]
final class CollectScopingStubsTest extends TestCase {

	private const FIXTURES_DIR = __DIR__ . '/../fixtures/collect-scoping-stubs';
	private const ENV_VARS     = array(
		'SCOPING_EXCLUSIONS_OUTPUT_DIR',
		'SCOPING_EXCLUSIONS_OUTPUT_FILE',
		'CI',
	);

	private string $project_dir;
	private string $vendor_dir;

	protected function setUp(): void {
		// Clear all env vars CollectScopingStubs reads, so each test starts from a known state.
		// `CI` is set by GitHub Actions; without this, tests that need the script to run would
		// hit the CI-skip branch.
		foreach ( self::ENV_VARS as $var ) {
			putenv( $var );
		}

		$this->project_dir = sys_get_temp_dir() . '/dws-wp-configs-test-' . uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		mkdir( $this->vendor_dir, 0755, true );
	}

	protected function tearDown(): void {
		foreach ( self::ENV_VARS as $var ) {
			putenv( $var );
		}
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function emits_empty_lists_when_no_package_declares_scoping_stubs(): void {
		$this->writeProjectComposer( array() );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function dumps_symbols_from_a_package_declared_in_project_composer(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'WP_Filesystem_Base', $result['classes'] );
		self::assertContains( 'add_action', $result['functions'] );
	}

	#[Test]
	public function aggregates_declarations_from_installed_packages(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array() );
		$this->installPackage(
			'ahegyes/wp-framework-bootstrap',
			array( 'extra' => array( 'scoping-stubs' => array( 'php-stubs/wordpress-stubs' ) ) )
		);

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'WP_Filesystem_Base', $result['classes'] );
		self::assertContains( 'add_action', $result['functions'] );
	}

	#[Test]
	public function unions_symbols_across_multiple_declared_packages(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->installStubsPackage(
			'php-stubs/woocommerce-stubs',
			self::FIXTURES_DIR . '/stubs-extra.php'
		);
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs', 'php-stubs/woocommerce-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'WP_Filesystem_Base', $result['classes'] );
		self::assertContains( 'WC_Logger', $result['classes'] );
		self::assertContains( 'add_action', $result['functions'] );
		self::assertContains( 'wc_get_product', $result['functions'] );
	}

	#[Test]
	public function deduplicates_symbols_when_same_catalog_is_declared_in_multiple_packages(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );
		$this->installPackage(
			'ahegyes/wp-framework-bootstrap',
			array( 'extra' => array( 'scoping-stubs' => array( 'php-stubs/wordpress-stubs' ) ) )
		);

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result    = $this->loadOutput( 'scoping-exclusions.json' );
		$add_count = count( array_keys( $result['functions'], 'add_action', true ) );
		self::assertSame( 1, $add_count );
	}

	#[Test]
	public function skips_declared_packages_whose_stubs_file_is_missing(): void {
		// Declared but not installed — helper should warn and continue, not crash.
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function honors_output_dir_and_file_overrides(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );
		mkdir( $this->project_dir . '/build' );
		putenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR=' . $this->project_dir . '/build' );
		putenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE=stubs-dump.json' );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/build/stubs-dump.json' );
		self::assertFileDoesNotExist( $this->project_dir . '/scoping-exclusions.json' );
	}

	#[Test]
	public function skips_when_not_in_dev_mode(): void {
		$this->writeProjectComposer( array() );

		CollectScopingStubs::postAutoloadDump( $this->event( devMode: false ) );

		self::assertFileDoesNotExist( $this->project_dir . '/scoping-exclusions.json' );
	}

	#[Test]
	public function skips_when_ci_env_is_set(): void {
		$this->writeProjectComposer( array() );
		putenv( 'CI=true' );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertFileDoesNotExist( $this->project_dir . '/scoping-exclusions.json' );
	}

	private function event( bool $devMode = true ): Event {
		$composer = new Composer();
		$config   = new Config();
		$config->merge( array( 'config' => array( 'vendor-dir' => $this->vendor_dir ) ) );
		$composer->setConfig( $config );

		return new Event( 'post-autoload-dump', $composer, new NullIO(), $devMode );
	}

	private function writeProjectComposer( array $scoping_stubs ): void {
		file_put_contents(
			$this->project_dir . '/composer.json',
			json_encode(
				array( 'extra' => array( 'scoping-stubs' => $scoping_stubs ) ),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
			)
		);
	}

	private function installPackage( string $package_name, array $composer_payload ): void {
		$package_dir = $this->vendor_dir . '/' . $package_name;
		mkdir( $package_dir, 0755, true );
		file_put_contents(
			$package_dir . '/composer.json',
			json_encode( $composer_payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	private function installStubsPackage( string $package_name, string $stubs_source_path ): void {
		$package_dir = $this->vendor_dir . '/' . $package_name;
		mkdir( $package_dir, 0755, true );

		// Stubs file lives at vendor/<vendor>/<name>/<name>.php by convention.
		$basename = explode( '/', $package_name )[1];
		copy( $stubs_source_path, $package_dir . '/' . $basename . '.php' );

		// Minimal composer.json so the package registers in vendor/.
		file_put_contents(
			$package_dir . '/composer.json',
			json_encode( array( 'name' => $package_name ), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	private function loadOutput( string $filename ): array {
		return json_decode(
			file_get_contents( $this->project_dir . '/' . $filename ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
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
