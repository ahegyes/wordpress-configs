<?php declare( strict_types = 1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the php-scoper base config closure in `php-scoper/wordpress-base.inc.php`.
 */
final class WordpressScoperConfigTest extends TestCase {

	private const CONFIG_FILE = __DIR__ . '/../../php-scoper/wordpress-base.inc.php';

	private string $project_dir;

	/** @var \Closure(array): array */
	private \Closure $build_config;

	protected function setUp(): void {
		$this->project_dir  = sys_get_temp_dir() . '/dws-wp-configs-scoper-' . uniqid();
		mkdir( $this->project_dir );
		$this->build_config = require self::CONFIG_FILE;
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function returns_config_array_with_all_expected_keys(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertArrayHasKey( 'finders', $config );
		self::assertArrayHasKey( 'exclude-namespaces', $config );
		self::assertArrayHasKey( 'exclude-classes', $config );
		self::assertArrayHasKey( 'exclude-functions', $config );
		self::assertArrayHasKey( 'patchers', $config );
	}

	#[Test]
	public function reads_wp_core_calls_json_into_excludes(): void {
		$this->writeWpCoreCalls( array(
			'classes'   => array( 'WP_Post', 'WP_User' ),
			'functions' => array( 'add_action', 'wp_filesystem' ),
		) );

		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertContains( 'WP_Post', $config['exclude-classes'] );
		self::assertContains( 'WP_User', $config['exclude-classes'] );
		self::assertContains( 'add_action', $config['exclude-functions'] );
		self::assertContains( 'wp_filesystem', $config['exclude-functions'] );
	}

	#[Test]
	public function returns_empty_excludes_when_wp_core_calls_json_is_missing(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertSame( array(), $config['exclude-classes'] );
		self::assertSame( array(), $config['exclude-functions'] );
	}

	#[Test]
	public function psr_namespace_is_always_excluded(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertContains( 'Psr', $config['exclude-namespaces'] );
	}

	#[Test]
	public function plugin_overrides_merge_into_excludes(): void {
		$this->writeWpCoreCalls( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );

		$config = ( $this->build_config )( array(
			'project_dir'        => $this->project_dir,
			'exclude_namespaces' => array( 'Custom\\Namespace' ),
			'exclude_classes'    => array( 'CustomClass' ),
			'exclude_functions'  => array( 'custom_function' ),
		) );

		self::assertContains( 'Psr', $config['exclude-namespaces'] );
		self::assertContains( 'Custom\\Namespace', $config['exclude-namespaces'] );
		self::assertContains( 'WP_Post', $config['exclude-classes'] );
		self::assertContains( 'CustomClass', $config['exclude-classes'] );
		self::assertContains( 'custom_function', $config['exclude-functions'] );
	}

	#[Test]
	public function plugin_finders_pass_through(): void {
		$marker_finder = (object) array( 'marker' => true );

		$config = ( $this->build_config )( array(
			'project_dir' => $this->project_dir,
			'finders'     => array( $marker_finder ),
		) );

		self::assertSame( array( $marker_finder ), $config['finders'] );
	}

	#[Test]
	public function plugin_patchers_are_appended_after_default(): void {
		$plugin_patcher = static fn( string $f, string $p, string $c ): string => $c;

		$config = ( $this->build_config )( array(
			'project_dir' => $this->project_dir,
			'patchers'    => array( $plugin_patcher ),
		) );

		self::assertCount( 2, $config['patchers'] );
		self::assertSame( $plugin_patcher, $config['patchers'][1] );
	}

	#[Test]
	public function patcher_strips_prefix_from_wp_function_calls(): void {
		$this->writeWpCoreCalls( array( 'classes' => array(), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php \\MyPrefix\\add_action(\'init\', $cb);';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '\\add_action(', $output );
		self::assertStringNotContainsString( '\\MyPrefix\\add_action(', $output );
	}

	#[Test]
	public function patcher_strips_prefix_from_wp_class_references(): void {
		$this->writeWpCoreCalls( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $post = new \\MyPrefix\\WP_Post();';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '\\WP_Post', $output );
		self::assertStringNotContainsString( '\\MyPrefix\\WP_Post', $output );
	}

	#[Test]
	public function patcher_handles_function_exists_string_arguments(): void {
		$this->writeWpCoreCalls( array( 'classes' => array(), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php if (function_exists('MyPrefix\\\\add_action')) {}";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "function_exists('\\add_action", $output );
		self::assertStringNotContainsString( "function_exists('MyPrefix\\\\add_action", $output );
	}

	#[Test]
	public function patcher_removes_use_statements_for_wp_classes(): void {
		$this->writeWpCoreCalls( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php\nuse MyPrefix\\WP_Post;\n\$x = 1;";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringNotContainsString( 'use MyPrefix\\WP_Post', $output );
	}

	#[Test]
	public function patcher_leaves_non_wp_references_unchanged(): void {
		$this->writeWpCoreCalls( array( 'classes' => array( 'WP_Post' ), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $a = new \\MyPrefix\\Some\\OtherClass(); \\MyPrefix\\some_function();';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertSame( $input, $output );
	}

	private function writeWpCoreCalls( array $contents ): void {
		file_put_contents(
			$this->project_dir . '/wp-core-calls.json',
			json_encode( $contents, JSON_THROW_ON_ERROR )
		);
	}

	private function getDefaultPatcher(): callable {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );
		return $config['patchers'][0];
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
