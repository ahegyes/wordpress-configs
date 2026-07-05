<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Composer\Internal\GenerateScopedAutoload;
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
		$this->installScopedPackage(
			'acme/minimal',
			array( 'autoload' => array( 'psr-4' => array( 'MyPlugin\\Scoped\\Acme\\Minimal\\' => 'src/' ) ) )
		);

		$output = GenerateScopedAutoload::generate( $this->dependencies_dir );

		self::assertSame( $this->dependencies_dir . '/scoper-autoload.php', $output );
		self::assertFileExists( $output );
	}

	#[Test]
	public function generated_file_is_valid_php(): void {
		$this->installScopedPackage(
			'acme/minimal',
			array( 'autoload' => array( 'psr-4' => array( 'MyPlugin\\Scoped\\Acme\\Minimal\\' => 'src/' ) ) )
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

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

		GenerateScopedAutoload::generate( $this->dependencies_dir );

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

		GenerateScopedAutoload::generate( $this->dependencies_dir );

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

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( 'MyPlugin\\\\Scoped\\\\DeepWebSolutions\\\\Framework\\\\Core\\\\', $generated );
		self::assertStringContainsString( 'MyPlugin\\\\Scoped\\\\DI\\\\', $generated );
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

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		$pos_a     = \strpos( $generated, 'P\\\\A\\\\' );
		$pos_z     = \strpos( $generated, 'P\\\\Z\\\\' );
		self::assertNotFalse( $pos_a );
		self::assertNotFalse( $pos_z );
		self::assertLessThan( $pos_z, $pos_a );
	}

	#[Test]
	public function throws_when_no_scoped_packages_exist(): void {
		// An empty scan means every scoped class would 404 at runtime while composer exits 0;
		// the generator refuses to write an empty autoload instead of failing silently.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/No scoped packages found/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir );
	}

	#[Test]
	public function finds_packages_in_flattened_single_vendor_layout(): void {
		// php-scoper mirrors input paths relative to their common ancestor: finders covering a
		// single vendor namespace (a framework-only consumer) collapse the `vendor/<vendor>/`
		// segment and write packages directly at dependencies/<pkg>/.
		$this->installScopedPackage(
			'wp-framework-core',
			array(
				'autoload' => array(
					'psr-4' => array( 'MyPlugin\\Scoped\\DeepWebSolutions\\Framework\\Core\\' => 'src/' ),
				),
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString(
			"\$loader->addPsr4( 'MyPlugin\\\\Scoped\\\\DeepWebSolutions\\\\Framework\\\\Core\\\\', __DIR__ . '/wp-framework-core/src' );",
			$generated
		);
	}

	#[Test]
	public function finds_packages_across_flattened_and_nested_layouts(): void {
		// Residue from a previous run with a different finder shape can leave both layouts on
		// disk at once; the scan reads packages from either depth.
		$this->installScopedPackage(
			'wp-framework-shared',
			array( 'autoload' => array( 'psr-4' => array( 'P\\Shared\\' => 'src/' ) ) )
		);
		$this->installScopedPackage(
			'php-di/php-di',
			array( 'autoload' => array( 'psr-4' => array( 'P\\DI\\' => 'src/' ) ) )
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( "__DIR__ . '/wp-framework-shared/src'", $generated );
		self::assertStringContainsString( "__DIR__ . '/php-di/php-di/src'", $generated );
	}

	#[Test]
	public function finds_a_single_package_at_the_dependencies_root(): void {
		\file_put_contents(
			$this->dependencies_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'single/root',
					'autoload' => array(
						'psr-4' => array( 'P\\Root\\' => 'src/' ),
						'files' => array( 'bootstrap.php' ),
					),
				),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( "\$loader->addPsr4( 'P\\\\Root\\\\', __DIR__ . '/src' );", $generated );
		self::assertStringContainsString( "require_once __DIR__ . '/bootstrap.php';", $generated );
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

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( "__DIR__ . '/multi/dirs/src'", $generated );
		self::assertStringContainsString( "__DIR__ . '/multi/dirs/lib'", $generated );
	}

	#[Test]
	public function emits_classmap_entries_for_scoped_package_with_classes(): void {
		// php-scoper rewrites the scoped class to the prefixed namespace; the scanner reads
		// that prefixed declaration and the generator emits the FQCN verbatim.
		$this->installScopedPackage(
			'legacy/classmapped',
			array(
				'autoload' => array(
					'classmap' => array( 'src/' ),
				),
			),
			array(
				'src/Widget.php' => "<?php\nnamespace MyPlugin\\Scoped\\Legacy;\nclass Widget {}\n",
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringContainsString( '$loader->addClassMap( array(', $generated );
		self::assertStringContainsString(
			"'MyPlugin\\\\Scoped\\\\Legacy\\\\Widget' => __DIR__ . '/legacy/classmapped/src/Widget.php',",
			$generated
		);

		// The emitted addClassMap block must be syntactically valid PHP.
		$lint = \shell_exec( 'php -l ' . \escapeshellarg( $this->dependencies_dir . '/scoper-autoload.php' ) . ' 2>&1' );
		self::assertStringContainsString( 'No syntax errors', (string) $lint );
	}

	#[Test]
	public function treats_class_free_classmap_as_noop_and_still_emits_files(): void {
		// The wp-framework-bootstrap shape: a classmap over a deliberately class-free src/
		// (its functions load via the files aggregator). The empty scan must add nothing.
		$this->installScopedPackage(
			'ahegyes/wp-framework-bootstrap',
			array(
				'autoload' => array(
					'classmap' => array( 'src/' ),
					'files'    => array( 'functions.php' ),
				),
			),
			array(
				'src/helpers.php' => "<?php\nnamespace MyPlugin\\Scoped\\Boot;\nfunction check() { return true; }\n",
				'functions.php'   => "<?php\nrequire_once __DIR__ . '/src/helpers.php';\n",
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringNotContainsString( 'addClassMap', $generated );
		self::assertStringContainsString(
			"require_once __DIR__ . '/ahegyes/wp-framework-bootstrap/functions.php';",
			$generated
		);
	}

	#[Test]
	public function classmap_entries_are_sorted_for_deterministic_diffs(): void {
		$this->installScopedPackage(
			'legacy/classmapped',
			array(
				'autoload' => array(
					'classmap' => array( 'src/' ),
				),
			),
			array(
				'src/Zebra.php' => "<?php\nnamespace P\\Legacy;\nclass Zebra {}\n",
				'src/Alpha.php' => "<?php\nnamespace P\\Legacy;\nclass Alpha {}\n",
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		$pos_alpha = \strpos( $generated, 'P\\\\Legacy\\\\Alpha' );
		$pos_zebra = \strpos( $generated, 'P\\\\Legacy\\\\Zebra' );
		self::assertNotFalse( $pos_alpha );
		self::assertNotFalse( $pos_zebra );
		self::assertLessThan( $pos_zebra, $pos_alpha );
	}

	#[Test]
	public function throws_when_a_classmap_class_resolves_to_two_files(): void {
		// A duplicate class would resolve by filesystem order, so the generator fails loud
		// to protect byte-identical regeneration rather than silently pick one file.
		$this->installScopedPackage(
			'legacy/classmapped',
			array(
				'autoload' => array(
					'classmap' => array( 'src/' ),
				),
			),
			array(
				'src/First.php'  => "<?php\nnamespace P\\Legacy;\nclass Duplicate {}\n",
				'src/Second.php' => "<?php\nnamespace P\\Legacy;\nclass Duplicate {}\n",
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/[Aa]mbiguous/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir );
	}

	#[Test]
	public function throws_when_a_package_declares_exclude_from_classmap(): void {
		// The generator cannot honour autoload.exclude-from-classmap, so it fails loud rather
		// than register an excluded class anyway and silently misbuild the autoload.
		$this->installScopedPackage(
			'legacy/classmapped',
			array(
				'autoload' => array(
					'classmap'              => array( 'src/' ),
					'exclude-from-classmap' => array( 'src/Generated/' ),
				),
			),
			array(
				'src/Widget.php' => "<?php\nnamespace P\\Legacy;\nclass Widget {}\n",
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/exclude-from-classmap/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir );
	}

	#[Test]
	public function ignores_packages_with_no_autoload_section(): void {
		$this->installScopedPackage( 'silent/pkg', array() );

		// Should not throw and should not emit anything for this pkg.
		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$generated = (string) \file_get_contents( $this->dependencies_dir . '/scoper-autoload.php' );
		self::assertStringNotContainsString( 'silent/pkg', $generated );
	}

	#[Test]
	public function throws_when_a_package_declares_psr0(): void {
		// psr-0's directory mapping differs from psr-4; the generator fails loud rather than
		// silently drop the package's classes from the autoload.
		$this->installScopedPackage(
			'legacy/psr0',
			array(
				'autoload' => array(
					'psr-0' => array( 'Legacy_' => 'src/' ),
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/psr-0/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir );
	}

	#[Test]
	public function throws_when_autoload_files_entry_escapes_the_package(): void {
		$this->installScopedPackage(
			'bad/files',
			array(
				'autoload' => array(
					'files' => array( '../../../x.php' ),
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/autoload\.files.*parent-directory traversal/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir );
	}

	#[Test]
	public function throws_when_classmap_entry_escapes_the_package(): void {
		$this->installScopedPackage(
			'bad/classmap',
			array(
				'autoload' => array(
					'classmap' => array( '../../' ),
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/autoload\.classmap.*parent-directory traversal/' );

		GenerateScopedAutoload::generate( $this->dependencies_dir );
	}

	#[Test]
	public function generated_file_autoloads_scoped_classes_and_runs_files(): void {
		// Proves the generated bootstrap works at runtime — its dedicated loader resolves both a
		// scoped PSR-4 class and a scoped classmap class, and the autoload.files entry executes —
		// not merely that the emitted source contains the right strings.
		$this->installScopedPackage(
			'acme/lib',
			array(
				'autoload' => array(
					'psr-4'    => array( 'MyPlugin\\Scoped\\Acme\\Lib\\' => 'src/' ),
					'classmap' => array( 'legacy/' ),
					'files'    => array( 'bootstrap.php' ),
				),
			),
			array(
				'src/Thing.php'     => "<?php\nnamespace MyPlugin\\Scoped\\Acme\\Lib;\nclass Thing {}\n",
				'legacy/Widget.php' => "<?php\nnamespace MyPlugin\\Scoped\\Acme\\Legacy;\nclass Widget {}\n",
				'bootstrap.php'     => "<?php\n\\file_put_contents( __DIR__ . '/ran.marker', '1' );\n",
			)
		);

		GenerateScopedAutoload::generate( $this->dependencies_dir );

		$before = \spl_autoload_functions();
		try {
			// The require (which registers the dedicated loader) is inside the try so a failure
			// mid-file still hits the finally cleanup.
			require $this->dependencies_dir . '/scoper-autoload.php';

			self::assertTrue(
				\class_exists( 'MyPlugin\\Scoped\\Acme\\Lib\\Thing', true ),
				'Generated autoload did not resolve the scoped PSR-4 class.'
			);
			self::assertTrue(
				\class_exists( 'MyPlugin\\Scoped\\Acme\\Legacy\\Widget', true ),
				'Generated autoload did not resolve the scoped classmap class.'
			);
			self::assertFileExists(
				$this->dependencies_dir . '/acme/lib/ran.marker',
				'Generated autoload did not run the autoload.files entry.'
			);
		} finally {
			// The generated file registers a loader on the global spl stack; remove what this added.
			foreach ( \spl_autoload_functions() as $fn ) {
				if ( ! \in_array( $fn, $before, true ) ) {
					\spl_autoload_unregister( $fn );
				}
			}
		}
	}

	/**
	 * @param array<string, mixed>  $composer_payload
	 * @param array<string, string> $source_files Map of package-relative path => file contents (real files the classmap scanner reads).
	 */
	private function installScopedPackage( string $package_name, array $composer_payload, array $source_files = array() ): void {
		$package_dir = $this->dependencies_dir . '/' . $package_name;
		\mkdir( $package_dir, 0755, true );
		$composer_payload['name'] = $composer_payload['name'] ?? $package_name;
		\file_put_contents(
			$package_dir . '/composer.json',
			\json_encode( $composer_payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
		foreach ( $source_files as $relative_path => $contents ) {
			$file_path = $package_dir . '/' . $relative_path;
			$file_dir  = \dirname( $file_path );
			if ( ! \is_dir( $file_dir ) ) {
				\mkdir( $file_dir, 0755, true );
			}
			\file_put_contents( $file_path, $contents );
		}
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
