<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkflowChecksWorkflowTest extends TestCase {
	use RunsWorkflowStepsTrait;

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_extra_workflows(): array {
		return array(
			'none'      => array( '[]', '' ),
			'one-glob'  => array( '["plugins/*/.github/workflows/*.yml"]', '.github/workflows/*.yml,.github/workflows/*.yaml,plugins/*/.github/workflows/*.yml' ),
			'two-globs' => array( '["plugins/*/.github/workflows/*.yml", "tools/ci/*.yaml"]', '.github/workflows/*.yml,.github/workflows/*.yaml,plugins/*/.github/workflows/*.yml,tools/ci/*.yaml' ),
		);
	}

	#[DataProvider( 'provide_extra_workflows' )]
	public function test_actionlint_lints_the_extra_workflows_beside_the_repository_s_own( string $extra_workflows, string $files ): void {
		$result = $this->resolve( $extra_workflows );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( array( 'files' => $files ), $result['outputs'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_malformed_extra_workflows(): array {
		return array(
			'not-json'        => array( 'plugins/*/.github/workflows/*.yml' ),
			'a-string'        => array( '"plugins/*/.github/workflows/*.yml"' ),
			'a-number-entry'  => array( '[1]' ),
			'an-empty-entry'  => array( '[""]' ),
			'a-comma-in-glob' => array( '["plugins/a/x.yml,plugins/b/x.yml"]' ),
		);
	}

	#[DataProvider( 'provide_malformed_extra_workflows' )]
	public function test_a_malformed_list_fails_the_job_and_names_the_input( string $extra_workflows ): void {
		$result = $this->resolve( $extra_workflows );

		self::assertNotSame( 0, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::error::extra-workflows /m', $result['output'] );
		self::assertSame( array(), $result['outputs'] );
	}

	/**
	 * @return array{status: int, output: string, outputs: array<string, string>, env: array<string, string>}
	 */
	protected function resolve( string $extra_workflows ): array {
		return $this->run_step( 'reusable-workflow-checks.yml', 'actionlint', 'Resolve the actionlint files', array( 'EXTRA_WORKFLOWS' => $extra_workflows ) );
	}
}
