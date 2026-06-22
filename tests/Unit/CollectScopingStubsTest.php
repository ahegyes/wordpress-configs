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

	#[Test]
	public function explicit_file_entry_resolves_a_secondary_stub_outside_autoload_files(): void {
		// Mimics php-stubs/woocommerce-stubs, which ships woocommerce-packages-stubs.php
		// (Action Scheduler as_* functions) WITHOUT listing it in autoload.files. The bare
		// package would resolve only the conventional <name>.php; the explicit-file form
		// reaches the secondary catalog.
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		\copy(
			self::FIXTURES_DIR . '/stubs-secondary.php',
			$this->vendor_dir . '/php-stubs/woocommerce-stubs/woocommerce-packages-stubs.php'
		);
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'as_schedule_single_action', $result['functions'] );
		self::assertContains( 'ActionScheduler_Store', $result['classes'] );
		// Only the named file is resolved — the conventional <name>.php is NOT pulled in.
		self::assertNotContains( 'wc_get_product', $result['functions'] );
	}

	#[Test]
	public function bare_and_explicit_file_entries_for_the_same_package_union(): void {
		// A package can be declared both bare (its autoload.files / convention) and via an
		// explicit secondary file; the symbol sets union.
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		\copy(
			self::FIXTURES_DIR . '/stubs-secondary.php',
			$this->vendor_dir . '/php-stubs/woocommerce-stubs/woocommerce-packages-stubs.php'
		);
		$this->writeProjectComposer(
			array(
				'php-stubs/woocommerce-stubs',
				'php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php',
			)
		);

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'wc_get_product', $result['functions'] );
		self::assertContains( 'as_schedule_single_action', $result['functions'] );
	}

	#[Test]
	public function explicit_file_entry_in_a_nested_subdirectory_resolves(): void {
		// The named file may live in a subdirectory of the package — still inside the
		// package root, so containment passes.
		$package_dir = $this->vendor_dir . '/php-stubs/woocommerce-stubs';
		\mkdir( $package_dir . '/build', 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs-secondary.php', $package_dir . '/build/packages.php' );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode( array( 'name' => 'php-stubs/woocommerce-stubs' ), JSON_THROW_ON_ERROR )
		);
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:build/packages.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'as_schedule_single_action', $result['functions'] );
	}

	#[Test]
	public function skips_explicit_file_entry_pointing_at_a_missing_file(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// The package is installed, but the named secondary file does not exist.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:does-not-exist.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringContainsString( 'Skipping declared stubs file', $io->getOutput() );
		self::assertStringContainsString( 'does-not-exist.php', $io->getOutput() );
	}

	#[Test]
	public function skips_explicit_file_entry_when_the_package_is_not_installed(): void {
		$io = new BufferIO();
		// Neither the package nor the file exist — realpath of the package dir fails.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringContainsString( 'Skipping declared stubs file', $io->getOutput() );
	}

	#[Test]
	public function rejects_explicit_file_entry_with_a_traversal_segment(): void {
		$io = new BufferIO();
		// A secret file is planted as a sibling of the package dir, reachable only by escaping it.
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		\copy( self::FIXTURES_DIR . '/stubs-secondary.php', $this->vendor_dir . '/php-stubs/escape.php' );
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:../escape.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		// The traversal target's symbols never appear — rejected at the validation filter,
		// so the entry is dropped before resolution and emits no skip note for it.
		self::assertNotContains( 'as_schedule_single_action', $result['functions'] );
		self::assertNotContains( 'ActionScheduler_Store', $result['classes'] );
		self::assertStringNotContainsString( '../escape.php', $io->getOutput() );
	}

	#[Test]
	public function rejects_explicit_file_entry_with_an_absolute_path(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:/etc/passwd' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringNotContainsString( '/etc/passwd', $io->getOutput() );
	}

	#[Test]
	public function rejects_explicit_file_entry_whose_file_part_is_not_php(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// A non-.php file part is rejected at the validation filter.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:README.md' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertStringNotContainsString( 'README.md', $io->getOutput() );
		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function rejects_explicit_file_entry_with_an_empty_file_part(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// Trailing-colon entry: valid package, empty file part — rejected at the filter.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertStringNotContainsString( 'woocommerce-stubs:', $io->getOutput() );
		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function rejects_explicit_file_entry_with_a_malformed_package_part(): void {
		$io = new BufferIO();
		// Uppercase package part fails Composer's package-name regex; the whole entry is dropped.
		$this->writeProjectComposer( array( 'Foo/Bar:stubs.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertStringNotContainsString( 'Foo/Bar', $io->getOutput() );
		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function bare_package_does_not_read_autoload_files_traversal_entry(): void {
		// A compromised stub package whose autoload.files points outside its own dir must
		// not have the escaped file's symbols harvested. The bare-package resolver
		// realpath-confines every candidate to the package dir.
		$secret = $this->plantOutsideSecret( 'BareTraversalSecret', 'bare_traversal_secret' );

		$package_dir = $this->vendor_dir . '/php-stubs/compromised';
		\mkdir( $package_dir, 0755, true );
		// In-package file the package legitimately ships alongside the escaping entry.
		\copy( self::FIXTURES_DIR . '/stubs.php', $package_dir . '/in-package.php' );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'php-stubs/compromised',
					'autoload' => array(
						'files' => array( 'in-package.php', '../../../' . \basename( $secret ) ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);

		$this->writeProjectComposer( array( 'php-stubs/compromised' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		// The in-package file still resolves; the escaping one never does.
		self::assertContains( 'add_action', $result['functions'] );
		self::assertNotContains( 'bare_traversal_secret', $result['functions'] );
		self::assertNotContains( 'BareTraversalSecret', $result['classes'] );
	}

	#[Test]
	public function bare_package_does_not_follow_conventional_symlink_outside_package(): void {
		// The conventional <name>/<name>.php fallback, if symlinked outside the package,
		// must be rejected by the realpath confinement.
		$secret = $this->plantOutsideSecret( 'BareSymlinkSecret', 'bare_symlink_secret' );

		$package_dir = $this->vendor_dir . '/php-stubs/symlinked';
		\mkdir( $package_dir, 0755, true );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode( array( 'name' => 'php-stubs/symlinked' ), JSON_THROW_ON_ERROR )
		);
		if ( ! @\symlink( $secret, $package_dir . '/symlinked.php' ) ) {
			self::markTestSkipped( 'Environment cannot create symlinks.' );
		}

		$this->writeProjectComposer( array( 'php-stubs/symlinked' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertNotContains( 'bare_symlink_secret', $result['functions'] );
		self::assertNotContains( 'BareSymlinkSecret', $result['classes'] );
	}

	#[Test]
	public function explicit_file_does_not_follow_symlink_outside_package(): void {
		// An explicit-file entry naming an in-package path that is itself a symlink pointing
		// outside the package must be rejected — is_safe_relative_path cannot see through a
		// symlink, so realpath confinement is the line of defence.
		$secret = $this->plantOutsideSecret( 'ExplicitSymlinkSecret', 'explicit_symlink_secret' );

		$package_dir = $this->vendor_dir . '/php-stubs/explicit-symlink';
		\mkdir( $package_dir, 0755, true );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode( array( 'name' => 'php-stubs/explicit-symlink' ), JSON_THROW_ON_ERROR )
		);
		if ( ! @\symlink( $secret, $package_dir . '/secondary.php' ) ) {
			self::markTestSkipped( 'Environment cannot create symlinks.' );
		}

		$this->writeProjectComposer( array( 'php-stubs/explicit-symlink:secondary.php' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertNotContains( 'explicit_symlink_secret', $result['functions'] );
		self::assertNotContains( 'ExplicitSymlinkSecret', $result['classes'] );
	}

	#[Test]
	public function explicit_file_entry_with_a_nul_byte_is_skipped_without_crashing(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// A NUL byte in the file part makes realpath throw a ValueError if it reaches it;
		// the validation filter rejects it first, so the entry is dropped silently.
		$this->writeProjectComposer( array( "php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php\0.php" ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function rejects_explicit_file_entry_with_a_windows_drive_shaped_file_part(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// A Windows drive-letter shape (C:/...) carries a colon; rejected at the validation
		// filter before realpath could interpret the drive semantics.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:C:/secret.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringNotContainsString( 'C:/secret.php', $io->getOutput() );
	}

	#[Test]
	public function rejects_explicit_file_entry_with_an_ntfs_ads_shaped_file_part(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// An NTFS alternate-data-stream shape (foo:bar.php) carries a colon; rejected at the
		// validation filter before realpath could interpret the stream semantics.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:foo:bar.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringNotContainsString( 'foo:bar.php', $io->getOutput() );
	}

	#[Test]
	public function rejects_explicit_file_entry_whose_file_part_still_contains_a_colon_after_split(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// The first colon splits package from file; a second colon left in the file part
		// (here a leading-colon ADS shape) is rejected at the validation filter.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs::stream.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array( 'classes' => array(), 'functions' => array(), 'constants' => array() ),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringNotContainsString( ':stream.php', $io->getOutput() );
	}

	#[Test]
	public function bare_package_still_resolves_all_in_package_symbols(): void {
		// Regression: the confinement refactor must not narrow legitimate in-package
		// resolution — both an autoload.files entry and the conventional fallback resolve.
		$package_dir = $this->vendor_dir . '/php-stubs/normal';
		\mkdir( $package_dir, 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs.php',       $package_dir . '/listed.php' );
		\copy( self::FIXTURES_DIR . '/stubs-extra.php', $package_dir . '/normal.php' );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'php-stubs/normal',
					'autoload' => array(
						'files' => array( 'listed.php' ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);

		$this->writeProjectComposer( array( 'php-stubs/normal' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		// autoload.files entry resolved.
		self::assertContains( 'add_action', $result['functions'] );
		// Conventional <name>.php fallback also resolved.
		self::assertContains( 'wc_get_product', $result['functions'] );
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

	/**
	 * Plants a stubs file carrying a uniquely-named class + function OUTSIDE every test
	 * package dir (at the project root), and returns its absolute path. A containment leak
	 * would harvest these symbols; the confinement tests assert they never appear.
	 */
	private function plantOutsideSecret( string $class, string $function ): string {
		$path = $this->project_dir . '/' . \uniqid( 'secret-' ) . '.php';
		\file_put_contents( $path, "<?php\nclass $class {}\nfunction $function() {}\n" );

		return $path;
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
