<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodeqlWorkflowTest extends TestCase {
	use RunsWorkflowStepsTrait;

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function provide_probe_answers(): array {
		return array(
			'advanced-security-on'     => array( '200', '[]', 'true', '' ),
			'advanced-security-off'    => array( '403', '{"message": "Advanced Security must be enabled for this repository to use code scanning."}', 'false', '::notice::' ),
			'another-forbidden-answer' => array( '403', '{"message": "Resource not accessible by integration"}', 'true', '::warning::' ),
			'server-error'             => array( '500', '{}', 'true', '::warning::' ),
		);
	}

	#[DataProvider( 'provide_probe_answers' )]
	public function test_analyze_is_skipped_only_where_advanced_security_is_off( string $code, string $body, string $available, string $annotation ): void {
		$this->write_executable( 'bin/curl', "#!/usr/bin/env bash\necho call >> \"\$HOME/curl-calls\"\nwhile [ \$# -gt 0 ]; do\n\tcase \"\$1\" in\n\t\t-o) printf '%s' " . \escapeshellarg( $body ) . " > \"\$2\"; shift 2 ;;\n\t\t*) shift ;;\n\tesac\ndone\nprintf '%s' " . \escapeshellarg( $code ) . "\n" );
		$this->write_executable( 'bin/sleep', "#!/usr/bin/env bash\n" );
		\mkdir( $this->work_dir . '/runner-temp' );

		$result = $this->run_step(
			'reusable-codeql.yml',
			'detect-ghas',
			'Detect GitHub Advanced Security availability',
			array(
				'GH_TOKEN'    => 'token',
				'REPO'        => 'acme/acme-plugin',
				'RUNNER_TEMP' => $this->work_dir . '/runner-temp',
			)
		);

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( array( 'ghas-available' => $available ), $result['outputs'] );
		if ( '' !== $annotation ) {
			self::assertStringContainsString( $annotation, $result['output'] );
		}

		$calls = \file( $this->work_dir . '/curl-calls', \FILE_IGNORE_NEW_LINES ) ?: array();
		self::assertCount( '::warning::' === $annotation ? 3 : 1, $calls );
	}
}
