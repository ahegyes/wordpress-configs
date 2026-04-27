<?php declare( strict_types = 1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Script\Event;
use DeepWebSolutions\Config\Composer\FindWPCoreCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( FindWPCoreCalls::class )]
final class FindWPCoreCallsTest extends TestCase {

	private const FIXTURES_DIR = __DIR__ . '/../fixtures/find-wp-core-calls';
	private const ENV_VARS     = array(
		'WP_CORE_CALLS_INPUT_DIR',
		'WP_CORE_CALLS_EXCLUDE_DIRS',
		'WP_CORE_CALLS_OUTPUT_DIR',
		'WP_CORE_CALLS_OUTPUT_FILE',
		'CI',
	);

	private string $project_dir;
	private string $vendor_dir;

	protected function setUp(): void {
		// Clear all env vars FindWPCoreCalls reads, so each test starts from a known state.
		// Notably `CI` is set by GitHub Actions; without this, tests that need the script
		// to run (most of them) would hit the CI-skip branch.
		foreach ( self::ENV_VARS as $var ) {
			putenv( $var );
		}

		$this->project_dir = sys_get_temp_dir() . '/dws-wp-configs-test-' . uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		mkdir( $this->vendor_dir . '/php-stubs/wordpress-stubs', 0755, true );

		// Stage the fixture stubs at the path FindWPCoreCalls expects.
		copy(
			self::FIXTURES_DIR . '/stubs.php',
			$this->vendor_dir . '/php-stubs/wordpress-stubs/wordpress-stubs.php'
		);
	}

	protected function tearDown(): void {
		foreach ( self::ENV_VARS as $var ) {
			putenv( $var );
		}
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function detects_wp_core_classes_and_functions_from_fixture_input(): void {
		copy( self::FIXTURES_DIR . '/input.php', $this->project_dir . '/input.php' );

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		$result   = $this->loadOutput( 'wp-core-calls.json' );
		$expected = json_decode( file_get_contents( self::FIXTURES_DIR . '/output-expected.json' ), true, 512, JSON_THROW_ON_ERROR );

		// Parser may emit findings in any order — assert order-independent equality.
		self::assertEqualsCanonicalizing( $expected['classes'], $result['classes'] );
		self::assertEqualsCanonicalizing( $expected['functions'], $result['functions'] );
	}

	#[Test]
	public function returns_empty_arrays_when_no_wp_refs_are_present(): void {
		file_put_contents( $this->project_dir . '/clean.php', "<?php\nfunction my_thing(): void { strlen('hi'); }\n" );

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array() ),
			$this->loadOutput( 'wp-core-calls.json' )
		);
	}

	#[Test]
	public function detects_class_extension(): void {
		file_put_contents( $this->project_dir . '/extender.php', "<?php\nclass MyChild extends WP_Filesystem_Base {}\n" );

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'wp-core-calls.json' );
		self::assertContains( 'WP_Filesystem_Base', $result['classes'] );
		self::assertSame( array(), $result['functions'] );
	}

	#[Test]
	public function detects_classes_in_type_hints(): void {
		file_put_contents(
			$this->project_dir . '/types.php',
			"<?php\nfunction op( WP_Filesystem_FTPext \$param ): WP_Filesystem_SSH2 { return new WP_Filesystem_SSH2(); }\n"
		);

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'wp-core-calls.json' );
		self::assertContains( 'WP_Filesystem_FTPext', $result['classes'] );
		self::assertContains( 'WP_Filesystem_SSH2', $result['classes'] );
	}

	#[Test]
	public function honors_exclude_dirs_via_env_var(): void {
		mkdir( $this->project_dir . '/skip-me' );
		file_put_contents( $this->project_dir . '/skip-me/inside.php', "<?php add_action('init', fn() => null);\n" );
		file_put_contents( $this->project_dir . '/clean.php', "<?php function my_thing(): void {}\n" );

		putenv( 'WP_CORE_CALLS_EXCLUDE_DIRS=skip-me' );

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		self::assertSame( array(), $this->loadOutput( 'wp-core-calls.json' )['functions'] );
	}

	#[Test]
	public function honors_input_dir_override(): void {
		mkdir( $this->project_dir . '/scan-here' );
		file_put_contents( $this->project_dir . '/scan-here/file.php', "<?php add_action('init', fn() => null);\n" );
		file_put_contents( $this->project_dir . '/elsewhere.php', "<?php wp_filesystem();\n" );

		putenv( 'WP_CORE_CALLS_INPUT_DIR=' . $this->project_dir . '/scan-here' );
		putenv( 'WP_CORE_CALLS_EXCLUDE_DIRS=' );

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'wp-core-calls.json' );
		self::assertContains( 'add_action', $result['functions'] );
		self::assertNotContains( 'wp_filesystem', $result['functions'] );
	}

	#[Test]
	public function skips_when_not_in_dev_mode(): void {
		copy( self::FIXTURES_DIR . '/input.php', $this->project_dir . '/input.php' );

		FindWPCoreCalls::postAutoloadDump( $this->event( devMode: false ) );

		self::assertFileDoesNotExist( $this->project_dir . '/wp-core-calls.json' );
	}

	#[Test]
	public function skips_when_ci_env_is_set(): void {
		copy( self::FIXTURES_DIR . '/input.php', $this->project_dir . '/input.php' );
		putenv( 'CI=true' );

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		self::assertFileDoesNotExist( $this->project_dir . '/wp-core-calls.json' );
	}

	#[Test]
	public function skips_when_stubs_are_missing(): void {
		copy( self::FIXTURES_DIR . '/input.php', $this->project_dir . '/input.php' );
		unlink( $this->vendor_dir . '/php-stubs/wordpress-stubs/wordpress-stubs.php' );

		FindWPCoreCalls::postAutoloadDump( $this->event() );

		self::assertFileDoesNotExist( $this->project_dir . '/wp-core-calls.json' );
	}

	private function event( bool $devMode = true ): Event {
		$composer = new Composer();
		$config   = new Config();
		$config->merge( array( 'config' => array( 'vendor-dir' => $this->vendor_dir ) ) );
		$composer->setConfig( $config );

		return new Event( 'post-autoload-dump', $composer, new NullIO(), $devMode );
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
