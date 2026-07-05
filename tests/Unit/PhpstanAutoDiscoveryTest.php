<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the auto-discovery logic in `quality-assurance/phpstan.dist.neon.php`.
 * The file is procedural — uses `getcwd()` as its project root — so tests `chdir` to a
 * fixture directory, `require` the file, and assert on the returned config array.
 */
final class PhpstanAutoDiscoveryTest extends TestCase {

	private const CONFIG_FILE = __DIR__ . '/../../php/quality-assurance/phpstan.dist.neon.php';

	private string $project_dir;
	private string $original_cwd;

	protected function setUp(): void {
		$tmp                = \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir();
		$this->project_dir  = $tmp . '/dws-wp-configs-phpstan-' . \uniqid();
		$this->original_cwd = \getcwd() ?: '/';
		\mkdir( $this->project_dir );
		\chdir( $this->project_dir );
	}

	protected function tearDown(): void {
		\chdir( $this->original_cwd );
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function detects_plugin_via_plugin_name_header(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name: My Plugin\n */\n"
		);
		\mkdir( $this->project_dir . '/src' );

		$config = require self::CONFIG_FILE;

		self::assertContains( $this->project_dir . '/src', $config['parameters']['paths'] );
		self::assertContains( $this->project_dir . '/my-plugin.php', $config['parameters']['paths'] );
		self::assertArrayNotHasKey( 'pluginFile', $config['parameters']['WPCompat'] );
	}

	#[Test]
	public function detects_plugin_when_filename_does_not_match_directory_basename(): void {
		// Real-world case the old basename-heuristic failed: project dir is "wordpress-plugins"
		// but the plugin file is named after the plugin slug, not the dir.
		\file_put_contents(
			$this->project_dir . '/internal-comments.php',
			"<?php\n/**\n * Plugin Name: Internal Comments\n */\n"
		);

		$config = require self::CONFIG_FILE;

		self::assertContains( $this->project_dir . '/internal-comments.php', $config['parameters']['paths'] );
		self::assertArrayNotHasKey( 'pluginFile', $config['parameters']['WPCompat'] );
	}

	#[Test]
	public function ignores_php_files_without_plugin_name_header(): void {
		\file_put_contents( $this->project_dir . '/random.php', "<?php\necho 'hello';\n" );

		$config = require self::CONFIG_FILE;

		self::assertArrayNotHasKey( 'pluginFile', $config['parameters']['WPCompat'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function detects_library_layout_via_src_directory(): void {
		\mkdir( $this->project_dir . '/src' );
		\mkdir( $this->project_dir . '/tests' );

		$config = require self::CONFIG_FILE;

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

		$config = require self::CONFIG_FILE;

		self::assertContains( $this->project_dir . '/inc', $config['parameters']['paths'] );
		self::assertContains( $this->project_dir . '/index.php', $config['parameters']['paths'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function honours_theme_requires_at_least_header_above_framework_floor(): void {
		\file_put_contents( $this->project_dir . '/style.css', "/*\nTheme Name: T\nRequires at least: 7.2\n*/\n" );

		$config = require self::CONFIG_FILE;

		self::assertSame( '7.2', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function floors_theme_requires_at_least_header_below_framework_floor(): void {
		\file_put_contents( $this->project_dir . '/style.css', "/*\nTheme Name: T\nRequires at least: 6.0\n*/\n" );

		$config = require self::CONFIG_FILE;

		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function floors_theme_requires_at_least_header_with_non_numeric_value(): void {
		\file_put_contents( $this->project_dir . '/style.css', "/*\nTheme Name: T\nRequires at least: 8.x\n*/\n" );

		$config = require self::CONFIG_FILE;

		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function honours_theme_requires_at_least_patch_version(): void {
		\file_put_contents( $this->project_dir . '/style.css', "/*\nTheme Name: T\nRequires at least: 7.2.1\n*/\n" );

		$config = require self::CONFIG_FILE;

		self::assertSame( '7.2.1', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function floors_header_less_plugin_to_framework_minimum_without_plugin_file(): void {
		// A plugin whose main file carries `Plugin Name:` but no `Requires at least:` is a valid,
		// supported layout. Handing WPCompat a `pluginFile` makes its SinceVersionRule read the
		// header itself and hard-throw when it is absent, aborting the whole run; the resolved
		// floor is passed as `requiresAtLeast`, which WPCompat honours without reading any header,
		// so analysis proceeds.
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name: Header Less\n */\n"
		);

		$config = require self::CONFIG_FILE;

		self::assertArrayNotHasKey( 'pluginFile', $config['parameters']['WPCompat'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function honours_plugin_requires_at_least_header_above_framework_floor(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name: Modern\n * Requires at least: 7.4\n */\n"
		);

		$config = require self::CONFIG_FILE;

		self::assertArrayNotHasKey( 'pluginFile', $config['parameters']['WPCompat'] );
		self::assertSame( '7.4', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function floors_plugin_requires_at_least_header_below_framework_floor(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name: Legacy\n * Requires at least: 6.5\n */\n"
		);

		$config = require self::CONFIG_FILE;

		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function floors_plugin_requires_at_least_header_with_non_numeric_value(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name: Odd\n * Requires at least: 8.x\n */\n"
		);

		$config = require self::CONFIG_FILE;

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

		$config = require self::CONFIG_FILE;

		self::assertContains(
			$this->project_dir . '/custom-vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
			$config['parameters']['bootstrapFiles']
		);
	}

	#[Test]
	public function returns_empty_paths_for_unknown_layout(): void {
		$config = require self::CONFIG_FILE;

		self::assertArrayNotHasKey( 'paths', $config['parameters'] );
		self::assertSame( '7.0', $config['parameters']['WPCompat']['requiresAtLeast'] );
	}

	#[Test]
	public function matches_plugin_name_header_in_doc_comment_block(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n/**\n * Plugin Name:       My Plugin\n * Description: foo\n */\n"
		);

		$config = require self::CONFIG_FILE;

		self::assertContains( $this->project_dir . '/my-plugin.php', $config['parameters']['paths'] );
	}

	#[Test]
	public function matches_plugin_name_header_with_hash_style_comment(): void {
		\file_put_contents(
			$this->project_dir . '/my-plugin.php',
			"<?php\n# Plugin Name: Hash Style\n"
		);

		$config = require self::CONFIG_FILE;

		self::assertContains( $this->project_dir . '/my-plugin.php', $config['parameters']['paths'] );
	}

	#[Test]
	public function bootstrap_files_omitted_when_stubs_not_installed(): void {
		\mkdir( $this->project_dir . '/src' );

		$config = require self::CONFIG_FILE;

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
