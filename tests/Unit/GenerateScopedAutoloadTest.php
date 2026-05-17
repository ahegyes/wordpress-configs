<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Composer\GenerateScopedAutoload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( GenerateScopedAutoload::class )]
final class GenerateScopedAutoloadTest extends TestCase {

	private string $dependencies_dir;

	protected function setUp(): void {
		$this->dependencies_dir = \sys_get_temp_dir() . '/dws-wp-configs-genscope-' . \uniqid();
		\mkdir( $this->dependencies_dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->dependencies_dir );
	}

	#[Test]
	public function writes_file_at_dependencies_root(): void {
		$output = GenerateScopedAutoload::generate( $this->dependencies_dir, 'MyPlugin\\Scoped' );

		self::assertSame( $this->dependencies_dir . '/scoper-autoload.php', $output );
		self::assertFileExists( $output );
	}

	#[Test]
	public function generated_file_is_valid_php(): void {
		GenerateScopedAutoload::generate( $this->dependencies_dir, 'MyPlugin\\Scoped' );

		$lint = \shell_exec( 'php -l ' . \escapeshellarg( $this->dependencies_dir . '/scoper-autoload.php' ) . ' 2>&1' );
		self::assertStringContainsString( 'No syntax errors', (string) $lint );
	}

	#[Test]
	public function emits_psr4_from_scoped_composer_verbatim(): void {
		// php-scoper rewrites the scoped package's own composer.json to use the prefixed
		// namespace. The generator passes that through; it does not re-prefix.
		$this->installScopedPackage(
			'ahegyes/wp-framework-core',
			array(
				'autoload' => array(
					'psr-4' => array( 'MyPlugin\\Scoped\\DeepWebSolutions\\Framework\\Core\\' => 'src/' ),
				),
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir, 'MyPlugin\\Scoped' );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString(
			"\$loader->addPsr4( 'MyPlugin\\\\Scoped\\\\DeepWebSolutions\\\\Framework\\\\Core\\\\', __DIR__ . '/ahegyes/wp-framework-core/src' );",
			$generated
		);
	}

	#[Test]
	public function emits_require_once_for_autoload_files(): void {
		$this->installScopedPackage(
			'ahegyes/wp-framework-bootstrap',
			array(
				'autoload' => array(
					'files' => array( 'check-requirements.php' ),
				),
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir, 'MyPlugin\\Scoped' );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString(
			"require_once __DIR__ . '/ahegyes/wp-framework-bootstrap/check-requirements.php';",
			$generated
		);
	}

	#[Test]
	public function handles_multiple_packages_with_mixed_autoload_shapes(): void {
		$this->installScopedPackage(
			'ahegyes/wp-framework-core',
			array(
				'autoload' => array(
					'psr-4' => array( 'MyPlugin\\Scoped\\DeepWebSolutions\\Framework\\Core\\' => 'src/' ),
				),
			)
		);
		$this->installScopedPackage(
			'php-di/php-di',
			array(
				'autoload' => array(
					'psr-4' => array( 'MyPlugin\\Scoped\\DI\\' => 'src/' ),
					'files' => array( 'src/functions.php' ),
				),
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir, 'MyPlugin\\Scoped' );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( "MyPlugin\\\\Scoped\\\\DeepWebSolutions\\\\Framework\\\\Core\\\\", $generated );
		self::assertStringContainsString( "MyPlugin\\\\Scoped\\\\DI\\\\", $generated );
		self::assertStringContainsString( "require_once __DIR__ . '/php-di/php-di/src/functions.php';", $generated );
	}

	#[Test]
	public function psr4_entries_are_sorted_for_deterministic_diffs(): void {
		$this->installScopedPackage(
			'z/package',
			array( 'autoload' => array( 'psr-4' => array( 'P\\Z\\' => 'src/' ) ) )
		);
		$this->installScopedPackage(
			'a/package',
			array( 'autoload' => array( 'psr-4' => array( 'P\\A\\' => 'src/' ) ) )
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir, 'P' );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		$pos_a = \strpos( $generated, "P\\\\A\\\\" );
		$pos_z = \strpos( $generated, "P\\\\Z\\\\" );
		self::assertNotFalse( $pos_a );
		self::assertNotFalse( $pos_z );
		self::assertLessThan( $pos_z, $pos_a );
	}

	#[Test]
	public function emits_empty_body_when_no_scoped_packages_exist(): void {
		GenerateScopedAutoload::generate( $this->dependencies_dir, 'X' );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( 'getRegisteredLoaders', $generated );
		self::assertStringNotContainsString( 'addPsr4', $generated );
		self::assertStringNotContainsString( 'require_once __DIR__', $generated );
	}

	#[Test]
	public function multiple_psr4_base_dirs_for_one_namespace_emit_one_entry_each(): void {
		$this->installScopedPackage(
			'multi/dirs',
			array(
				'autoload' => array(
					'psr-4' => array( 'P\\Multi\\' => array( 'src/', 'lib/' ) ),
				),
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir, 'P' );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( "__DIR__ . '/multi/dirs/src'", $generated );
		self::assertStringContainsString( "__DIR__ . '/multi/dirs/lib'", $generated );
	}

	#[Test]
	public function throws_clearly_when_a_scoped_package_uses_classmap(): void {
		$this->installScopedPackage(
			'legacy/classmapped',
			array(
				'autoload' => array(
					'classmap' => array( 'src/' ),
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/classmap/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir, 'P' );
	}

	#[Test]
	public function throws_when_a_psr4_key_does_not_start_with_the_declared_prefix(): void {
		// Pipeline-drift sanity check: scoper ran with prefix "Wrong" but we passed "Right".
		$this->installScopedPackage(
			'mismatch/pkg',
			array(
				'autoload' => array(
					'psr-4' => array( 'Wrong\\Prefix\\' => 'src/' ),
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/does not start with the declared prefix/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir, 'Right' );
	}

	#[Test]
	public function ignores_packages_with_no_autoload_section(): void {
		$this->installScopedPackage( 'silent/pkg', array() );

		// Should not throw and should not emit anything for this pkg.
		GenerateScopedAutoload::generate( $this->dependencies_dir, 'P' );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringNotContainsString( 'silent/pkg', $generated );
	}

	/**
	 * @param array<string, mixed> $composer_payload
	 */
	private function installScopedPackage( string $package_name, array $composer_payload ): void {
		$package_dir = $this->dependencies_dir . '/' . $package_name;
		\mkdir( $package_dir, 0755, true );
		$composer_payload['name'] = $composer_payload['name'] ?? $package_name;
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode( $composer_payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
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
