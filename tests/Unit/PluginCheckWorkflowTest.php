<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginCheckWorkflowTest extends TestCase {

	use RunsWorkflowSteps;

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function provide_exclusions(): array {
		return array(
			'github-profile'                => array( 'false', '', 'plugin_updater,plugin_readme' ),
			'github-profile-and-the-caller' => array( 'false', 'prefixing,direct_file_access', 'plugin_updater,plugin_readme,prefixing,direct_file_access' ),
			'wp-org'                        => array( 'true', '', '' ),
			'wp-org-and-the-caller'         => array( 'true', 'prefixing', 'prefixing' ),
		);
	}

	#[DataProvider( 'provide_exclusions' )]
	public function test_the_check_skips_the_profile_s_checks_and_the_caller_s( string $wp_org, string $exclude_checks, string $expected ): void {
		$result = $this->resolve( $wp_org, $exclude_checks );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( $expected, $result['outputs']['checks'] ?? null );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_malformed_exclusions(): array {
		return array(
			'space-after-comma' => array( 'prefixing, direct_file_access' ),
			'trailing-comma'    => array( 'prefixing,' ),
			'uppercase'         => array( 'Prefixing' ),
			'option'            => array( '--checks=prefixing' ),
		);
	}

	#[DataProvider( 'provide_malformed_exclusions' )]
	public function test_a_malformed_exclusion_list_fails_the_job_and_names_the_input( string $exclude_checks ): void {
		$result = $this->resolve( 'false', $exclude_checks );

		self::assertNotSame( 0, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::error::exclude-checks /m', $result['output'] );
	}

	/**
	 * @return array{status: int, output: string, outputs: array<string, string>}
	 */
	protected function resolve( string $wp_org, string $exclude_checks ): array {
		return $this->run_step(
			'reusable-plugin-check.yml',
			'plugin-check',
			'Resolve the excluded checks',
			array(
				'WP_ORG'         => $wp_org,
				'EXCLUDE_CHECKS' => $exclude_checks,
			)
		);
	}
}
