<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhpstanConfigTest extends TestCase {

	protected const string FIXTURE_DIR = __DIR__ . '/../fixtures/phpstan';

	public function test_container_get_returns_the_requested_class(): void {
		self::assertSame( array(), $this->analyse( 'container.php' ) );
	}

	public function test_shared_rules_report_implicit_mixed_and_missing_overrides(): void {
		self::assertSame( array( 'method.missingOverride', 'return.type' ), $this->analyse( 'shared-rules.php' ) );
	}

	public function test_nested_vendor_directories_are_scanned_but_not_analyzed(): void {
		self::assertSame( array(), $this->analyse( 'nested' ) );
	}

	/**
	 * @return list<string>
	 */
	protected function analyse( string $path ): array {
		$command = \sprintf(
			'cd %s && %s vendor/bin/phpstan analyse -c php/quality-assurance/phpstan.dist.neon --error-format=raw --no-progress --memory-limit=1G %s 2>&1',
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
