<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplyChainAuditWorkflowTest extends TestCase {

	use RunsWorkflowSteps;

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_fail_on_findings(): array {
		return array(
			'advisory-mode' => array( 'false' ),
			'failing-mode'  => array( 'true' ),
		);
	}

	#[DataProvider( 'provide_fail_on_findings' )]
	public function test_composer_audit_fails_on_a_filter_list_hit_and_names_the_package( string $fail_on_findings ): void {
		$result = $this->audit( 'malware-filter.json', $fail_on_findings );

		self::assertNotSame( 0, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::error::.*acme\/compromised/m', $result['output'] );
	}

	public function test_advisory_mode_reports_advisories_without_failing(): void {
		$result = $this->audit( 'advisory.json', 'false' );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::warning::/m', $result['output'] );
	}

	#[DataProvider( 'provide_fail_on_findings' )]
	public function test_composer_audit_reports_an_unreadable_report( string $fail_on_findings ): void {
		$result = $this->audit( null, $fail_on_findings );

		self::assertNotSame( 0, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::error::composer audit failed before producing a valid findings report\.$/m', $result['output'] );
	}

	public function test_failing_mode_fails_on_advisories(): void {
		$result = $this->audit( 'advisory.json', 'true' );

		self::assertNotSame( 0, $result['status'], $result['output'] );
	}

	/**
	 * @return array{status: int, output: string, outputs: array<string, string>}
	 */
	protected function audit( ?string $fixture, string $fail_on_findings ): array {
		$report = \is_null( $fixture ) ? '/dev/null' : __DIR__ . '/../fixtures/composer-audit/' . $fixture;
		$this->write_executable( 'composer', "#!/usr/bin/env bash\ncat " . \escapeshellarg( $report ) . "\nexit 1\n" );

		return $this->run_step(
			'reusable-supply-chain-audit.yml',
			'composer-audit',
			'composer audit',
			array(
				'AUDIT_FLAGS'      => '--abandoned=report',
				'FAIL_ON_FINDINGS' => $fail_on_findings,
			)
		);
	}
}
