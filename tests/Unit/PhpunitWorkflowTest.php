<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpunitWorkflowTest extends TestCase {

	use RunsWorkflowSteps;

	/**
	 * @return array<string, array{array<string, string>, string, string}>
	 */
	public static function provide_configs(): array {
		return array(
			'no-config'                        => array( array(), '', "WP_ENV_PHP_VERSION=8.5\n" ),
			'unpinned'                         => array( array( '.wp-env.json' => '{ "core": null }' ), '', "WP_ENV_PHP_VERSION=8.5\n" ),
			'pinned'                           => array( array( '.wp-env.json' => '{ "phpVersion": "7.4" }' ), '', '' ),
			'pinned-in-the-override'           => array(
				array(
					'.wp-env.json'          => '{}',
					'.wp-env.override.json' => '{ "phpVersion": "7.4" }',
				),
				'',
				'',
			),
			'pinned-for-one-environment'       => array( array( '.wp-env.json' => '{ "env": { "tests": { "phpVersion": "7.4" } } }' ), '', '' ),
			'pinned-in-the-named-config'       => array(
				array(
					'.wp-env.json'       => '{}',
					'.wp-env.php74.json' => '{ "phpVersion": "7.4" }',
				),
				'.wp-env.php74.json',
				'',
			),
			'pinned-beside-the-named-override' => array(
				array(
					'.wp-env.php74.json'          => '{}',
					'.wp-env.php74.override.json' => '{ "phpVersion": "7.4" }',
				),
				'.wp-env.php74.json',
				'',
			),
			'unpinned-in-the-named-config'     => array(
				array(
					'.wp-env.json'       => '{ "phpVersion": "7.4" }',
					'.wp-env.tests.json' => '{}',
				),
				'.wp-env.tests.json',
				"WP_ENV_PHP_VERSION=8.5\n",
			),
		);
	}

	/**
	 * @param array<string, string> $files
	 */
	#[DataProvider( 'provide_configs' )]
	public function test_the_site_runs_on_php_version_only_where_the_wp_env_config_pins_no_php( array $files, string $config_file, string $exported ): void {
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
		self::assertSame( $exported, \is_file( $this->work_dir . '/github-env' ) ? (string) \file_get_contents( $this->work_dir . '/github-env' ) : '' );
	}
}
