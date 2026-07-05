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
		$this->project_dir = \sys_get_temp_dir() . '/dws-wp-configs-scoper-' . \uniqid();
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
		$config = ( $this->build_config )(
			array(
				'project_dir'   => $this->project_dir,
				'exclude_files' => array( '/path/to/template.php', '/another/file.php' ),
			)
		);

		self::assertSame(
			array( '/path/to/template.php', '/another/file.php' ),
			$config['exclude-files']
		);
	}

	#[Test]
	public function reads_scoping_exclusions_into_excludes(): void {
		$this->writeScopingExclusions(
			array(
				'classes'   => array( 'WP_Post', 'WP_User' ),
				'functions' => array( 'add_action', 'wp_filesystem' ),
			)
		);

		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertContains( 'WP_Post', $config['exclude-classes'] );
		self::assertContains( 'WP_User', $config['exclude-classes'] );
		self::assertContains( 'add_action', $config['exclude-functions'] );
		self::assertContains( 'wp_filesystem', $config['exclude-functions'] );
	}

	#[Test]
	public function throws_json_exception_when_scoping_exclusions_file_is_malformed(): void {
		\file_put_contents(
			$this->project_dir . '/scoping-exclusions.json',
			'{ this is not valid json'
		);

		$this->expectException( \JsonException::class );

		( $this->build_config )( array( 'project_dir' => $this->project_dir ) );
	}

	#[Test]
	public function returns_empty_excludes_when_scoping_exclusions_file_is_missing(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertSame( array(), $config['exclude-classes'] );
		self::assertSame( array( '/^as_/' ), $config['exclude-functions'] );
	}

	#[Test]
	public function action_scheduler_function_family_is_excluded_by_regex(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertContains( '/^as_/', $config['exclude-functions'] );
	}

	#[Test]
	public function psr_namespace_is_always_excluded(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertContains( '/^Psr(?:\\\\|$)/i', $config['exclude-namespaces'] );
	}

	#[Test]
	public function psr_namespace_exclusion_is_anchored_not_substring(): void {
		// php-scoper matches a non-regex namespace exclusion by case-insensitive substring, so a
		// bare 'Psr' literal would also exclude any namespace merely containing "psr" — leaving
		// bundled PSR-7 implementations like `Nyholm\Psr7` / `GuzzleHttp\Psr7` unprefixed and
		// defeating per-plugin isolation. The emitted exclusion must match only the `Psr\*` root.
		$config      = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );
		$psr_pattern = '/^Psr(?:\\\\|$)/i';

		self::assertContains( $psr_pattern, $config['exclude-namespaces'] );

		self::assertSame( 1, \preg_match( $psr_pattern, 'Psr\\Log' ) );
		self::assertSame( 1, \preg_match( $psr_pattern, 'Psr' ) );
		self::assertSame( 0, \preg_match( $psr_pattern, 'Nyholm\\Psr7' ) );
		self::assertSame( 0, \preg_match( $psr_pattern, 'GuzzleHttp\\Psr7' ) );
		self::assertSame( 0, \preg_match( $psr_pattern, 'Acme\\Psr\\Thing' ) );
		self::assertSame( 0, \preg_match( $psr_pattern, 'Psruff\\Widgets' ) );
	}

	#[Test]
	public function plugin_overrides_merge_into_excludes(): void {
		$this->writeScopingExclusions(
			array(
				'classes'   => array( 'WP_Post' ),
				'functions' => array(),
			)
		);

		$config = ( $this->build_config )(
			array(
				'project_dir'        => $this->project_dir,
				'exclude_namespaces' => array( 'Custom\\Namespace' ),
				'exclude_classes'    => array( 'CustomClass' ),
				'exclude_functions'  => array( 'custom_function' ),
			)
		);

		self::assertContains( '/^Psr(?:\\\\|$)/i', $config['exclude-namespaces'] );
		self::assertContains( 'Custom\\Namespace', $config['exclude-namespaces'] );
		self::assertContains( 'WP_Post', $config['exclude-classes'] );
		self::assertContains( 'CustomClass', $config['exclude-classes'] );
		self::assertContains( 'custom_function', $config['exclude-functions'] );
	}

	#[Test]
	public function plugin_finders_pass_through(): void {
		$marker_finder = (object) array( 'marker' => true );

		$config = ( $this->build_config )(
			array(
				'project_dir' => $this->project_dir,
				'finders'     => array( $marker_finder ),
			)
		);

		self::assertSame( array( $marker_finder ), $config['finders'] );
	}

	#[Test]
	public function plugin_patchers_pass_through(): void {
		$plugin_patcher = static fn( string $f, string $p, string $c ): string => $c;

		$config = ( $this->build_config )(
			array(
				'project_dir' => $this->project_dir,
				'patchers'    => array( $plugin_patcher ),
			)
		);

		self::assertSame( array( $plugin_patcher ), $config['patchers'] );
	}

	#[Test]
	public function exposes_exclude_constants_key_in_returned_config(): void {
		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertArrayHasKey( 'exclude-constants', $config );
	}

	#[Test]
	public function reads_constants_into_excludes(): void {
		$this->writeScopingExclusions(
			array(
				'classes'   => array(),
				'functions' => array(),
				'constants' => array( 'WP_DEBUG', 'ABSPATH' ),
			)
		);

		$config = ( $this->build_config )( array( 'project_dir' => $this->project_dir ) );

		self::assertContains( 'WP_DEBUG', $config['exclude-constants'] );
		self::assertContains( 'ABSPATH', $config['exclude-constants'] );
	}

	#[Test]
	public function plugin_exclude_constants_override_merges_in(): void {
		$this->writeScopingExclusions(
			array(
				'classes'   => array(),
				'functions' => array(),
				'constants' => array( 'WP_DEBUG' ),
			)
		);

		$config = ( $this->build_config )(
			array(
				'project_dir'       => $this->project_dir,
				'exclude_constants' => array( 'CUSTOM_CONST' ),
			)
		);

		self::assertContains( 'WP_DEBUG', $config['exclude-constants'] );
		self::assertContains( 'CUSTOM_CONST', $config['exclude-constants'] );
	}

	#[Test]
	public function rejects_unknown_override_key(): void {
		$this->expectException( \InvalidArgumentException::class );

		( $this->build_config )(
			array(
				'project_dir'     => $this->project_dir,
				'exclude-classes' => array( 'SomeClass' ),
			)
		);
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
