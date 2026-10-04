<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpcsProfilesTest extends TestCase {

	protected const string RULESET_DIR = __DIR__ . '/../../php/quality-assurance';

	protected string $scan_dir;

	#[\Override]
	protected function setUp(): void {
		$this->scan_dir = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/dws-wp-configs-phpcs-' . \uniqid();
		\mkdir( $this->scan_dir );
	}

	#[\Override]
	protected function tearDown(): void {
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->scan_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $entries as $entry ) {
			self::assertInstanceOf( \SplFileInfo::class, $entry );
			$entry->isDir() ? \rmdir( $entry->getPathname() ) : \unlink( $entry->getPathname() );
		}
		\rmdir( $this->scan_dir );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_profiles(): array {
		return array(
			'production' => array( 'phpcs.dist.xml' ),
			'tests'      => array( 'phpcs.tests.dist.xml' ),
		);
	}

	#[DataProvider( 'provide_profiles' )]
	public function test_profile_excludes_vendor_directories_case_sensitively( string $profile ): void {
		foreach ( array( 'vendor', 'vendor-prefixed', 'src/Vendor' ) as $dir ) {
			\mkdir( $this->scan_dir . '/' . $dir, 0777, true );
			\file_put_contents( $this->scan_dir . '/' . $dir . '/x.php', "<?php declare( strict_types=1 );\n\n\$a = [];\n" );
		}

		self::assertSame( array( 'src/Vendor/x.php' ), \array_keys( $this->scan( $profile ) ) );
	}

	public function test_production_profile_skips_docblock_sniffs_in_patterns_directories(): void {
		foreach ( array( 'patterns', 'src/Patterns' ) as $dir ) {
			\mkdir( $this->scan_dir . '/' . $dir, 0777, true );
			\file_put_contents( $this->scan_dir . '/' . $dir . '/x.php', "<?php declare( strict_types=1 );\n\nclass X {}\n" );
		}

		self::assertSame( array( 'src/Patterns/x.php' ), \array_keys( $this->scan( 'phpcs.dist.xml' ) ) );
	}

	public function test_production_profile_reports_unsanitized_input(): void {
		\file_put_contents( $this->scan_dir . '/input.php', "<?php declare( strict_types=1 );\n\n\$name = wp_unslash( \$_POST['name'] ?? '' );\n" );

		self::assertContains( 'WordPress.Security.ValidatedSanitizedInput.InputNotSanitized', $this->scan( 'phpcs.dist.xml' )['input.php'] ?? array() );
	}

	/**
	 * @return array<string, list<string>>
	 */
	protected function scan( string $profile ): array {
		$command = \sprintf(
			'%s %s -q --report=json --no-colors --no-cache --standard=%s --basepath=%s %s 2>&1',
			\escapeshellarg( \PHP_BINARY ),
			\escapeshellarg( __DIR__ . '/../../vendor/bin/phpcs' ),
			\escapeshellarg( self::RULESET_DIR . '/' . $profile ),
			\escapeshellarg( $this->scan_dir ),
			\escapeshellarg( $this->scan_dir )
		);
		$output  = (string) \shell_exec( $command );
		$report  = \json_decode( $output, true );
		self::assertIsArray( $report, $output );
		self::assertIsArray( $report['files'], $output );

		$sources = array();
		foreach ( $report['files'] as $file => $result ) {
			self::assertIsArray( $result );
			self::assertIsArray( $result['messages'] );
			foreach ( $result['messages'] as $message ) {
				self::assertIsArray( $message );
				self::assertIsString( $message['source'] );
				$sources[ (string) $file ][] = $message['source'];
			}
		}

		return $sources;
	}
}
