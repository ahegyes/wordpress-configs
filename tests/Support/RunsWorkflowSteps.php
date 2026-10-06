<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use Symfony\Component\Yaml\Yaml;

trait RunsWorkflowSteps {

	protected string $work_dir;

	#[Before]
	protected function create_work_dir(): void {
		$this->work_dir = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/wp-configs-workflow-' . \uniqid();
		\mkdir( $this->work_dir . '/project', 0777, true );
		\mkdir( $this->work_dir . '/bin' );
	}

	#[After]
	protected function remove_work_dir(): void {
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->work_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $entries as $entry ) {
			self::assertInstanceOf( \SplFileInfo::class, $entry );
			$entry->isDir() ? \rmdir( $entry->getPathname() ) : \unlink( $entry->getPathname() );
		}
		\rmdir( $this->work_dir );
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

	protected function write_executable( string $name, string $script ): void {
		\file_put_contents( $this->work_dir . '/bin/' . $name, $script );
		\chmod( $this->work_dir . '/bin/' . $name, 0755 );
	}

	/**
	 * @param array<string, string> $env
	 *
	 * @return array{status: int, output: string, outputs: array<string, string>}
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
		\file_put_contents( $this->work_dir . '/github-output', '' );
		\file_put_contents( $log, '' );
		$process = \proc_open(
			array( 'bash', '--noprofile', '--norc', '-eo', 'pipefail', $this->work_dir . '/step.sh' ), // The flags GitHub Actions runs a step's script with.
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
			)
		);
		self::assertIsResource( $process );
		$status = \proc_close( $process );
		$output = (string) \file_get_contents( $log );

		$outputs = array();
		foreach ( \file( $this->work_dir . '/github-output', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
			$pair                = \explode( '=', $line, 2 );
			$outputs[ $pair[0] ] = $pair[1] ?? '';
		}

		return array(
			'status'  => $status,
			'output'  => $output,
			'outputs' => $outputs,
		);
	}
}
