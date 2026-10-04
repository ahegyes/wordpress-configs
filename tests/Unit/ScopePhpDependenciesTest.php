<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Composer\ScopePhpDependencies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopePhpDependenciesTest extends TestCase {

	protected const string FIXTURE_DIR = __DIR__ . '/../fixtures/scoping-project';

	protected const string VENDOR_DIR = __DIR__ . '/../../vendor';

	protected const array THIRD_PARTY_PACKAGES = array( 'laravel/serializable-closure', 'php-di/invoker', 'php-di/php-di', 'psr/container', 'psr/log' );

	/**
	 * @var list<string>
	 */
	protected static array $projects = array();

	protected static ?string $scoped_project = null;

	#[\Override]
	public static function tearDownAfterClass(): void {
		foreach ( self::$projects as $project ) {
			self::remove( $project );
		}
		self::$projects       = array();
		self::$scoped_project = null;
	}

	public function test_every_package_nests_under_its_vendor_directory(): void {
		$output_dir = self::scoped_project() . '/vendor-prefixed';

		self::assertSame(
			array( 'ahegyes/wp-framework-commerce', 'ahegyes/wp-framework-loader', 'laravel/serializable-closure', 'php-di/invoker', 'php-di/php-di', 'psr/container', 'psr/log' ),
			self::package_dirs( $output_dir )
		);
		self::assertStringContainsString( 'namespace Acme\Scoped\DeepWebSolutions\Framework\Loader;', self::read( $output_dir . '/ahegyes/wp-framework-loader/load.php' ) );
	}

	/**
	 * @param list<string> $packages
	 */
	#[DataProvider( 'provide_single_vendor_closures' )]
	public function test_a_single_vendor_closure_still_nests_under_its_vendor_directory( array $packages, string|false $text_domain ): void {
		$project = self::make_project( $packages, array(), array( 'text-domain' => $text_domain ) );
		self::copy( self::VENDOR_DIR . '/psr/log', $project . '/vendor-prefixed/psr/log' );

		ScopePhpDependencies::scope( $project );

		self::assertSame( array( 'ahegyes', 'scoper-autoload.php' ), self::entries( $project . '/vendor-prefixed' ) );
		self::assertSame( \array_map( static fn ( string $package ): string => 'ahegyes/' . $package, $packages ), self::package_dirs( $project . '/vendor-prefixed' ) );
	}

	/**
	 * @return array<string, array{list<string>, string|false}>
	 */
	public static function provide_single_vendor_closures(): array {
		return array(
			'one-package' => array( array( 'wp-framework-loader' ), false ),
			'one-vendor'  => array( array( 'wp-framework-commerce', 'wp-framework-loader' ), 'acme-text-domain' ),
		);
	}

	public function test_non_php_files_ship_with_their_package(): void {
		self::assertFileEquals(
			self::FIXTURE_DIR . '/packages/wp-framework-commerce/resources/notice.css',
			self::scoped_project() . '/vendor-prefixed/ahegyes/wp-framework-commerce/resources/notice.css'
		);
	}

	public function test_scoped_autoload_loads_prefixed_classes_and_files(): void {
		$script = \sprintf(
			'namespace Automattic\WooCommerce\Admin\Settings { abstract class SettingsSection {} } namespace { require %s; require %s; echo json_encode( array( interface_exists( %s ), class_exists( %s ), class_exists( %s ), class_exists( %s ), function_exists( %s ) ) ); }',
			\var_export( self::VENDOR_DIR . '/composer/ClassLoader.php', true ),
			\var_export( self::scoped_project() . '/vendor-prefixed/scoper-autoload.php', true ),
			\var_export( 'Acme\Scoped\Psr\Log\LoggerInterface', true ),
			\var_export( 'Acme\Scoped\DeepWebSolutions\Framework\Commerce\SettingsSection', true ),
			\var_export( 'Acme\Scoped\LegacyRenderer', true ),
			\var_export( 'Acme\Scoped\DI\ContainerBuilder', true ),
			\var_export( 'Acme\Scoped\DI\create', true )
		);
		\exec( \escapeshellarg( \PHP_BINARY ) . ' -r ' . \escapeshellarg( $script ) . ' 2>&1', $output, $exit_code );

		self::assertSame( 0, $exit_code, \implode( "\n", $output ) );
		self::assertSame( '[true,true,true,true,true]', \implode( "\n", $output ) );
	}

	public function test_php_di_compares_prefixed_class_names(): void {
		$resolver = self::read( self::scoped_project() . '/vendor-prefixed/php-di/php-di/src/Invoker/FactoryParameterResolver.php' );

		self::assertStringContainsString( "'Acme\\Scoped\\Psr\\Container\\ContainerInterface'", $resolver );
		self::assertStringContainsString( "'Acme\\Scoped\\DI\\Factory\\RequestedEntry'", $resolver );
	}

	public function test_host_symbols_stay_global_and_strings_take_the_text_domain(): void {
		$section = self::read( self::scoped_project() . '/vendor-prefixed/ahegyes/wp-framework-commerce/src/SettingsSection.php' );

		self::assertStringContainsString( 'extends \Automattic\WooCommerce\Admin\Settings\SettingsSection', $section );
		self::assertStringContainsString( '\wc_get_logger()', $section );
		self::assertStringContainsString( '\as_enqueue_async_action(', $section );
		self::assertStringContainsString( "'acme-text-domain'", $section );
		foreach ( self::php_files( self::scoped_project() . '/vendor-prefixed/ahegyes' ) as $file ) {
			self::assertDoesNotMatchRegularExpression( '/Acme\\\\Scoped\\\\(WC_|wc_|WC\(|woocommerce_|as_|Automattic\\\\WooCommerce\\\\)/', self::read( $file ) );
			self::assertStringNotContainsString( "'wp-framework'", self::read( $file ) );
		}
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	#[DataProvider( 'provide_incomplete_text_domains' )]
	public function test_scope_run_fails_without_a_text_domain( array $extra ): void {
		$project = self::make_project( array( 'wp-framework-loader' ), array(), $extra );

		try {
			ScopePhpDependencies::scope( $project );
			self::fail( 'The scope run did not fail.' );
		} catch ( \RuntimeException $exception ) {
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
		);
	}

	public function test_scope_run_fails_when_declared_stubs_are_not_installed(): void {
		$project  = self::make_project( array( 'wp-framework-commerce' ) );
		$manifest = $project . '/vendor/ahegyes/wp-framework-commerce/composer.json';
		\file_put_contents( $manifest, \str_replace( '"php-stubs/woocommerce-stubs",', '"acme/absent-stubs",', self::read( $manifest ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( 'acme/absent-stubs' );

		ScopePhpDependencies::scope( $project );
	}

	#[DataProvider( 'provide_residues' )]
	public function test_scope_run_fails_on_a_prefixed_host_symbol_or_an_unrewritten_text_domain( string $call, string|false $text_domain, string $residue ): void {
		$project = self::make_project( array( 'wp-framework-commerce' ), array(), array( 'text-domain' => $text_domain ) );
		$section = $project . '/vendor/ahegyes/wp-framework-commerce/src/SettingsSection.php';
		\file_put_contents( $section, \str_replace( 'return \__(', $call . ' return \__(', self::read( $section ) ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( $residue );

		ScopePhpDependencies::scope( $project );
	}

	/**
	 * @return array<string, array{string, string|false, string}>
	 */
	public static function provide_residues(): array {
		return array(
			'function-newer-than-the-stubs' => array( '\wc_get_future_feature();', 'acme-text-domain', 'Acme\Scoped\wc_get_future_feature' ),
			'text-domain-opted-out'         => array( '', false, "'wp-framework'" ),
			'class-newer-than-the-stubs'    => array( "\\class_exists( 'WC_Future_Feature' );", 'acme-text-domain', 'WC_Future_Feature' ),
		);
	}

	public function test_scope_run_refuses_an_output_directory_that_resolves_elsewhere(): void {
		$project = self::make_project( array( 'wp-framework-loader' ) );
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

	public function test_scope_run_refuses_a_symlinked_package(): void {
		$project = self::make_project( array() );
		\mkdir( $project . '/vendor/ahegyes' );
		\symlink( self::FIXTURE_DIR . '/packages/wp-framework-loader', $project . '/vendor/ahegyes/wp-framework-loader' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( 'symbolic link' );

		ScopePhpDependencies::scope( $project );
	}

	public function test_scope_run_fails_when_no_package_is_installed(): void {
		$project = self::make_project( array() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( 'No package to scope' );

		ScopePhpDependencies::scope( $project );
	}

	protected static function scoped_project(): string {
		if ( \is_null( self::$scoped_project ) ) {
			$project = self::make_project( array( 'wp-framework-commerce', 'wp-framework-loader' ), self::THIRD_PARTY_PACKAGES );
			ScopePhpDependencies::scope( $project );
			self::$scoped_project = $project;
		}

		return self::$scoped_project;
	}

	/**
	 * @param list<string>         $packages
	 * @param list<string>         $third_party_packages
	 * @param array<string, mixed> $extra
	 */
	protected static function make_project( array $packages, array $third_party_packages = array(), array $extra = array() ): string {
		$project          = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/dws wp-configs scoping ' . \uniqid();
		self::$projects[] = $project;
		\mkdir( $project . '/vendor', 0777, true );
		\copy( self::FIXTURE_DIR . '/scoper.inc.php', $project . '/scoper.inc.php' );

		$manifest = \json_decode( self::read( self::FIXTURE_DIR . '/composer.json' ), true, flags: \JSON_THROW_ON_ERROR );
		self::assertIsArray( $manifest );
		self::assertIsArray( $manifest['extra'] );
		$manifest['extra'] = \array_filter( \array_merge( $manifest['extra'], $extra ), static fn ( mixed $value ): bool => ! \is_null( $value ) );
		\file_put_contents( $project . '/composer.json', \json_encode( $manifest, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES ) );

		foreach ( $packages as $package ) {
			self::copy( self::FIXTURE_DIR . '/packages/' . $package, $project . '/vendor/ahegyes/' . $package );
		}
		foreach ( $third_party_packages as $package ) {
			self::copy( self::VENDOR_DIR . '/' . $package, $project . '/vendor/' . $package );
		}

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
	 * @return list<string>
	 */
	protected static function php_files( string $dir ): array {
		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			self::assertInstanceOf( \SplFileInfo::class, $file );
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		self::assertNotSame( array(), $files );

		return $files;
	}

	protected static function read( string $file ): string {
		$contents = \file_get_contents( $file );
		self::assertIsString( $contents );

		return $contents;
	}

	protected static function copy( string $from, string $to ): void {
		\mkdir( $to, 0777, true );
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			self::assertInstanceOf( \SplFileInfo::class, $item );
			$target = $to . \substr( $item->getPathname(), \strlen( $from ) );
			$item->isDir() ? \mkdir( $target ) : \copy( $item->getPathname(), $target );
		}
	}

	protected static function remove( string $path ): void {
		if ( \is_link( $path ) || \is_file( $path ) ) {
			\unlink( $path );
			return;
		}
		if ( ! \is_dir( $path ) ) {
			return;
		}

		$entries = \scandir( $path );
		self::assertIsArray( $entries );
		foreach ( \array_diff( $entries, array( '.', '..' ) ) as $entry ) {
			self::remove( $path . '/' . $entry );
		}
		\rmdir( $path );
	}
}
