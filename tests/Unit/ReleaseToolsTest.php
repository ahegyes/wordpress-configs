<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseToolsTest extends TestCase {

	protected const string DIR = __DIR__ . '/../../php/release';

	public function test_the_lock_is_current_with_its_manifest(): void {
		\exec( \sprintf( 'composer validate --strict --working-dir=%s 2>&1', \escapeshellarg( self::DIR ) ), $output, $status );

		self::assertSame( 0, $status, \implode( "\n", $output ) );
	}

	public function test_the_lock_holds_every_command_the_release_runs_and_nothing_for_development(): void {
		$lock = \json_decode( (string) \file_get_contents( self::DIR . '/composer.lock' ), true, flags: \JSON_THROW_ON_ERROR );
		self::assertIsArray( $lock );
		self::assertIsArray( $lock['packages'] ?? null );

		$names = \array_column( $lock['packages'], 'name' );
		foreach ( array( 'wp-cli/wp-cli', 'wp-cli/i18n-command', 'wp-cli/dist-archive-command' ) as $package ) {
			self::assertContains( $package, $names );
		}
		self::assertSame( array(), $lock['packages-dev'] ?? null );
	}
}
