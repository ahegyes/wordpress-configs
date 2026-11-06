<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Composer\ScopedPackages;
use DeepWebSolutions\Config\Tests\Support\RecordsInstalledPackagesTrait;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass( ScopedPackages::class )]
final class ScopedPackagesTest extends TestCase {
	use RecordsInstalledPackagesTrait;

	protected string $vendor_dir = '';

	#[After]
	protected function remove_vendor_dir(): void {
		new Filesystem()->remove( \dirname( $this->vendor_dir ) );
	}

	/**
	 * @param list<array<string, mixed>> $packages
	 * @param array<string, mixed>       $root_links
	 * @param list<string>               $scoped
	 */
	#[DataProvider( 'provide_links_composer_resolved' )]
	public function test_dependencies_follow_the_links_composer_resolved( array $packages, array $root_links, array $scoped ): void {
		$this->install( $packages, $root_links );

		self::assertSame( $scoped, \array_keys( ScopedPackages::resolve( $this->vendor_dir, array( 'acme/app' ) )['scoped'] ) );
	}

	/**
	 * @return array<string, array{list<array<string, mixed>>, array<string, mixed>, list<string>}>
	 */
	public static function provide_links_composer_resolved(): array {
		return array(
			'replaced-dependency'          => array(
				array(
					self::package( 'acme/app', array( 'require' => array( 'acme/upstream' => '*' ) ) ),
					self::package( 'acme/fork', array( 'replace' => array( 'acme/upstream' => '*' ) ) ),
				),
				array(),
				array( 'acme/app', 'acme/fork' ),
			),
			'provided-virtual-package'     => array(
				array(
					self::package( 'acme/app', array( 'require' => array( 'acme/logger-implementation' => '*' ) ) ),
					self::package( 'acme/adapter', array( 'provide' => array( 'acme/logger-implementation' => '1.0' ) ) ),
				),
				array(),
				array( 'acme/app' ),
			),
			'composer-plugin-dependency'   => array(
				array(
					self::package( 'acme/app', array( 'require' => array( 'acme/installer' => '*' ) ) ),
					self::package( 'acme/installer', array( 'type' => 'composer-plugin' ) ),
				),
				array(),
				array( 'acme/app' ),
			),
			'metapackage-dependency'       => array(
				array(
					self::package( 'acme/app', array( 'require' => array( 'acme/bundle' => '*' ) ) ),
					self::package(
						'acme/bundle',
						array(
							'type'    => 'metapackage',
							'require' => array( 'acme/loader' => '*' ),
						)
					),
					self::package( 'acme/loader' ),
				),
				array(),
				array( 'acme/app', 'acme/loader' ),
			),
			'dependency-the-root-replaces' => array(
				array( self::package( 'acme/app', array( 'require' => array( 'acme/polyfill' => '*' ) ) ) ),
				array( 'replace' => array( 'acme/polyfill' => '*' ) ),
				array( 'acme/app' ),
			),
			'virtual-the-root-provides'    => array(
				array( self::package( 'acme/app', array( 'require' => array( 'acme/cache-implementation' => '*' ) ) ) ),
				array( 'provide' => array( 'acme/cache-implementation' => '1.0' ) ),
				array( 'acme/app' ),
			),
		);
	}

	public function test_a_dependency_nothing_installs_replaces_or_provides_fails(): void {
		$this->install( array( self::package( 'acme/app', array( 'require' => array( 'acme/absent' => '*' ) ) ) ), array() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageIsOrContains( "'acme/app' requires 'acme/absent', which is not installed" );

		ScopedPackages::resolve( $this->vendor_dir, array( 'acme/app' ) );
	}

	/**
	 * @param list<array<string, mixed>> $packages
	 * @param array<string, mixed>       $root_links
	 */
	protected function install( array $packages, array $root_links ): void {
		$this->vendor_dir = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/wp-configs-packages ' . \uniqid() . '/vendor';
		\mkdir( $this->vendor_dir . '/composer', 0777, true );
		foreach ( $packages as $package ) {
			self::assertIsString( $package['name'] );
			\mkdir( $this->vendor_dir . '/' . $package['name'], 0777, true );
		}
		self::record_installed_packages( $this->vendor_dir, array( 'name' => 'acme/project' ) + $root_links, $packages );
	}

	/**
	 * @param array<string, mixed> $links
	 *
	 * @return array<string, mixed>
	 */
	protected static function package( string $name, array $links = array() ): array {
		return $links + array(
			'name'         => $name,
			'version'      => '1.0.0',
			'type'         => 'library',
			'install-path' => '../' . $name,
		);
	}
}
