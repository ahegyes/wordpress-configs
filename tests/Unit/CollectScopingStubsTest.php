<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use Composer\IO\BufferIO;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Repository\InstalledArrayRepository;
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
	);

	private string $project_dir;
	private string $vendor_dir;

	/**
	 * Packages registered in the Composer local repository for the run under test.
	 * The script reads `extra.scoping-stubs` + `autoload.files` from these in-memory objects
	 * (mirroring a real install, where every package is both on disk and in installed.json).
	 *
	 * @var list<CompletePackage>
	 */
	private array $installed_packages = array();

	protected function setUp(): void {
		// Clear all env vars CollectScopingStubs reads, so each test starts from a known state.
		foreach ( self::ENV_VARS as $var ) {
			\putenv( $var );
		}

		$this->installed_packages = array();
		$this->project_dir        = \sys_get_temp_dir() . '/dws-wp-configs-test-' . \uniqid();
		$this->vendor_dir         = $this->project_dir . '/vendor';
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
			array(
				'classes'   => array(),
				'functions' => array(),
				'constants' => array(),
			),
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
	public function aggregates_multiple_declarations_from_one_installed_package(): void {
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		$this->writeProjectComposer( array() );
		$this->registerPackage(
			'some/catalog-consumer',
			extra: array(
				'scoping-stubs' => array(
					'php-stubs/wordpress-stubs',
					'php-stubs/woocommerce-stubs',
				),
			)
		);

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
		self::assertContains( 'wc_get_product', $result['functions'] );
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
			array(
				'classes'   => array(),
				'functions' => array(),
				'constants' => array(),
			),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
	}

	#[Test]
	public function continues_past_first_missing_stubs_package_to_collect_subsequent_ones(): void {
		$this->installStubsPackage( 'zzz/present', self::FIXTURES_DIR . '/stubs-extra.php' );
		$this->writeProjectComposer( array( 'aaa/missing', 'zzz/present' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'WC_Logger', $result['classes'] );
		self::assertContains( 'wc_get_product', $result['functions'] );
	}

	#[Test]
	public function throws_on_non_string_entry_in_root_scoping_stubs(): void {
		// The root declaration is the consumer's own file: a malformed entry there fails
		// loudly instead of being filtered into a silently smaller exclusion set.
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs', 123 ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_traversal_entry_in_root_scoping_stubs(): void {
		$this->writeProjectComposer( array( '../etc/passwd' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_malformed_package_name_in_root_scoping_stubs(): void {
		// Fails Composer's package-name regex (uppercase vendor).
		$this->writeProjectComposer( array( 'Foo/Bar' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function writes_default_output_to_project_root_when_vendor_dir_is_custom(): void {
		$this->vendor_dir = $this->project_dir . '/build/vendor';
		\mkdir( $this->vendor_dir, 0755, true );
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ), array( 'vendor-dir' => 'build/vendor' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/scoping-exclusions.json' );
		self::assertFileDoesNotExist( $this->project_dir . '/build/scoping-exclusions.json' );
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
		$this->registerPackage( 'php-stubs/foo', autoload: array( 'files' => array( 'build/stubs-bundled.php' ) ) );

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
		\copy( self::FIXTURES_DIR . '/stubs.php', $package_dir . '/core-stubs.php' );
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
		$this->registerPackage( 'php-stubs/multi', autoload: array( 'files' => array( 'core-stubs.php', 'extra-stubs.php' ) ) );

		$this->writeProjectComposer( array( 'php-stubs/multi' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
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
	public function package_declaration_skips_non_string_entries_and_keeps_survivors(): void {
		// Third-party tolerance: a non-string entry in an INSTALLED package's declaration is
		// dropped (with a warning) while the valid sibling entry still contributes symbols.
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array() );
		$this->registerPackage(
			'some/mixed-declaration',
			extra: array( 'scoping-stubs' => array( 123, 'php-stubs/wordpress-stubs' ) )
		);

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
		self::assertStringContainsString( 'some/mixed-declaration', $io->getOutput() );
	}

	#[Test]
	public function explicit_file_entry_resolves_a_secondary_stub_outside_autoload_files(): void {
		// Mimics php-stubs/woocommerce-stubs, which ships woocommerce-packages-stubs.php without
		// listing it in autoload.files. The bare package would resolve only the conventional
		// <name>.php; the explicit-file form reaches the secondary stubs file.
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		\copy(
			self::FIXTURES_DIR . '/stubs-secondary.php',
			$this->vendor_dir . '/php-stubs/woocommerce-stubs/woocommerce-packages-stubs.php'
		);
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'wc_get_container', $result['functions'] );
		self::assertContains( 'WC_Packages_Container', $result['classes'] );
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
		self::assertContains( 'wc_get_container', $result['functions'] );
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
		$this->registerPackage( 'php-stubs/woocommerce-stubs' );
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:build/packages.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'wc_get_container', $result['functions'] );
	}

	#[Test]
	public function skips_explicit_file_entry_pointing_at_a_missing_file(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// The package is installed, but the named secondary file does not exist.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:does-not-exist.php' ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array(
				'classes'   => array(),
				'functions' => array(),
				'constants' => array(),
			),
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
			array(
				'classes'   => array(),
				'functions' => array(),
				'constants' => array(),
			),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringContainsString( 'Skipping declared stubs file', $io->getOutput() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_with_a_traversal_segment(): void {
		// A secret file is planted as a sibling of the package dir, reachable only by escaping it.
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		\copy( self::FIXTURES_DIR . '/stubs-secondary.php', $this->vendor_dir . '/php-stubs/escape.php' );
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:../escape.php' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_with_an_absolute_path(): void {
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:/etc/passwd' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_whose_file_part_is_not_php(): void {
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// A non-.php file part is rejected at the validation filter.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:README.md' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_with_an_empty_file_part(): void {
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// Trailing-colon entry: valid package, empty file part — rejected at the filter.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_with_a_malformed_package_part(): void {
		// Uppercase package part fails Composer's package-name regex; the whole entry is dropped.
		$this->writeProjectComposer( array( 'Foo/Bar:stubs.php' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
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
		$this->registerPackage( 'php-stubs/compromised', autoload: array( 'files' => array( 'in-package.php', '../../../' . \basename( $secret ) ) ) );

		$this->writeProjectComposer( array( 'php-stubs/compromised' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		// The in-package file still resolves; the escaping one never does.
		self::assertContains( 'add_action', $result['functions'] );
		self::assertNotContains( 'bare_traversal_secret', $result['functions'] );
		self::assertNotContains( 'BareTraversalSecret', $result['classes'] );
	}

	#[Test]
	public function bare_package_with_nul_byte_autoload_files_entry_skips_it_without_fatal(): void {
		// realpath() throws a ValueError (not false) on a NUL byte, and a bare-package autoload.files
		// entry reaches it without is_safe_relative_path()'s NUL guard. The malformed entry must be
		// skipped, not abort the whole post-autoload-dump hook.
		$package_dir = $this->vendor_dir . '/php-stubs/nul';
		\mkdir( $package_dir, 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs.php', $package_dir . '/in-package.php' );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'php-stubs/nul',
					'autoload' => array( 'files' => array( 'in-package.php', "bad\0entry.php" ) ),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$this->registerPackage( 'php-stubs/nul', autoload: array( 'files' => array( 'in-package.php', "bad\0entry.php" ) ) );

		$this->writeProjectComposer( array( 'php-stubs/nul' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		// The hook completes without a ValueError; the legit file resolves and the NUL entry is skipped.
		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
	}

	#[Test]
	public function bare_package_does_not_read_sibling_directory_that_shares_the_package_prefix(): void {
		$package_dir = $this->vendor_dir . '/php-stubs/prefix';
		$sibling_dir = $this->vendor_dir . '/php-stubs/prefix-evil';
		\mkdir( $package_dir, 0755, true );
		\mkdir( $sibling_dir, 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs.php', $package_dir . '/legit.php' );
		\file_put_contents( $sibling_dir . '/secret.php', "<?php\nclass PrefixSiblingSecret {}\nfunction prefix_sibling_secret() {}\n" );
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'php-stubs/prefix',
					'autoload' => array(
						'files' => array( 'legit.php', '../prefix-evil/secret.php' ),
					),
				),
				JSON_THROW_ON_ERROR
			)
		);
		$this->registerPackage( 'php-stubs/prefix', autoload: array( 'files' => array( 'legit.php', '../prefix-evil/secret.php' ) ) );

		$this->writeProjectComposer( array( 'php-stubs/prefix' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
		self::assertNotContains( 'prefix_sibling_secret', $result['functions'] );
		self::assertNotContains( 'PrefixSiblingSecret', $result['classes'] );
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
		$this->registerPackage( 'php-stubs/symlinked' );

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
		$this->registerPackage( 'php-stubs/explicit-symlink' );

		$this->writeProjectComposer( array( 'php-stubs/explicit-symlink:secondary.php' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertNotContains( 'explicit_symlink_secret', $result['functions'] );
		self::assertNotContains( 'ExplicitSymlinkSecret', $result['classes'] );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_with_a_nul_byte(): void {
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// A NUL byte in the file part makes realpath throw a ValueError if it reaches it; the
		// validation rejects it first, and the root strictness turns that into a clean throw.
		$this->writeProjectComposer( array( "php-stubs/woocommerce-stubs:woocommerce-packages-stubs.php\0.php" ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_with_a_windows_drive_shaped_file_part(): void {
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// A Windows drive-letter shape (C:/...) carries a colon; rejected at the validation
		// filter before realpath could interpret the drive semantics.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:C:/secret.php' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_with_an_ntfs_ads_shaped_file_part(): void {
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// An NTFS alternate-data-stream shape (foo:bar.php) carries a colon; rejected at the
		// validation filter before realpath could interpret the stream semantics.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs:foo:bar.php' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function throws_on_root_explicit_file_entry_whose_file_part_still_contains_a_colon_after_split(): void {
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		// The first colon splits package from file; a second colon left in the file part
		// (here a leading-colon ADS shape) is rejected at the validation filter.
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs::stream.php' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Invalid root extra\.scoping-stubs entry/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function bare_package_still_resolves_all_in_package_symbols(): void {
		// Regression: the confinement refactor must not narrow legitimate in-package
		// resolution — both an autoload.files entry and the conventional fallback resolve.
		$package_dir = $this->vendor_dir . '/php-stubs/normal';
		\mkdir( $package_dir, 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs.php', $package_dir . '/listed.php' );
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
		$this->registerPackage( 'php-stubs/normal', autoload: array( 'files' => array( 'listed.php' ) ) );

		$this->writeProjectComposer( array( 'php-stubs/normal' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		// autoload.files entry resolved.
		self::assertContains( 'add_action', $result['functions'] );
		// Conventional <name>.php fallback also resolved.
		self::assertContains( 'wc_get_product', $result['functions'] );
	}

	#[Test]
	public function collects_scoping_stubs_from_a_symlinked_path_repository_package(): void {
		// A Composer path-repository package symlinked into vendor (monorepo dev) that declares
		// extra.scoping-stubs. A vendor directory walk does not descend the symlink and silently
		// misses the declaration; reading Composer's in-memory package list catches it. The
		// declared stubs package (wordpress-stubs) is normally installed.
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );

		// Real path-repo source dir OUTSIDE vendor.
		$source_dir = $this->project_dir . '/packages/wp-framework-bootstrap';
		\mkdir( $source_dir, 0755, true );
		\file_put_contents(
			$source_dir . '/composer.json',
			\json_encode(
				array(
					'name'  => 'ahegyes/wp-framework-bootstrap',
					'extra' => array( 'scoping-stubs' => array( 'php-stubs/wordpress-stubs' ) ),
				),
				JSON_THROW_ON_ERROR
			)
		);

		// Symlink it into vendor at the standard layout, exactly as Composer's path repo does.
		\mkdir( $this->vendor_dir . '/ahegyes', 0755, true );
		if ( ! @\symlink( $source_dir, $this->vendor_dir . '/ahegyes/wp-framework-bootstrap' ) ) {
			self::markTestSkipped( 'Environment cannot create symlinks.' );
		}
		$this->registerPackage( 'ahegyes/wp-framework-bootstrap', extra: array( 'scoping-stubs' => array( 'php-stubs/wordpress-stubs' ) ) );

		// The project itself declares nothing — the only source of the declaration is the
		// symlinked path-repo package.
		$this->writeProjectComposer( array() );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
		self::assertContains( 'WP_Filesystem_Base', $result['classes'] );
	}

	#[Test]
	public function resolves_stubs_through_a_symlinked_path_repository_install_path(): void {
		// A symlinked path-repo package that ships its own stubs file must still resolve through
		// the vendor-layout symlink to the real source.
		$source_dir = $this->project_dir . '/packages/wp-framework-shared';
		\mkdir( $source_dir, 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs-extra.php', $source_dir . '/shared-stubs.php' );
		\file_put_contents(
			$source_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'ahegyes/wp-framework-shared',
					'autoload' => array( 'files' => array( 'shared-stubs.php' ) ),
				),
				JSON_THROW_ON_ERROR
			)
		);

		\mkdir( $this->vendor_dir . '/ahegyes', 0755, true );
		if ( ! @\symlink( $source_dir, $this->vendor_dir . '/ahegyes/wp-framework-shared' ) ) {
			self::markTestSkipped( 'Environment cannot create symlinks.' );
		}
		$this->registerPackage( 'ahegyes/wp-framework-shared', autoload: array( 'files' => array( 'shared-stubs.php' ) ) );

		$this->writeProjectComposer( array( 'ahegyes/wp-framework-shared' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'wc_get_product', $result['functions'] );
	}

	#[Test]
	public function resolves_stubs_through_the_install_path_honoring_target_dir(): void {
		// A package with a target-dir installs under vendor/<name>/<target-dir>; the script must
		// resolve its stubs via Composer's getInstallPath (which appends the target-dir), not a
		// synthesized vendor/<name> layout. The stub lives ONLY under the target-dir subpath, so a
		// layout that ignored target-dir would find nothing.
		$install_dir = $this->vendor_dir . '/php-stubs/targeted/Stubs/Build';
		\mkdir( $install_dir, 0755, true );
		\copy( self::FIXTURES_DIR . '/stubs.php', $install_dir . '/targeted.php' );

		$package = new CompletePackage( 'php-stubs/targeted', '1.0.0.0', '1.0.0' );
		$package->setTargetDir( 'Stubs/Build' );
		$this->installed_packages[] = $package;

		$this->writeProjectComposer( array( 'php-stubs/targeted' ) );
		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
	}

	#[Test]
	public function skips_package_when_install_path_resolution_throws(): void {
		$io      = new BufferIO();
		$package = new CompletePackage( 'php-stubs/custom-installer', '1.0.0.0', '1.0.0' );
		$package->setType( 'custom-installer' );
		$this->installed_packages[] = $package;

		$this->writeProjectComposer( array( 'php-stubs/custom-installer' ) );
		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		self::assertSame(
			array(
				'classes'   => array(),
				'functions' => array(),
				'constants' => array(),
			),
			$this->loadOutput( 'scoping-exclusions.json' )
		);
		self::assertStringContainsString( 'Skipping declared stubs package "php-stubs/custom-installer"', $io->getOutput() );
	}

	#[Test]
	public function tolerates_installed_packages_with_absent_or_malformed_scoping_stubs(): void {
		$io = new BufferIO();
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		// No extra at all; extra present but no scoping-stubs key; a scalar (non-array) value;
		// and an object/associative-array value whose entries are not valid declarations — every
		// malformed shape in an INSTALLED package is skipped (the consumer cannot fix third-party
		// metadata) but surfaced as a warning, without disturbing the valid project declaration.
		$this->registerPackage( 'some/plain-package' );
		$this->registerPackage( 'some/other-extra', extra: array( 'branch-alias' => array( 'dev-main' => '1.x-dev' ) ) );
		$this->registerPackage( 'some/scalar-stubs', extra: array( 'scoping-stubs' => 'php-stubs/wordpress-stubs' ) );
		$this->registerPackage( 'some/object-stubs', extra: array( 'scoping-stubs' => array( 'key' => 'value' ) ) );

		CollectScopingStubs::postAutoloadDump( $this->event( io: $io ) );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		self::assertContains( 'add_action', $result['functions'] );
		// The scalar shape names a real package but is not a list, so it contributes nothing —
		// add_action appears exactly once (from the project declaration), not duplicated.
		self::assertSame( 1, \count( \array_keys( $result['functions'], 'add_action', true ) ) );
		// Each malformed shape is named in a warning; absent declarations warn nothing.
		self::assertStringContainsString( 'some/scalar-stubs', $io->getOutput() );
		self::assertStringContainsString( 'some/object-stubs', $io->getOutput() );
		self::assertStringNotContainsString( 'some/plain-package', $io->getOutput() );
		self::assertStringNotContainsString( 'some/other-extra', $io->getOutput() );
	}

	#[Test]
	public function throws_when_root_scoping_stubs_is_not_an_array(): void {
		// A malformed root declaration (scoping-stubs as a string, not a list) fails loudly —
		// silently writing empty exclusions would surface only as prefixed host symbols at runtime.
		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode( array( 'extra' => array( 'scoping-stubs' => 'php-stubs/wordpress-stubs' ) ), JSON_THROW_ON_ERROR )
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/root extra\.scoping-stubs must be an array/' );

		CollectScopingStubs::postAutoloadDump( $this->event() );
	}

	#[Test]
	public function output_symbol_lists_are_sorted_for_deterministic_regeneration(): void {
		// Two stubs packages whose symbols interleave; the merged lists must come out sorted so the
		// file regenerates byte-identical regardless of package iteration order.
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->installStubsPackage( 'php-stubs/woocommerce-stubs', self::FIXTURES_DIR . '/stubs-extra.php' );
		$this->writeProjectComposer( array( 'php-stubs/woocommerce-stubs', 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		$result = $this->loadOutput( 'scoping-exclusions.json' );
		foreach ( array( 'classes', 'functions', 'constants' ) as $kind ) {
			$sorted = $result[ $kind ];
			\sort( $sorted );
			self::assertSame( $sorted, $result[ $kind ], "$kind list is not sorted" );
		}
		self::assertNotSame( array(), $result['functions'] );
	}

	#[Test]
	public function writes_output_and_leaves_no_temp_file_behind(): void {
		// Happy-path smoke check, NOT an atomicity guarantee: write_atomically's temp-then-rename
		// internals are @infection-ignore-all (an accepted decision), so this only asserts a
		// successful run produces the final file and leaves no leftover `.tmp.*` sibling.
		$this->installStubsPackage( 'php-stubs/wordpress-stubs', self::FIXTURES_DIR . '/stubs.php' );
		$this->writeProjectComposer( array( 'php-stubs/wordpress-stubs' ) );

		CollectScopingStubs::postAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/scoping-exclusions.json' );
		$leftovers = \glob( $this->project_dir . '/scoping-exclusions.json.tmp.*' ) ?: array();
		self::assertSame( array(), $leftovers, 'A temp file was left behind by the atomic write.' );
	}

	private function event( bool $devMode = true, ?BufferIO $io = null ): Event {
		$io_instance = $io ?? new NullIO();

		// A real Factory-assembled Composer wires a genuine InstallationManager, so the script's
		// getInstallPath() resolves package dirs exactly as production does (honouring target-dir
		// and custom installer paths). The root package's extra is read from the on-disk
		// composer.json; the local repository is swapped for the run's registered packages.
		$composer = \Composer\Factory::create( $io_instance, $this->project_dir . '/composer.json', true );
		$composer->getRepositoryManager()->setLocalRepository( new InstalledArrayRepository( $this->installed_packages ) );

		return new Event( 'post-autoload-dump', $composer, $io_instance, $devMode );
	}

	/**
	 * Registers a package in the Composer local repository for the run under test.
	 *
	 * @param array<array-key, mixed> $extra    The package's `extra` metadata.
	 * @param array<array-key, mixed> $autoload The package's `autoload` rules.
	 */
	private function registerPackage( string $name, array $extra = array(), array $autoload = array() ): void {
		$package = new CompletePackage( $name, '1.0.0.0', '1.0.0' );
		if ( array() !== $extra ) {
			$package->setExtra( $extra );
		}
		if ( array() !== $autoload ) {
			$package->setAutoload( $autoload );
		}

		$this->installed_packages[] = $package;
	}

	/**
	 * @param list<mixed>              $scoping_stubs Test fixtures intentionally exercise mixed types to verify filtering.
	 * @param array<array-key, mixed>  $config         Composer config values to write.
	 */
	private function writeProjectComposer( array $scoping_stubs, array $config = array() ): void {
		$payload = array( 'extra' => array( 'scoping-stubs' => $scoping_stubs ) );
		if ( array() !== $config ) {
			$payload['config'] = $config;
		}

		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode(
				$payload,
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

		$extra    = isset( $composer_payload['extra'] ) && \is_array( $composer_payload['extra'] ) ? $composer_payload['extra'] : array();
		$autoload = isset( $composer_payload['autoload'] ) && \is_array( $composer_payload['autoload'] ) ? $composer_payload['autoload'] : array();
		$this->registerPackage( $package_name, $extra, $autoload );
	}

	/**
	 * @param array<array-key, mixed> $autoload The package's `autoload` rules (empty ⇒ convention-only).
	 */
	private function installStubsPackage( string $package_name, string $stubs_source_path, array $autoload = array() ): void {
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

		$this->registerPackage( $package_name, autoload: $autoload );
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
