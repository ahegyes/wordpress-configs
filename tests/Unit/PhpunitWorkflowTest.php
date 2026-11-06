<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PhpunitWorkflowTest extends TestCase {
	use RunsWorkflowStepsTrait;

	/**
	 * @return array<string, array{array<string, string>, string, array<string, string>}>
	 */
	public static function provide_configs(): array {
		return array(
			'no-config'                        => array( array(), '', array( 'WP_ENV_PHP_VERSION' => '8.5' ) ),
			'unpinned'                         => array( array( '.wp-env.json' => '{ "core": null }' ), '', array( 'WP_ENV_PHP_VERSION' => '8.5' ) ),
			'pinned'                           => array( array( '.wp-env.json' => '{ "phpVersion": "7.4" }' ), '', array() ),
			'pinned-in-the-override'           => array(
				array(
					'.wp-env.json'          => '{}',
					'.wp-env.override.json' => '{ "phpVersion": "7.4" }',
				),
				'',
				array(),
			),
			'pinned-for-one-environment'       => array( array( '.wp-env.json' => '{ "env": { "tests": { "phpVersion": "7.4" } } }' ), '', array() ),
			'pinned-in-the-named-config'       => array(
				array(
					'.wp-env.json'       => '{}',
					'.wp-env.php74.json' => '{ "phpVersion": "7.4" }',
				),
				'.wp-env.php74.json',
				array(),
			),
			'pinned-beside-the-named-override' => array(
				array(
					'.wp-env.php74.json'          => '{}',
					'.wp-env.php74.override.json' => '{ "phpVersion": "7.4" }',
				),
				'.wp-env.php74.json',
				array(),
			),
			'unpinned-in-the-named-config'     => array(
				array(
					'.wp-env.json'       => '{ "phpVersion": "7.4" }',
					'.wp-env.tests.json' => '{}',
				),
				'.wp-env.tests.json',
				array( 'WP_ENV_PHP_VERSION' => '8.5' ),
			),
		);
	}

	/**
	 * @param array<string, string> $files
	 * @param array<string, string> $exported
	 */
	#[DataProvider( 'provide_configs' )]
	public function test_the_site_runs_on_php_version_only_where_the_wp_env_config_pins_no_php( array $files, string $config_file, array $exported ): void {
		$this->write_project( $files );

		$result = $this->run_step(
			'reusable-phpunit.yml',
			'phpunit',
			"Resolve the site's PHP",
			array(
				'PHP_VERSION'        => '8.5',
				'WP_ENV_CONFIG_FILE' => $config_file,
			)
		);

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( $exported, $result['env'] );
	}

	public function test_the_end_to_end_workflow_resolves_the_site_s_php_with_this_workflow_s_step(): void {
		$phpunit = self::job( 'reusable-phpunit.yml', 'phpunit' );
		$e2e     = self::job( 'reusable-playwright-e2e.yml', 'e2e' );

		$env = $e2e['env'] ?? array();
		self::assertIsArray( $env );
		self::assertArrayNotHasKey( 'WP_ENV_PHP_VERSION', $env );
		self::assertSame( \array_diff_key( self::step( $phpunit, "Resolve the site's PHP" ), array( 'if' => true ) ), self::step( $e2e, "Resolve the site's PHP" ) );
	}

	/**
	 * @return array<mixed>
	 */
	protected static function job( string $workflow, string $job ): array {
		$definition = Yaml::parseFile( __DIR__ . '/../../.github/workflows/' . $workflow );
		self::assertIsArray( $definition );
		self::assertIsArray( $definition['jobs'] ?? null );
		self::assertIsArray( $definition['jobs'][ $job ] ?? null );

		return $definition['jobs'][ $job ];
	}

	/**
	 * @param array<mixed> $job
	 *
	 * @return array<mixed>
	 */
	protected static function step( array $job, string $name ): array {
		self::assertIsArray( $job['steps'] ?? null );
		foreach ( $job['steps'] as $step ) {
			if ( \is_array( $step ) && ( $step['name'] ?? null ) === $name ) {
				return $step;
			}
		}

		self::fail( "No step named '$name'." );
	}
}
