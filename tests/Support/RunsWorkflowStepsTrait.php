<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

trait RunsWorkflowStepsTrait {
	protected string $work_dir;

	#[Before]
	protected function create_work_dir(): void {
		$this->work_dir = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/wp-configs-workflow-' . \uniqid();
		\mkdir( $this->work_dir . '/project', 0777, true );
		\mkdir( $this->work_dir . '/bin' );
	}

	#[After]
	protected function remove_work_dir(): void {
		new Filesystem()->remove( $this->work_dir );
	}

	/**
	 * @param array<string, string> $files
	 */
	protected function write_project( array $files ): void {
		foreach ( $files as $path => $contents ) {
			$file = $this->work_dir . '/project/' . $path;
			if ( ! \is_dir( \dirname( $file ) ) ) {
				\mkdir( \dirname( $file ), 0777, true );
			}

			\file_put_contents( $file, $contents );
		}
	}

	protected function write_executable( string $path, string $script ): void {
		if ( ! \is_dir( \dirname( $this->work_dir . '/' . $path ) ) ) {
			\mkdir( \dirname( $this->work_dir . '/' . $path ), 0777, true );
		}

		\file_put_contents( $this->work_dir . '/' . $path, $script );
		\chmod( $this->work_dir . '/' . $path, 0755 );
	}

	/**
	 * @param array<string, string> $env
	 *
	 * @return array{status: int, output: string, outputs: array<string, string>, env: array<string, string>}
	 */
	protected function run_step( string $workflow, string $job, string $name, array $env ): array {
		$definition = Yaml::parseFile( __DIR__ . '/../../.github/workflows/' . $workflow );
		self::assertIsArray( $definition );
		self::assertIsArray( $definition['jobs'] ?? null );
		$job_definition = $definition['jobs'][ $job ] ?? null;
		self::assertIsArray( $job_definition, "No job '$job' in $workflow." );
		self::assertIsArray( $job_definition['steps'] ?? null );

		$script = null;
		foreach ( $job_definition['steps'] as $step ) {
			if ( \is_array( $step ) && ( $step['name'] ?? null ) === $name ) {
				$script = $step['run'] ?? null;
			}
		}

		self::assertIsString( $script, "No run step named '$name' in job '$job' of $workflow." );

		$log = $this->work_dir . '/step.log';
		\file_put_contents( $this->work_dir . '/step.sh', $script );
		foreach ( array( 'github-output', 'github-env', 'github-path' ) as $file ) {
			\file_put_contents( $this->work_dir . '/' . $file, '' );
		}

		\file_put_contents( $log, '' );
		$process = \proc_open(
			array( 'bash', '-e', $this->work_dir . '/step.sh' ), // GitHub Actions runs a step that names no shell this way, so a script fails here exactly as it fails in CI.
			array(
				1 => array( 'file', $log, 'a' ),
				2 => array( 'file', $log, 'a' ),
			),
			$pipes,
			$this->work_dir . '/project',
			$env + array(
				'PATH'          => $this->work_dir . '/bin:' . \getenv( 'PATH' ),
				'HOME'          => $this->work_dir,
				'GITHUB_OUTPUT' => $this->work_dir . '/github-output',
				'GITHUB_ENV'    => $this->work_dir . '/github-env',
				'GITHUB_PATH'   => $this->work_dir . '/github-path',
			)
		);
		self::assertIsResource( $process );
		$status = \proc_close( $process );
		$output = (string) \file_get_contents( $log );

		$files = array(
			'outputs' => 'github-output',
			'env'     => 'github-env',
		);
		$pairs = array();
		foreach ( $files as $key => $file ) {
			$pairs[ $key ] = array();
			foreach ( \file( $this->work_dir . '/' . $file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
				$pair                      = \explode( '=', $line, 2 );
				$pairs[ $key ][ $pair[0] ] = $pair[1] ?? '';
			}
		}

		return array(
			'status'  => $status,
			'output'  => $output,
			'outputs' => $pairs['outputs'],
			'env'     => $pairs['env'],
		);
	}
}
