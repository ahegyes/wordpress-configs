<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the PHPStan auto-discovery closure in `quality-assurance/phpstan-auto-discovery.php`.
 */
final class PhpstanAutoDiscoveryTest extends TestCase {

	private const CONFIG_FILE = __DIR__ . '/../../php/quality-assurance/phpstan-auto-discovery.php';

	private string $project_dir;

	/** @var \Closure(string): array<string, array<string, mixed>> */
	private \Closure $discover;

	protected function setUp(): void {
		$this->project_dir = \sys_get_temp_dir() . '/dws-wp-configs-phpstan-' . \uniqid();
		\mkdir( $this->project_dir );
		$this->discover = require self::CONFIG_FILE;
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function detects_plugin_via_plugin_name_header(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name: My Plugin\n */\n"
		);
		\mkdir( $this->project_dir . '/src' );

		$config = ( $this->discover )( $this->project_dir );

		self::assertSame( $this->project_dir . '/my-plugin.php', $config['parameters']['WPCompat']['pluginFile'] );
		self::assertContains( $this->project_dir . '/src', $config['parameters']['paths'] );
		self::assertContains( $this->project_dir . '/my-plugin.php', $config['parameters']['paths'] );
	}

	#[Test]
	public function detects_plugin_when_filename_does_not_match_directory_basename(): void {
		// Real-world case the old basename-heuristic failed: project dir is "wordpress-plugins" but
		// the plugin file is named after the plugin slug, not the dir.
		\file_put_contents(
			$this->project_dir . '/internal-comments.php',
			"<?php\n/**\n * Plugin Name: Internal Comments\n */\n"
		);

		$config = ( $this->discover )( $this->project_dir );

		self::assertSame(
			$this->project_dir . '/internal-comments.php',
			$config['parameters']['WPCompat']['pluginFile']
		);
		self::assertContains( $this->project_dir . '/internal-comments.php', $config['parameters']['paths'] );
	}

	#[Test]
	public function ignores_php_files_without_plugin_name_header(): void {
		\file_put_contents( $this->project_dir . '/random.php', "<?php\necho 'hello';\n" );

		$config = ( $this->discover )( $this->project_dir );

		self::assertArrayNotHasKey( 'pluginFile', $config['parameters']['WPCompat'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function detects_library_layout_via_src_directory(): void {
		\mkdir( $this->project_dir . '/src' );
		\mkdir( $this->project_dir . '/tests' );

		$config = ( $this->discover )( $this->project_dir );

		self::assertContains( $this->project_dir . '/src', $config['parameters']['paths'] );
		self::assertContains( $this->project_dir . '/tests', $config['parameters']['paths'] );
		self::assertArrayNotHasKey( 'pluginFile', $config['parameters']['WPCompat'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function detects_theme_layout_via_style_css(): void {
		\file_put_contents( $this->project_dir . '/style.css', "/* Theme Name: T */\n" );
		\mkdir( $this->project_dir . '/inc' );
		\file_put_contents( $this->project_dir . '/index.php', "<?php\n" );

		$config = ( $this->discover )( $this->project_dir );

		self::assertContains( $this->project_dir . '/inc', $config['parameters']['paths'] );
		self::assertContains( $this->project_dir . '/index.php', $config['parameters']['paths'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function reads_custom_vendor_dir_from_composer_json(): void {
		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode( array( 'config' => array( 'vendor-dir' => 'custom-vendor' ) ), JSON_THROW_ON_ERROR )
		);
		\mkdir( $this->project_dir . '/custom-vendor/php-stubs/wordpress-stubs', 0755, true );
		\file_put_contents(
			$this->project_dir . '/custom-vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
			"<?php\n"
		);

		$config = ( $this->discover )( $this->project_dir );

		self::assertContains(
			$this->project_dir . '/custom-vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
			$config['parameters']['bootstrapFiles']
		);
	}

	#[Test]
	public function returns_empty_paths_for_unknown_layout(): void {
		$config = ( $this->discover )( $this->project_dir );

		self::assertArrayNotHasKey( 'paths', $config['parameters'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function matches_plugin_name_header_in_doc_comment_block(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name:       My Plugin\n * Description: foo\n */\n"
		);

		$config = ( $this->discover )( $this->project_dir );

		self::assertSame( $this->project_dir . '/my-plugin.php', $config['parameters']['WPCompat']['pluginFile'] );
	}

	#[Test]
	public function matches_plugin_name_header_with_hash_style_comment(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n# Plugin Name: Hash Style\n"
		);

		$config = ( $this->discover )( $this->project_dir );

		self::assertSame( $this->project_dir . '/my-plugin.php', $config['parameters']['WPCompat']['pluginFile'] );
	}

	#[Test]
	public function bootstrap_files_omitted_when_stubs_not_installed(): void {
		\mkdir( $this->project_dir . '/src' );

		$config = ( $this->discover )( $this->project_dir );

		self::assertArrayNotHasKey( 'bootstrapFiles', $config['parameters'] );
	}

	private function rrmdir( string $dir ): void {
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
