<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the php-scoper base config closure in `php-scoper/scoper-base.inc.php`.
 */
final class ScoperBaseConfigTest extends TestCase {

	private const CONFIG_FILE = __DIR__ . '/../../php/php-scoper/scoper-base.inc.php';

	private string $project_dir;

	/** @var \Closure(array<string, mixed>): array<string, mixed> */
	private \Closure $build_config;

	protected function setUp(): void {
		$this->project_dir  = \sys_get_temp_dir() . '/dws-wp-configs-scoper-' . \uniqid();
		\mkdir( $this->project_dir );
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
		self::assertArrayHasKey( 'exclude-files', $config );
		self::assertArrayHasKey( 'patchers', $config );
	}

	#[Test]
	public function exclude_files_override_passes_through(): void {
		$config = ( $this->build_config )( array(
			'project_dir'   => $this->project_dir,
			'exclude_files' => array( '/path/to/template.php', '/another/file.php' ),
		) );

		self::assertSame(
			array( '/path/to/template.php', '/another/file.php' ),
			$config['exclude-files']
		);
	}

	#[Test]
	public function reads_scoping_exclusions_into_excludes(): void {
		$this->writeScopingExclusions( array(
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
	public function returns_empty_excludes_when_scoping_exclusions_file_is_missing(): void {
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
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );

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
	public function patcher_strips_prefix_from_excluded_function_calls(): void {
		$this->writeScopingExclusions( array( 'classes' => array(), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php \\MyPrefix\\add_action(\'init\', $cb);';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '\\add_action(', $output );
		self::assertStringNotContainsString( '\\MyPrefix\\add_action(', $output );
	}

	#[Test]
	public function patcher_strips_prefix_from_excluded_class_references(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $post = new \\MyPrefix\\WP_Post();';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '\\WP_Post', $output );
		self::assertStringNotContainsString( '\\MyPrefix\\WP_Post', $output );
	}

	#[Test]
	public function patcher_handles_function_exists_string_arguments(): void {
		$this->writeScopingExclusions( array( 'classes' => array(), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php if (function_exists('MyPrefix\\\\add_action')) {}";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "function_exists('\\add_action", $output );
		self::assertStringNotContainsString( "function_exists('MyPrefix\\\\add_action", $output );
	}

	#[Test]
	public function patcher_removes_use_statements_for_excluded_classes(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php\nuse MyPrefix\\WP_Post;\n\$x = 1;";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringNotContainsString( 'use MyPrefix\\WP_Post', $output );
	}

	#[Test]
	public function patcher_handles_class_exists_string_arguments(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php if (class_exists('MyPrefix\\\\WP_Post')) {}";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "class_exists('\\WP_Post", $output );
		self::assertStringNotContainsString( "'MyPrefix\\\\WP_Post", $output );
	}

	#[Test]
	public function patcher_handles_method_exists_string_arguments(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Query' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php if (method_exists('MyPrefix\\\\WP_Query', 'have_posts')) {}";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "method_exists('\\WP_Query", $output );
		self::assertStringNotContainsString( "'MyPrefix\\\\WP_Query", $output );
	}

	#[Test]
	public function patcher_handles_is_a_string_arguments(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php if (is_a(\$obj, 'MyPrefix\\\\WP_Post')) {}";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "'\\WP_Post", $output );
		self::assertStringNotContainsString( "'MyPrefix\\\\WP_Post", $output );
	}

	#[Test]
	public function patcher_handles_is_subclass_of_string_arguments(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Widget' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php if (is_subclass_of(\$x, 'MyPrefix\\\\WP_Widget')) {}";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "'\\WP_Widget", $output );
		self::assertStringNotContainsString( "'MyPrefix\\\\WP_Widget", $output );
	}

	#[Test]
	public function patcher_handles_reflection_class_string_arguments(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php \$r = new \\ReflectionClass('MyPrefix\\\\WP_Post');";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "ReflectionClass('\\WP_Post", $output );
		self::assertStringNotContainsString( "'MyPrefix\\\\WP_Post", $output );
	}

	#[Test]
	public function patcher_does_not_strip_prefix_when_excluded_class_is_a_namespace_segment_of_a_longer_fqcn(): void {
		// `A\Foo` is excluded — but `A\Foo\Bar` is a DIFFERENT class that's still prefixed.
		$this->writeScopingExclusions( array(
			'classes'   => array( 'A\\Foo' ),
			'functions' => array(),
			'constants' => array(),
		) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $x = new \\Prefix\\A\\Foo\\Bar(); $y = new \\Prefix\\A\\Foo();';
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( '\\Prefix\\A\\Foo\\Bar', $output );
		self::assertStringContainsString( 'new \\A\\Foo()', $output );
	}

	#[Test]
	public function patcher_does_not_strip_prefix_from_extended_class_names(): void {
		// WP_Post is excluded, WP_Post_Type is NOT — partial-name match must respect a word boundary.
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $a = new \\MyPrefix\\WP_Post(); $b = new \\MyPrefix\\WP_Post_Type();';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '\\MyPrefix\\WP_Post_Type', $output );
		self::assertStringContainsString( 'new \\WP_Post()', $output );
	}

	#[Test]
	public function patcher_does_not_strip_prefix_from_extended_function_names_in_function_exists(): void {
		// add_action is excluded, add_action_link is NOT.
		$this->writeScopingExclusions( array( 'classes' => array(), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php function_exists('MyPrefix\\\\add_action'); function_exists('MyPrefix\\\\add_action_link');";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "function_exists('MyPrefix\\\\add_action_link'", $output );
		self::assertStringContainsString( "function_exists('\\add_action'", $output );
	}

	#[Test]
	public function patcher_strips_prefix_from_use_as_alias_statements(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php\nuse MyPrefix\\WP_Post as Aliased;\n\$x = new Aliased();";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringNotContainsString( 'use MyPrefix\\WP_Post', $output );
		self::assertStringContainsString( 'use \\WP_Post as Aliased', $output );
	}

	#[Test]
	public function patcher_handles_function_names_as_plain_strings(): void {
		$this->writeScopingExclusions( array( 'classes' => array(), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php array_map('MyPrefix\\\\add_action', \$arr);";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "'\\add_action'", $output );
		self::assertStringNotContainsString( "'MyPrefix\\\\add_action'", $output );
	}

	#[Test]
	public function patcher_does_not_strip_prefix_from_extended_function_names_in_string_refs(): void {
		$this->writeScopingExclusions( array( 'classes' => array(), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php array_map('MyPrefix\\\\add_action', \$arr); array_map('MyPrefix\\\\add_action_link', \$arr);";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "'\\add_action'", $output );
		self::assertStringContainsString( "'MyPrefix\\\\add_action_link'", $output );
	}

	#[Test]
	public function patcher_does_not_strip_prefix_from_extended_class_names_in_string_refs(): void {
		// WP_Post excluded, WP_Post_Type not.
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php class_exists('MyPrefix\\\\WP_Post'); class_exists('MyPrefix\\\\WP_Post_Type');";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "class_exists('MyPrefix\\\\WP_Post_Type'", $output );
		self::assertStringContainsString( "class_exists('\\WP_Post'", $output );
	}

	#[Test]
	public function exposes_exclude_constants_key_in_returned_config(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertArrayHasKey( 'exclude-constants', $config );
	}

	#[Test]
	public function reads_constants_into_excludes(): void {
		$this->writeScopingExclusions( array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array( 'WP_DEBUG', 'ABSPATH' ),
		) );

		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertContains( 'WP_DEBUG', $config['exclude-constants'] );
		self::assertContains( 'ABSPATH', $config['exclude-constants'] );
	}

	#[Test]
	public function plugin_exclude_constants_override_merges_in(): void {
		$this->writeScopingExclusions( array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array( 'WP_DEBUG' ),
		) );

		$config = ( $this->build_config )( array(
			'project_dir'       => $this->project_dir,
			'exclude_constants' => array( 'CUSTOM_CONST' ),
		) );

		self::assertContains( 'WP_DEBUG', $config['exclude-constants'] );
		self::assertContains( 'CUSTOM_CONST', $config['exclude-constants'] );
	}

	#[Test]
	public function patcher_strips_prefix_from_excluded_constant_references(): void {
		$this->writeScopingExclusions( array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array( 'WP_DEBUG' ),
		) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $x = \\MyPrefix\\WP_DEBUG;';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '\\WP_DEBUG;', $output );
		self::assertStringNotContainsString( '\\MyPrefix\\WP_DEBUG', $output );
	}

	#[Test]
	public function patcher_handles_defined_string_arguments(): void {
		$this->writeScopingExclusions( array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array( 'WP_DEBUG' ),
		) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php if (defined('MyPrefix\\\\WP_DEBUG')) {}";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( "defined('\\WP_DEBUG", $output );
		self::assertStringNotContainsString( "'MyPrefix\\\\WP_DEBUG", $output );
	}

	#[Test]
	public function patcher_does_not_strip_prefix_from_extended_constant_names(): void {
		$this->writeScopingExclusions( array(
			'classes'   => array(),
			'functions' => array(),
			'constants' => array( 'WP_DEBUG' ),
		) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $x = \\MyPrefix\\WP_DEBUG; $y = \\MyPrefix\\WP_DEBUG_LOG;';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '\\MyPrefix\\WP_DEBUG_LOG', $output );
		self::assertStringContainsString( '\\WP_DEBUG;', $output );
	}

	#[Test]
	public function patcher_does_not_rewrite_pattern_inside_inline_comments(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php\n// References \\MyPrefix\\WP_Post and 'MyPrefix\\\\WP_Post'\n\$x = new \\MyPrefix\\WP_Post();";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '// References \\MyPrefix\\WP_Post', $output );
		self::assertStringContainsString( "'MyPrefix\\\\WP_Post'", $output );
		self::assertStringContainsString( 'new \\WP_Post()', $output );
	}

	#[Test]
	public function patcher_does_not_rewrite_pattern_inside_doc_comments(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array() ) );
		$patcher = $this->getDefaultPatcher();

		$input  = "<?php\n/**\n * @see \\MyPrefix\\WP_Post\n */\n\$x = new \\MyPrefix\\WP_Post();";
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertStringContainsString( '@see \\MyPrefix\\WP_Post', $output );
		self::assertStringContainsString( 'new \\WP_Post()', $output );
	}

	#[Test]
	public function patcher_leaves_non_excluded_references_unchanged(): void {
		$this->writeScopingExclusions( array( 'classes' => array( 'WP_Post' ), 'functions' => array( 'add_action' ) ) );
		$patcher = $this->getDefaultPatcher();

		$input  = '<?php $a = new \\MyPrefix\\Some\\OtherClass(); \\MyPrefix\\some_function();';
		$output = $patcher( '/file.php', 'MyPrefix', $input );

		self::assertSame( $input, $output );
	}

	/**
	 * @param array<string, mixed> $contents
	 */
	private function writeScopingExclusions( array $contents ): void {
		\file_put_contents(
			$this->project_dir . '/scoping-exclusions.json',
			\json_encode( $contents, JSON_THROW_ON_ERROR )
		);
	}

	private function getDefaultPatcher(): callable {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );
		return $config['patchers'][0];
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
