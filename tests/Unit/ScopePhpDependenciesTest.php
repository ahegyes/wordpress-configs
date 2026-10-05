<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Composer\ScopePhpDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ScopePhpDependenciesTest extends TestCase {

	protected const string FIXTURE_DIR = __DIR__ . '/../fixtures/scoping-project';

	protected const string VENDOR_DIR = __DIR__ . '/../../vendor';

	protected const array FIXTURE_PACKAGES = array( 'acme/commerce', 'acme/devtool', 'acme/legacy', 'acme/loader' );

	protected const array THIRD_PARTY_PACKAGES = array( 'laravel/serializable-closure', 'php-di/invoker', 'php-di/php-di', 'psr/container', 'psr/log' );

	protected const string SECTION = '/acme/commerce/src/SettingsSection.php';

	/**
	 * @var list<string>
	 */
	protected static array $projects = array();

	protected static ?string $scoped_project = null;

	protected static string|false $columns = false;

	#[\Override]
	public static function setUpBeforeClass(): void {
		// A narrow terminal, the default on a CI runner, makes php-scoper wrap the failure messages it prints.
		self::$columns = \getenv( 'COLUMNS' );
		\putenv( 'COLUMNS=40' );
	}

	#[\Override]
	public static function tearDownAfterClass(): void {
		new Filesystem()->remove( self::$projects );
		self::$projects       = array();
		self::$scoped_project = null;
		\putenv( false === self::$columns ? 'COLUMNS' : 'COLUMNS=' . self::$columns );
	}

	public function test_named_packages_and_their_runtime_dependencies_are_scoped(): void {
		self::assertSame(
			array( 'acme/commerce', 'acme/legacy', 'acme/loader', 'laravel/serializable-closure', 'php-di/invoker', 'php-di/php-di', 'psr/container', 'psr/log' ),
			self::package_dirs( self::scoped_project() . '/vendor-prefixed' )
		);
		self::assertStringContainsString( 'namespace Acme\Scoped\Acme\Loader;', self::read( self::scoped_project() . '/vendor-prefixed/acme/loader/load.php' ) );
		self::assertDirectoryDoesNotExist( self::scoped_project() . '/vendor-prefixed/acme/commerce/tests' );
	}

	/**
	 * @param list<string> $names
	 */
	#[DataProvider( 'provide_single_vendor_closures' )]
	public function test_a_single_vendor_closure_still_nests_under_its_vendor_directory( array $names ): void {
		$project = self::make_project( $names );
		new Filesystem()->mirror( self::VENDOR_DIR . '/psr/log', $project . '/vendor-prefixed/psr/log' );

		ScopePhpDependencies::scope( $project );

		self::assertSame( array( 'acme', 'scoper-autoload.php' ), self::entries( $project . '/vendor-prefixed' ) );
		self::assertSame( $names, self::package_dirs( $project . '/vendor-prefixed' ) );
	}

	/**
	 * @return array<string, array{list<string>}>
	 */
	public static function provide_single_vendor_closures(): array {
		return array(
			'one-package' => array( array( 'acme/loader' ) ),
			'one-vendor'  => array( array( 'acme/legacy', 'acme/loader' ) ),
		);
	}

	public function test_non_php_files_ship_with_their_package(): void {
		self::assertFileEquals(
			self::FIXTURE_DIR . '/packages/commerce/resources/notice.css',
			self::scoped_project() . '/vendor-prefixed/acme/commerce/resources/notice.css'
		);
	}

	public function test_scoped_autoload_loads_prefixed_classes_and_files_without_global_aliases(): void {
		$script = \sprintf(
			'namespace Automattic\WooCommerce\Admin\Settings { abstract class SettingsSection {} } namespace { require %s; require %s; echo json_encode( array( interface_exists( %s ), class_exists( %s ), class_exists( %s ), class_exists( %s ), class_exists( %s ), function_exists( %s ), function_exists( %s ), get_class( %s::format( "Csv" ) ), class_exists( "LegacyRenderer", false ), class_exists( "AcmeLegacy_Config", false ) ) ); }',
			\var_export( self::VENDOR_DIR . '/composer/ClassLoader.php', true ),
			\var_export( self::scoped_project() . '/vendor-prefixed/scoper-autoload.php', true ),
			\var_export( 'Acme\Scoped\Psr\Log\LoggerInterface', true ),
			\var_export( 'Acme\Scoped\LegacyRenderer', true ),
			\var_export( 'Acme\Scoped\LegacyCanvas', true ),
			\var_export( 'Acme\Scoped\AcmeLegacy_Config', true ),
			\var_export( 'Acme\Scoped\DI\ContainerBuilder', true ),
			\var_export( 'Acme\Scoped\DI\create', true ),
			\var_export( 'Acme\Scoped\Acme\Commerce\get_label_prefix', true ),
			'\Acme\Scoped\Acme\Commerce\SettingsSection'
		);
		\exec( \escapeshellarg( \PHP_BINARY ) . ' -r ' . \escapeshellarg( $script ) . ' 2>&1', $output, $exit_code );

		self::assertSame( 0, $exit_code, \implode( "\n", $output ) );
		self::assertSame( '[true,true,true,true,true,true,true,"Acme\\\\Scoped\\\\Acme\\\\Commerce\\\\Formats\\\\Csv",false,false]', \implode( "\n", $output ) );
	}

	public function test_the_classmap_skips_paths_excluded_from_it(): void {
		$autoload = self::read( self::scoped_project() . '/vendor-prefixed/scoper-autoload.php' );

		self::assertStringContainsString( "'Acme\\\\Scoped\\\\AcmeLegacy_Config' => __DIR__ . '/acme/legacy/library/AcmeLegacy/Config.php'", $autoload );
		self::assertStringNotContainsString( 'AcmeLegacy_Language_en', $autoload );
		self::assertStringNotContainsString( 'AcmeLegacy_Locale_de', $autoload );
		self::assertStringContainsString( "'Acme\\\\Scoped\\\\AcmeLegacy_LocaleData'", $autoload );
		self::assertStringContainsString( "'Acme\\\\Scoped\\\\AcmeLegacy_Extra' => __DIR__ . '/acme/legacy/plugins/extra/Extra.php'", $autoload );
		\preg_match_all( "/^\\t'([^']+)' => __DIR__/m", $autoload, $classes );
		$sorted = $classes[1];
		\sort( $sorted );
		self::assertSame( $sorted, $classes[1] );
	}

	public function test_php_di_keeps_its_template_and_optional_proxies_and_compares_prefixed_names(): void {
		$php_di   = self::scoped_project() . '/vendor-prefixed/php-di/php-di/src';
		$resolver = self::read( $php_di . '/Invoker/FactoryParameterResolver.php' );

		self::assertStringContainsString( "'Acme\\Scoped\\Psr\\Container\\ContainerInterface'", $resolver );
		self::assertStringContainsString( "'Acme\\Scoped\\DI\\Factory\\RequestedEntry'", $resolver );
		self::assertStringContainsString( 'use ProxyManager\\', self::read( $php_di . '/Proxy/ProxyFactory.php' ) );
		self::assertFileEquals( self::VENDOR_DIR . '/php-di/php-di/src/Compiler/Template.php', $php_di . '/Compiler/Template.php' );
	}

	public function test_host_symbols_stay_global_and_strings_take_the_text_domain(): void {
		$section = self::read( self::scoped_project() . '/vendor-prefixed' . self::SECTION );

		self::assertStringContainsString( 'extends \Automattic\WooCommerce\Admin\Settings\SettingsSection', $section );
		self::assertStringContainsString( '\wc_get_logger()', $section );
		self::assertStringContainsString( '\as_enqueue_async_action(', $section );
		self::assertStringContainsString( '\host_plugin_setting()', $section );
		self::assertStringContainsString( "'Automattic\\WooCommerce\\Utilities\\OrderUtil'", $section );
		self::assertStringContainsString( "\\__('Commerce', 'acme-text-domain')", $section );
		self::assertStringNotContainsString( 'acme-commerce', $section );
	}

	public function test_a_dropped_package_and_its_symbols_stay_global(): void {
		$project = self::make_project( array( 'acme/commerce', '!psr/log' ) );

		ScopePhpDependencies::scope( $project );

		self::assertNotContains( 'psr/log', self::package_dirs( $project . '/vendor-prefixed' ) );
		self::assertStringContainsString( 'use Psr\Log\LoggerInterface;', self::read( $project . '/vendor-prefixed' . self::SECTION ) );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	#[DataProvider( 'provide_incomplete_text_domains' )]
	public function test_scope_run_fails_without_a_text_domain_when_a_package_declares_one( array $extra ): void {
		$project = self::make_project( array( 'acme/commerce' ), $extra );

		try {
			ScopePhpDependencies::scope( $project );
			self::fail( 'The scope run did not fail.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringStartsWith( "php-scoper failed:\n", $exception->getMessage() );
			self::assertStringContainsString( 'extra.text-domain', $exception->getMessage() );
		}
		self::assertFileExists( $project . '/vendor-prefixed/scoper-autoload.php' );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function provide_incomplete_text_domains(): array {
		return array(
			'missing' => array( array( 'text-domain' => null ) ),
			'empty'   => array( array( 'text-domain' => '' ) ),
			'false'   => array( array( 'text-domain' => false ) ),
		);
	}

	public function test_a_project_that_scopes_no_declaring_package_needs_no_text_domain(): void {
		$project = self::make_project( array( 'acme/legacy' ), array( 'text-domain' => null ) );

		ScopePhpDependencies::scope( $project );

		self::assertSame( array( 'acme/legacy' ), self::package_dirs( $project . '/vendor-prefixed' ) );
	}

	public function test_a_failed_scope_run_keeps_the_previous_build(): void {
		$project = self::make_project( array( 'acme/commerce' ) );
		ScopePhpDependencies::scope( $project );
		$autoload = self::read( $project . '/vendor-prefixed/scoper-autoload.php' );
		\file_put_contents( $project . '/composer.json', \str_replace( '"text-domain": "acme-text-domain"', '"text-domain": ""', self::read( $project . '/composer.json' ) ) );

		try {
			ScopePhpDependencies::scope( $project );
			self::fail( 'The scope run did not fail.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'extra.text-domain', $exception->getMessage() );
		}
		self::assertSame( $autoload, self::read( $project . '/vendor-prefixed/scoper-autoload.php' ) );
		self::assertContains( 'acme/commerce', self::package_dirs( $project . '/vendor-prefixed' ) );
	}

	#[DataProvider( 'provide_missing_stubs' )]
	public function test_scope_run_fails_when_declared_stubs_are_missing( string $manifest, string $search, string $replace, string $absent ): void {
		$project  = self::make_project( array( 'acme/commerce' ) );
		$manifest = $project . $manifest;
		\file_put_contents( $manifest, \str_replace( $search, $replace, self::read( $manifest ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( $absent );

		ScopePhpDependencies::scope( $project );
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function provide_missing_stubs(): array {
		return array(
			'package-declared-package' => array( '/vendor/acme/commerce/composer.json', '"php-stubs/woocommerce-stubs",', '"acme/absent-stubs",', 'acme/absent-stubs' ),
			'project-declared-file'    => array( '/composer.json', './stubs/host-plugin.php', './stubs/absent.php', './stubs/absent.php' ),
		);
	}

	#[DataProvider( 'provide_undeclared_prefixed_names' )]
	public function test_scope_run_fails_on_a_prefixed_name_the_scoped_code_does_not_declare( string $search, string $replace, string $name ): void {
		$project = self::make_project( array( 'acme/commerce' ) );
		$section = $project . '/vendor' . self::SECTION;
		\file_put_contents( $section, \str_replace( $search, $replace, self::read( $section ) ) );

		try {
			ScopePhpDependencies::scope( $project );
			self::fail( 'The scope run did not fail.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringStartsWith( 'No scoped package declares these prefixed names', $exception->getMessage() );
			self::assertMatchesRegularExpression( '/\n' . \preg_quote( $name . ' in ', '/' ) . '.+SettingsSection\.php:\d+$/m', $exception->getMessage() );
		}
		self::assertStringContainsString( "'Acme\\\\Scoped\\\\Acme\\\\Commerce\\\\SettingsSection'", self::read( $project . '/vendor-prefixed/scoper-autoload.php' ) );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function provide_undeclared_prefixed_names(): array {
		$return = "\t\treturn \\__(";

		return array(
			'function-newer-than-the-stubs'   => array( $return, "\t\t\\wc_get_future_feature();\n" . $return, 'Acme\Scoped\wc_get_future_feature' ),
			'class-string-newer-than-stubs'   => array( $return, "\t\t\\class_exists( 'WC_Future_Feature' );\n" . $return, 'Acme\Scoped\WC_Future_Feature' ),
			'namespace-import-of-a-host'      => array( 'use Psr\Log\LoggerInterface;', "use Psr\\Log\\LoggerInterface;\nuse Automattic\\WooCommerce\\Future\\Feature;", 'Acme\Scoped\Automattic\WooCommerce\Future\Feature' ),
			'escape-sequence-taken-for-class' => array( "\t// region METHODS", "\t// region METHODS\n\n\tpublic string \$line_break = '\\\\r\\\\n';", 'Acme\Scoped\r\n' ),
			'code-in-a-php-nowdoc'            => array( $return, "\t\t\$code = <<<'PHP'\n\t\t\t<?php \\wc_get_future_feature();\n\t\t\tPHP;\n" . $return, 'Acme\Scoped\wc_get_future_feature' ),
			'group-use-of-a-host'             => array( 'use Psr\Log\LoggerInterface;', "use Psr\\Log\\LoggerInterface;\nuse Automattic\\WooCommerce\\Future\\{Feature};", 'Acme\Scoped\Automattic\WooCommerce\Future\Feature' ),
		);
	}

	public function test_scope_run_fails_when_a_declared_text_domain_survives_the_rewrite(): void {
		$project = self::make_project( array( 'acme/commerce' ) );
		$section = $project . '/vendor' . self::SECTION;
		\file_put_contents( $section, \str_replace( "\t\treturn \\__(", "\t\t\$credit = 'Translated by acme-commerce';\n\t\treturn \\__(", self::read( $section ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( 'acme-commerce' );

		ScopePhpDependencies::scope( $project );
	}

	public function test_scope_run_fails_when_a_recipe_target_is_missing(): void {
		$project = self::make_project( array( 'php-di/php-di' ) );
		\unlink( $project . '/vendor/php-di/php-di/src/Compiler/Template.php' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( 'src/Compiler/Template.php' );

		ScopePhpDependencies::scope( $project );
	}

	public function test_scope_run_fails_before_php_scoper_without_a_scoping_prefix(): void {
		$project = self::make_project( array( 'acme/loader' ), array( 'scoping-prefix' => null ) );

		try {
			ScopePhpDependencies::scope( $project );
			self::fail( 'The scope run did not fail.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'extra.scoping-prefix', $exception->getMessage() );
		}
		self::assertDirectoryDoesNotExist( $project . '/vendor-prefixed' );
	}

	public function test_scope_run_fails_without_the_project_directory(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( 'does not exist' );

		ScopePhpDependencies::scope( self::FIXTURE_DIR . '/absent' );
	}

	/**
	 * @param list<string> $names
	 * @param list<string> $installed
	 */
	#[DataProvider( 'provide_missing_packages' )]
	public function test_scope_run_fails_when_a_package_to_scope_is_missing( array $names, array $installed, string $message ): void {
		$project = self::make_project( $names, array(), $installed );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( $message );

		ScopePhpDependencies::scope( $project );
	}

	/**
	 * @return array<string, array{list<string>, list<string>, string}>
	 */
	public static function provide_missing_packages(): array {
		$without_psr_log = \array_values( \array_diff( \array_merge( self::FIXTURE_PACKAGES, self::THIRD_PARTY_PACKAGES ), array( 'psr/log' ) ) );

		return array(
			'named-package'       => array( array( 'acme/absent' ), self::FIXTURE_PACKAGES, 'acme/absent' ),
			'pattern'             => array( array( 'acme/absent-*' ), self::FIXTURE_PACKAGES, 'acme/absent-*' ),
			'required-dependency' => array( array( 'acme/commerce' ), $without_psr_log, 'psr/log' ),
			'no-package'          => array( array(), self::FIXTURE_PACKAGES, 'Name at least one package' ),
		);
	}

	public function test_scope_run_reads_packages_from_the_composer_vendor_directory(): void {
		$project = self::make_project( array( 'acme/legacy' ), array(), self::FIXTURE_PACKAGES, 'lib/vendor' );

		ScopePhpDependencies::scope( $project );

		self::assertSame( array( 'acme/legacy' ), self::package_dirs( $project . '/vendor-prefixed' ) );
	}

	public function test_scope_run_refuses_an_output_directory_that_resolves_elsewhere(): void {
		$project = self::make_project( array( 'acme/loader' ) );
		\mkdir( $project . '/src' );
		\file_put_contents( $project . '/src/Plugin.php', "<?php\n" );
		\symlink( $project . '/src', $project . '/vendor-prefixed' );

		try {
			ScopePhpDependencies::scope( $project );
			self::fail( 'The scope run did not fail.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'vendor-prefixed', $exception->getMessage() );
		}
		self::assertFileExists( $project . '/src/Plugin.php' );
	}

	public function test_scope_run_writes_only_to_vendor_prefixed_whatever_the_config_says(): void {
		$project = self::make_project( array( 'acme/loader' ) );
		\mkdir( $project . '/src' );
		\file_put_contents( $project . '/src/Plugin.php', "<?php\n" );
		\file_put_contents( $project . '/scoper.inc.php', \str_replace( 'return ( require', '$config = ( require', self::read( $project . '/scoper.inc.php' ) ) . "\$config['output-dir'] = __DIR__ . '/src';\n\nreturn \$config;\n" );

		ScopePhpDependencies::scope( $project );

		self::assertFileExists( $project . '/src/Plugin.php' );
		self::assertSame( array( 'acme/loader' ), self::package_dirs( $project . '/vendor-prefixed' ) );
	}

	public function test_scope_run_refuses_a_symlinked_package(): void {
		$project = self::make_project( array( 'acme/loader' ) );
		new Filesystem()->remove( $project . '/vendor/acme/loader' );
		\symlink( self::FIXTURE_DIR . '/packages/loader', $project . '/vendor/acme/loader' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( 'symbolic link' );

		ScopePhpDependencies::scope( $project );
	}

	#[Group( 'slow' )]
	public function test_dompdf_renders_through_its_recipes(): void {
		$project = self::make_project( array( 'dompdf/dompdf' ), array(), array( 'dompdf/dompdf', 'dompdf/php-font-lib', 'dompdf/php-svg-lib', 'masterminds/html5', 'sabberworm/php-css-parser' ) );
		ScopePhpDependencies::scope( $project );
		$render = <<<'PHP'
			<?php
			require %s;
			require __DIR__ . '/vendor-prefixed/scoper-autoload.php';
			$dompdf = new \Acme\Scoped\Dompdf\Dompdf( array( 'tempDir' => sys_get_temp_dir() ) );
			$dompdf->loadHtml( '<html><body><h1 style="font-family: DejaVu Sans">Quote #42</h1><p>Žluťoučký kůň</p><svg width="10" height="10"><rect width="10" height="10"/></svg></body></html>' );
			$dompdf->setPaper( 'A4' );
			$dompdf->render();
			echo substr( (string) $dompdf->output(), 0, 5 ), class_exists( 'Dompdf\Dompdf', false ) ? ' and the unprefixed Dompdf loaded' : '';
			PHP;
		\file_put_contents( $project . '/render.php', \sprintf( $render, \var_export( self::VENDOR_DIR . '/composer/ClassLoader.php', true ) ) );

		\exec( \escapeshellarg( \PHP_BINARY ) . ' ' . \escapeshellarg( $project . '/render.php' ) . ' 2>&1', $output, $exit_code );

		self::assertSame( 0, $exit_code, \implode( "\n", $output ) );
		self::assertSame( '%PDF-', \implode( "\n", $output ) );
	}

	protected static function scoped_project(): string {
		if ( \is_null( self::$scoped_project ) ) {
			$project = self::make_project();
			ScopePhpDependencies::scope( $project );
			self::$scoped_project = $project;
		}

		return self::$scoped_project;
	}

	/**
	 * @param list<string>         $names
	 * @param array<string, mixed> $extra
	 * @param list<string>|null    $installed
	 */
	protected static function make_project( array $names = array( 'acme/commerce', 'acme/legacy' ), array $extra = array(), ?array $installed = null, string $vendor = 'vendor' ): string {
		$project          = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/dws wp-configs scoping ' . \uniqid();
		self::$projects[] = $project;
		\mkdir( $project . '/' . $vendor . '/composer', 0777, true );
		new Filesystem()->mirror( self::FIXTURE_DIR . '/stubs', $project . '/stubs' );

		$arguments = \implode( '', \array_map( static fn ( string $name ): string => ', ' . \var_export( $name, true ), $names ) );
		\file_put_contents( $project . '/scoper.inc.php', \str_replace( "( __DIR__, 'acme/commerce', 'acme/legacy' )", "( __DIR__$arguments )", self::read( self::FIXTURE_DIR . '/scoper.inc.php' ) ) );

		$manifest = self::decode( self::FIXTURE_DIR . '/composer.json' );
		self::assertIsArray( $manifest['extra'] );
		$manifest['extra'] = \array_filter( \array_merge( $manifest['extra'], $extra ), static fn ( mixed $value ): bool => ! \is_null( $value ) );
		if ( 'vendor' !== $vendor ) {
			$manifest['config'] = array( 'vendor-dir' => $vendor );
		}
		\file_put_contents( $project . '/composer.json', \json_encode( $manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES ) );

		$locked   = array();
		$lockfile = self::decode( self::VENDOR_DIR . '/composer/installed.json' );
		self::assertIsArray( $lockfile['packages'] );
		foreach ( $lockfile['packages'] as $package ) {
			self::assertIsArray( $package );
			self::assertIsString( $package['name'] );
			$locked[ $package['name'] ] = $package;
		}

		$packages = array();
		foreach ( $installed ?? \array_merge( self::FIXTURE_PACKAGES, self::THIRD_PARTY_PACKAGES ) as $name ) {
			if ( \str_starts_with( $name, 'acme/' ) ) {
				$source  = self::FIXTURE_DIR . '/packages/' . \substr( $name, 5 );
				$package = self::decode( $source . '/composer.json' ) + array(
					'version' => '1.0.0',
					'type'    => 'library',
				);
			} else {
				$source  = self::VENDOR_DIR . '/' . $name;
				$package = $locked[ $name ];
			}
			new Filesystem()->mirror( $source, $project . '/' . $vendor . '/' . $name );
			$packages[] = array( 'install-path' => '../' . $name ) + $package;
		}
		$installed_json = array(
			'packages'          => $packages,
			'dev'               => true,
			'dev-package-names' => array(),
		);
		\file_put_contents( $project . '/' . $vendor . '/composer/installed.json', \json_encode( $installed_json, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES ) );

		return $project;
	}

	/**
	 * @return list<string>
	 */
	protected static function package_dirs( string $output_dir ): array {
		$package_dirs = \glob( $output_dir . '/*/*', \GLOB_ONLYDIR );
		self::assertIsArray( $package_dirs );

		return \array_map( static fn ( string $dir ): string => \substr( $dir, \strlen( $output_dir ) + 1 ), $package_dirs );
	}

	/**
	 * @return list<string>
	 */
	protected static function entries( string $dir ): array {
		$entries = \scandir( $dir );
		self::assertIsArray( $entries );

		return \array_values( \array_diff( $entries, array( '.', '..' ) ) );
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected static function decode( string $file ): array {
		$decoded = \json_decode( self::read( $file ), true, flags: \JSON_THROW_ON_ERROR );
		self::assertIsArray( $decoded );

		return $decoded;
	}

	protected static function read( string $file ): string {
		$contents = \file_get_contents( $file );
		self::assertIsString( $contents );

		return $contents;
	}
}
