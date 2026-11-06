<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
}
