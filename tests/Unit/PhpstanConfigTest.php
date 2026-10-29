<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhpstanConfigTest extends TestCase {

	protected const string FIXTURE_DIR = __DIR__ . '/../fixtures/phpstan';

	public function test_shared_rules_report_implicit_mixed_and_missing_overrides(): void {
		self::assertSame( array( 'method.missingOverride', 'return.type' ), $this->analyse( 'shared-rules.php' ) );
	}

	public function test_nested_vendor_directories_are_scanned_but_not_analyzed(): void {
		self::assertSame( array(), $this->analyse( 'nested' ) );
	}

	public function test_global_statements_take_the_types_their_function_documents(): void {
		self::assertSame( array( 'method.nonObject' ), $this->analyse( 'globals.php' ) );
	}

	public function test_the_path_discovery_adds_a_plugin_s_conventional_files_and_scans_its_scoped_dependencies(): void {
		$project = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/wp-configs-phpstan-' . \uniqid();
		foreach ( array( 'src', 'includes', 'blocks', 'vendor-prefixed/acme/library', 'docs' ) as $dir ) {
			\mkdir( "$project/$dir", 0777, true );
		}
		foreach ( array( 'functions.php', 'uninstall.php', 'footprint.php' ) as $file ) {
			\file_put_contents( "$project/$file", "<?php\n" );
		}

		$cwd = (string) \getcwd();
		\chdir( $project );
		try {
			$config = require \dirname( __DIR__, 2 ) . '/php/quality-assurance/phpstan.dist.neon.php';
		} finally {
			\chdir( $cwd );
			\exec( 'rm -rf ' . \escapeshellarg( $project ) );
		}

		self::assertSame(
			array(
				'parameters' => array(
					'paths'           => array( "$project/functions.php", "$project/uninstall.php", "$project/src", "$project/includes", "$project/blocks" ),
					'scanDirectories' => array( "$project/vendor-prefixed" ),
				),
			),
			$config
		);
	}

	/**
	 * @return list<string>
	 */
	protected function analyse( string $path ): array {
		$command = \sprintf(
			'cd %s && %s vendor/bin/phpstan analyse -c php/quality-assurance/phpstan.dist.neon -v --error-format=raw --no-progress --memory-limit=1G %s 2>&1',
			\escapeshellarg( \dirname( __DIR__, 2 ) ),
			\escapeshellarg( \PHP_BINARY ),
			\escapeshellarg( self::FIXTURE_DIR . '/' . $path )
		);
		\exec( $command, $output, $exit_code );
		\preg_match_all( '/\[identifier=([^\]]+)\]$/m', \implode( "\n", $output ), $matches );

		// PHPStan exits non-zero without reporting an error when the configuration fails to load.
		self::assertSame( array() === $matches[1] ? 0 : 1, $exit_code, \implode( "\n", $output ) );

		return $matches[1];
	}
}
