<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupplyChainAuditWorkflowTest extends TestCase {
	use RunsWorkflowStepsTrait;

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

	public function test_advisory_mode_reports_a_package_in_its_cooldown_without_failing(): void {
		$result = $this->audit( 'cooldown.json', 'false' );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::warning::/m', $result['output'] );
	}

	public function test_failing_mode_fails_on_a_package_in_its_cooldown_as_a_finding(): void {
		$result = $this->audit( 'cooldown.json', 'true' );

		self::assertNotSame( 0, $result['status'], $result['output'] );
		self::assertDoesNotMatchRegularExpression( '/^::error::/m', $result['output'] );
	}

	/**
	 * @return array<string, array{string, int, string, int, string}>
	 */
	public static function provide_npm_reports(): array {
		$findings = '{"metadata": {"vulnerabilities": {"info": 0, "low": 1, "moderate": 0, "high": 0, "critical": 0, "total": 1}}}';

		return array(
			'clean-audit'                   => array( '{"metadata": {"vulnerabilities": {"total": 0}}}', 0, 'false', 0, '' ),
			'findings-in-advisory-mode'     => array( $findings, 1, 'false', 0, '::warning::npm audit reported findings' ),
			'findings-in-failing-mode'      => array( $findings, 1, 'true', 1, '' ),
			'error-report-in-advisory-mode' => array( '{"error": {"code": "ENOLOCK"}}', 1, 'false', 1, '::error::npm audit failed before producing a valid findings report.' ),
			'unreadable-report'             => array( 'not json', 1, 'false', 1, '::error::npm audit failed before producing a valid findings report.' ),
			'no-findings-but-a-failure'     => array( '{"metadata": {"vulnerabilities": {"total": 0}}}', 1, 'false', 1, '::error::npm audit failed before producing a valid findings report.' ),
		);
	}

	#[DataProvider( 'provide_npm_reports' )]
	public function test_npm_audit_softens_only_a_report_that_holds_findings( string $report, int $npm_status, string $fail_on_findings, int $expected_status, string $annotation ): void {
		$this->write_executable( 'bin/npm', "#!/usr/bin/env bash\nprintf '%s\\n' " . \escapeshellarg( $report ) . "\nexit $npm_status\n" );

		$result = $this->run_step(
			'reusable-supply-chain-audit.yml',
			'npm-audit',
			'npm audit',
			array(
				'AUDIT_FLAGS'      => '--omit=dev --audit-level=high',
				'FAIL_ON_FINDINGS' => $fail_on_findings,
			)
		);

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( '' !== $annotation ) {
			self::assertStringContainsString( $annotation, $result['output'] );
		}
	}

	/**
	 * @return array{status: int, output: string, outputs: array<string, string>, env: array<string, string>}
	 */
	protected function audit( ?string $fixture, string $fail_on_findings ): array {
		$report = \is_null( $fixture ) ? '/dev/null' : __DIR__ . '/../Fixtures/composer-audit/' . $fixture;
		$this->write_executable( 'bin/composer', "#!/usr/bin/env bash\ncat " . \escapeshellarg( $report ) . "\nexit 1\n" );

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
