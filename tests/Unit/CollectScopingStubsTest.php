<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
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
		'COMPOSER',
		'SCOPING_EXCLUSIONS_OUTPUT_DIR',
		'SCOPING_EXCLUSIONS_OUTPUT_FILE',
	);

	private string $project_dir;
	private string $vendor_dir;

	protected function setUp(): void {
		// Clear all env vars CollectScopingStubs reads, so each test starts from a known state.
		// `CI` is set by GitHub Actions; without this, tests that need the script to run would
		// hit the CI-skip branch.
		foreach ( self::ENV_VARS as $var ) {
			\putenv( $var );
		}

		$this->project_dir = \sys_get_temp_dir() . '/dws-wp-configs-test-' . \uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		\mkdir( $this->vendor_dir, 0755, true );

		\putenv( 'COMPOSER=' . $this->project_dir . '/composer.json' );
	}

	protected function tearDown(): void {
		foreach ( self::ENV_VARS as $var ) {
			\putenv( $var );
		}
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function emits_empty_lists_when_no_package_declares_scoping_stubs(): void {
		$this->writeProjectComposer( array() );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
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
		$add_count = \count( \array_keys( $result['functions'], 'add_action', true ) );
		self::assertSame( 1, $add_count );
	}

	#[Test]
	public function skips_declared_packages_whose_stubs_file_is_missing(): void {
		// Declared but not installed — helper should warn and continue, not crash.
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function continues_past_first_missing_stubs_package_to_collect_subsequent_ones(): void {
		// First package declared but not installed; second package declared AND installed.
		// `continue` mutated to `break` would stop after the first miss and yield empty results.
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs', 'php-stubs/woocommerce-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'WC_Logger', $result['classes'] );
		self::assertContains( 'wc_get_product', $result['functions'] );
	}

	#[Test]
	public function filters_non_string_entries_from_scoping_stubs_declaration(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		// Mixed types in scoping-stubs — only the string survives.
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs', 123, null, array( 'nested' => true ) ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
	}

	#[Test]
	public function filters_path_traversal_segments_from_scoping_stubs_declaration(): void {
		$io = new BufferIO();
		$this->writeProjectComposer( array( '../etc/passwd', '/etc/shadow' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		// Filtered at read_declaration before reaching resolve_stubs_paths —
		// no skip messages for these entries.
		self::assertStringNotContainsString( '../etc/passwd', $io->getOutput() );
		self::assertStringNotContainsString( '/etc/shadow', $io->getOutput() );
		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function filters_malformed_package_names_from_scoping_stubs_declaration(): void {
		$io = new BufferIO();
		// Each value fails Composer's package-name regex for a different reason:
		// uppercase / missing slash / too many slashes / trailing dash.
		$this->writeProjectComposer( array( 'Foo/Bar', 'no-slash', 'too/many/slashes', 'foo-/bar' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertStringNotContainsString( 'Foo/Bar', $io->getOutput() );
		self::assertStringNotContainsString( 'no-slash', $io->getOutput() );
		self::assertStringNotContainsString( 'too/many/slashes', $io->getOutput() );
		self::assertStringNotContainsString( 'foo-/bar', $io->getOutput() );
	}


	#[Test]
	public function honors_output_dir_and_file_overrides(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );
		\mkdir( $this->project_dir . '/build' );
		\putenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR=' . $this->project_dir . '/build' );
		\putenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE=stubs-dump.json' );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/build/stubs-dump.json' );
		self::assertFileDoesNotExist( $this->project_dir . '/scoping-exclusions.json' );
	}

	#[Test]
	public function rejects_output_dir_outside_project_root(): void {
		$this->writeProjectComposer( array() );
		$outside_dir = \sys_get_temp_dir() . '/dws-wp-configs-escape-' . \uniqid();
		\mkdir( $outside_dir );
		\putenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR=' . $outside_dir );

		try {
			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/must be inside the project root/' );
			CollectScopingStubs::postAutoloadDump( $this->event() );
		} finally {
			\rmdir( $outside_dir );
		}
	}

	#[Test]
	public function rejects_output_dir_that_does_not_exist(): void {
		$this->writeProjectComposer( array() );
		\putenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR=' . $this->project_dir . '/does-not-exist' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/does not resolve to an existing directory/' );
		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function rejects_output_file_containing_forward_slash(): void {
		$this->writeProjectComposer( array() );
		\putenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE=../escape.json' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be a filename, not a path/' );
		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function rejects_output_file_containing_backslash(): void {
		$this->writeProjectComposer( array() );
		\putenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE=sub\\escape.json' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be a filename, not a path/' );
		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function skips_when_not_in_dev_mode(): void {
		$io = new BufferIO();
		$this->writeProjectComposer( array() );

		CollectScopingStubs::postAutoloadDump( $this->event( devMode: false, io: $io ) );

		self::assertFileDoesNotExist( $this->project_dir . '/scoping-exclusions.json' );
		self::assertStringContainsString( 'not being in dev mode', $io->getOutput() );
	}

	#[Test]
	public function writes_skip_message_when_stubs_file_is_missing(): void {
		$io = new BufferIO();
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertStringContainsString( 'Skipping declared stubs package', $io->getOutput() );
		self::assertStringContainsString( 'no stubs file found', $io->getOutput() );
	}

	#[Test]
	public function output_arrays_are_list_shaped(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertSame( \array_values( $result['classes'] ), $result['classes'] );
		self::assertSame( \array_values( $result['functions'] ), $result['functions'] );
	}

	#[Test]
	public function output_json_is_pretty_printed(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$contents = \file_get_contents( $this->project_dir . '/scoping-exclusions.json' ) ?: self::fail( 'output unreadable' );
		self::assertStringContainsString( "\n    ", $contents );
	}

	#[Test]
	public function works_when_vendor_dir_does_not_exist(): void {
		$this->rrmdir( $this->vendor_dir );
		$this->writeProjectComposer( array() );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/scoping-exclusions.json' );
	}

	#[Test]
	public function throws_when_output_directory_is_not_writable(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		$readonly_dir = $this->project_dir . '/readonly';
		\mkdir( $readonly_dir );
		\chmod( $readonly_dir, 0500 );
		\putenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR=' . $readonly_dir );

		// Suppress the PHP Warning that file_put_contents emits before returning false on permission denied.
		\set_error_handler( static fn (): bool => true );
		try {
			$this->expectException( \RuntimeException::class );
			CollectScopingStubs::postAutoloadDump( $this->event() );
		} finally {
			\restore_error_handler();
			\chmod( $readonly_dir, 0700 );
		}
	}

	#[Test]
	public function dumps_const_declarations_from_a_package(): void {
		$this->installStubsPackage( 'php-stubs/constants-stubs', self::FIXTURES_DIR . '/stubs-constants.php' );
		$this->writeProjectComposer( array( 'php-stubs/constants-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'TOP_CONST_A', $result['constants'] );
		self::assertContains( 'TOP_CONST_B', $result['constants'] );
	}

	#[Test]
	public function dumps_namespaced_classes_with_distinct_fqcn_per_namespace(): void {
		$this->installStubsPackage( 'php-stubs/namespaced-stubs', self::FIXTURES_DIR . '/stubs-namespaced.php' );
		$this->writeProjectComposer( array( 'php-stubs/namespaced-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'A\\Foo', $result['classes'] );
		self::assertContains( 'B\\Foo', $result['classes'] );
	}

	#[Test]
	public function dumps_interface_declarations_with_fqcn(): void {
		$this->installStubsPackage( 'php-stubs/namespaced-stubs', self::FIXTURES_DIR . '/stubs-namespaced.php' );
		$this->writeProjectComposer( array( 'php-stubs/namespaced-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'A\\FooInterface', $result['classes'] );
	}

	#[Test]
	public function dumps_trait_declarations_with_fqcn(): void {
		$this->installStubsPackage( 'php-stubs/namespaced-stubs', self::FIXTURES_DIR . '/stubs-namespaced.php' );
		$this->writeProjectComposer( array( 'php-stubs/namespaced-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'A\\FooTrait', $result['classes'] );
	}

	#[Test]
	public function dumps_enum_declarations_with_fqcn(): void {
		$this->installStubsPackage( 'php-stubs/namespaced-stubs', self::FIXTURES_DIR . '/stubs-namespaced.php' );
		$this->writeProjectComposer( array( 'php-stubs/namespaced-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'A\\FooEnum', $result['classes'] );
	}

	#[Test]
	public function dumps_namespaced_functions_with_distinct_fqcn_per_namespace(): void {
		$this->installStubsPackage( 'php-stubs/namespaced-stubs', self::FIXTURES_DIR . '/stubs-namespaced.php' );
		$this->writeProjectComposer( array( 'php-stubs/namespaced-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'A\\bar', $result['functions'] );
		self::assertContains( 'B\\bar', $result['functions'] );
	}

	#[Test]
	public function dumps_namespaced_const_declarations_with_distinct_fqcn_per_namespace(): void {
		$this->installStubsPackage( 'php-stubs/namespaced-stubs', self::FIXTURES_DIR . '/stubs-namespaced.php' );
		$this->writeProjectComposer( array( 'php-stubs/namespaced-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'A\\BAZ', $result['constants'] );
		self::assertContains( 'B\\BAZ', $result['constants'] );
	}

	#[Test]
	public function does_not_pick_up_non_define_function_calls_as_constants(): void {
		$this->installStubsPackage( 'php-stubs/constants-stubs', self::FIXTURES_DIR . '/stubs-constants.php' );
		$this->writeProjectComposer( array( 'php-stubs/constants-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertNotContains( 'NOT_A_CONSTANT', $result['constants'] );
		self::assertNotContains( 'NEITHER_IS_THIS', $result['constants'] );
	}

	#[Test]
	public function dumps_define_calls_from_a_package(): void {
		$this->installStubsPackage( 'php-stubs/constants-stubs', self::FIXTURES_DIR . '/stubs-constants.php' );
		$this->writeProjectComposer( array( 'php-stubs/constants-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'DEFINED_CONST_A', $result['constants'] );
		self::assertContains( 'DEFINED_CONST_B', $result['constants'] );
	}

	#[Test]
	public function resolves_stubs_paths_from_autoload_files_when_declared(): void {
		$package_dir = $this->vendor_dir . '/php-stubs/foo';
		\mkdir( $package_dir . '/build', 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs.php', $package_dir . '/build/stubs-bundled.php' );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'php-stubs/foo',
					'autoload' => array(
						'files' => array( 'build/stubs-bundled.php' ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);

		$this->writeProjectComposer( array( 'php-stubs/foo' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
	}

	#[Test]
	public function aggregates_symbols_across_multiple_autoload_files_in_one_package(): void {
		// Mimics woocommerce-stubs, which ships woocommerce-stubs.php + woocommerce-packages-stubs.php.
		$package_dir = $this->vendor_dir . '/php-stubs/multi';
		\mkdir( $package_dir, 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs.php',       $package_dir . '/core-stubs.php' );
		\copy( self::FIXTURES_DIR . '/stubs-extra.php', $package_dir . '/extra-stubs.php' );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'php-stubs/multi',
					'autoload' => array(
						'files' => array( 'core-stubs.php', 'extra-stubs.php' ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);

		$this->writeProjectComposer( array( 'php-stubs/multi' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action',     $result['functions'] );
		self::assertContains( 'wc_get_product', $result['functions'] );
	}

	#[Test]
	public function falls_back_to_convention_when_autoload_files_is_absent(): void {
		// installStubsPackage writes a composer.json with no autoload section, so the
		// helper must fall back to vendor/<vendor>/<name>/<name>.php.
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
	}

	#[Test]
	public function read_declaration_skips_non_string_entries_at_non_zero_index(): void {
		// Catches UnwrapArrayValues on read_declaration's return — without array_values(),
		// a non-string at index 0 would leave the survivor at index 1 (associative array, not list).
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 123, 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
	}

	private function event( bool $devMode = true, ?BufferIO $io = null ): Event {
		$composer = new Composer();
		$config   = new Config();
		$config->merge( array( 'config' => array( 'vendor-dir' => $this->vendor_dir ) ) );
		$composer->setConfig( $config );

		return new Event( 'post-autoload-dump', $composer, $io ?? new NullIO(), $devMode );
	}

	/**
	 * @param list<mixed> $scoping_stubs Test fixtures intentionally exercise mixed types to verify filtering.
	 */
	private function writeProjectComposer( array $scoping_stubs ): void {
		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode(
				array( 'extra' => array( 'scoping-stubs' => $scoping_stubs ) ),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * @param array<string, mixed> $composer_payload
	 */
	private function installPackage( string $package_name, array $composer_payload ): void {
		$package_dir = $this->vendor_dir . '/' . $package_name;
		\mkdir( $package_dir, 0755, true );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode( $composer_payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	private function installStubsPackage( string $package_name, string $stubs_source_path ): void {
		$package_dir = $this->vendor_dir . '/' . $package_name;
		\mkdir( $package_dir, 0755, true );

		// Stubs file lives at vendor/<vendor>/<name>/<name>.php by convention.
		$basename = \explode( '/', $package_name )[1];
		\copy( $stubs_source_path, $package_dir . '/' . $basename . '.php' );

		// Minimal composer.json so the package registers in vendor/.
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode( array( 'name' => $package_name ), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadOutput( string $filename ): array {
		$contents = \file_get_contents( $this->project_dir . '/' . $filename ) ?: throw new \RuntimeException( \sprintf( 'Could not read %s', $filename ) );

		return \json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	}

	private function rrmdir( string $dir ): void {
		// SAFETY: never follow symlinks — is_dir() returns true for symlink-to-dir.
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
