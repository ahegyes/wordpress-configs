<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\TestCase;

final class BlockJsonCheckWorkflowTest extends TestCase {
	use RunsWorkflowStepsTrait;

	protected const string TOOLS = __DIR__ . '/../../node/block-json';

	public function test_each_block_json_is_validated_with_the_locked_ajv(): void {
		$this->write_project(
			array(
				'blocks/greeting/block.json'   => '{}',
				'blocks/notice/block.json'     => '{}',
				'node_modules/acme/block.json' => '{}',
				'vendor/acme/block.json'       => '{}',
				'.block-json-tools/node/block-json/block.json' => '{}',
			)
		);

		$result = $this->validate();

		self::assertSame( 0, $result['status'], $result['output'] );
		$validated = \file( $this->work_dir . '/ajv-calls', \FILE_IGNORE_NEW_LINES ) ?: array();
		\sort( $validated );
		self::assertSame( array( './blocks/greeting/block.json', './blocks/notice/block.json' ), $validated );
		self::assertFileDoesNotExist( $this->work_dir . '/npx-calls' );
	}

	public function test_a_project_without_blocks_validates_nothing(): void {
		$result = $this->validate();

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertStringContainsString( 'nothing to validate', $result['output'] );
		self::assertFileDoesNotExist( $this->work_dir . '/ajv-calls' );
	}

	public function test_the_tools_lock_holds_ajv_cli_and_nothing_else_at_the_top(): void {
		$manifest = \json_decode( (string) \file_get_contents( self::TOOLS . '/package.json' ), true, flags: \JSON_THROW_ON_ERROR );
		$lock     = \json_decode( (string) \file_get_contents( self::TOOLS . '/package-lock.json' ), true, flags: \JSON_THROW_ON_ERROR );
		self::assertIsArray( $manifest );
		self::assertIsArray( $lock );
		self::assertIsArray( $lock['packages'] ?? null );
		self::assertIsArray( $lock['packages'][''] ?? null );
		self::assertIsArray( $manifest['dependencies'] ?? null );

		self::assertSame( array( 'ajv-cli' ), \array_keys( $manifest['dependencies'] ) );
		self::assertArrayNotHasKey( 'devDependencies', $manifest );
		self::assertSame( $manifest['dependencies'], $lock['packages']['']['dependencies'] ?? null );
		self::assertArrayHasKey( 'node_modules/ajv-cli', $lock['packages'] );
	}

	/**
	 * @return array{status: int, output: string, outputs: array<string, string>, env: array<string, string>}
	 */
	protected function validate(): array {
		$this->write_executable( 'bin/ajv', "#!/usr/bin/env bash\nwhile [ \$# -gt 0 ]; do\n\tcase \"\$1\" in\n\t\t-d) echo \"\$2\" >> \"\$HOME/ajv-calls\"; shift 2 ;;\n\t\t*) shift ;;\n\tesac\ndone\n" );
		$this->write_executable( 'bin/npx', "#!/usr/bin/env bash\necho \"npx \$*\" >> \"\$HOME/npx-calls\"\nexit 1\n" );
		$this->write_executable( 'bin/curl', "#!/usr/bin/env bash\nwhile [ \$# -gt 0 ]; do\n\tcase \"\$1\" in\n\t\t-o) echo '{}' > \"\$2\"; shift 2 ;;\n\t\t*) shift ;;\n\tesac\ndone\n" );

		return $this->run_step(
			'reusable-block-json-check.yml',
			'validate',
			'Validate block.json files against the WordPress schema',
			array( 'AJV' => $this->work_dir . '/bin/ajv' )
		);
	}
}
