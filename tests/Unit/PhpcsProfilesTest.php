<?php declare( strict_types=1 );

namespace WordPressConfigs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpcsProfilesTest extends TestCase {

	protected const string RULESET_DIR = __DIR__ . '/../../php/quality-assurance';

	protected string $scan_dir;

	#[\Override]
	protected function setUp(): void {
		$this->scan_dir = ( \realpath( \sys_get_temp_dir() ) ?: \sys_get_temp_dir() ) . '/wp-configs-phpcs-' . \uniqid();
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

		self::assertSame( array( 'src/Vendor/x.php' ), \array_keys( $this->scan( self::RULESET_DIR . '/' . $profile ) ) );
	}

	public function test_production_profile_skips_docblock_sniffs_in_patterns_directories(): void {
		foreach ( array( 'patterns', 'src/Patterns' ) as $dir ) {
			\mkdir( $this->scan_dir . '/' . $dir, 0777, true );
			\file_put_contents( $this->scan_dir . '/' . $dir . '/x.php', "<?php declare( strict_types=1 );\n\nclass X {}\n" );
		}

		self::assertSame( array( 'src/Patterns/x.php' ), \array_keys( $this->scan( self::RULESET_DIR . '/phpcs.dist.xml' ) ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_silence_is_golden_stubs(): array {
		return array(
			'two-line-comment'  => array( "<?php\n// Silence is golden.\n" ),
			'same-line-comment' => array( "<?php // Silence is golden.\n" ),
		);
	}

	#[DataProvider( 'provide_silence_is_golden_stubs' )]
	public function test_production_profile_accepts_silence_is_golden_stubs( string $stub ): void {
		\mkdir( $this->scan_dir . '/assets' );
		\file_put_contents( $this->scan_dir . '/assets/index.php', $stub );

		self::assertSame( array(), $this->scan( self::RULESET_DIR . '/phpcs.dist.xml' ) );
	}

	public function test_production_profile_lints_a_theme_index_file(): void {
		\mkdir( $this->scan_dir . '/theme' );
		\file_put_contents( $this->scan_dir . '/theme/index.php', "<?php\n\nget_header();\necho get_query_var( 'paged' );\nget_footer();\n" );

		self::assertArrayHasKey( 'WordPress.Security.EscapeOutput.OutputNotEscaped', $this->scan( self::RULESET_DIR . '/phpcs.dist.xml' )['theme/index.php'] ?? array() );
	}

	public function test_production_profile_reports_unsanitized_input(): void {
		\file_put_contents( $this->scan_dir . '/input.php', "<?php declare( strict_types=1 );\n\n\$name = wp_unslash( \$_POST['name'] ?? '' );\n" );

		self::assertArrayHasKey( 'WordPress.Security.ValidatedSanitizedInput.InputNotSanitized', $this->scan( self::RULESET_DIR . '/phpcs.dist.xml' )['input.php'] ?? array() );
	}

	/**
	 * @return array<string, array{string, list<string>, bool}>
	 */
	public static function provide_floor_overrides(): array {
		$floors = '<config name="testVersion" value="7.4-"/><config name="minimum_wp_version" value="6.8"/>';

		return array(
			'shared-floors'                  => array( '', array(), false ),
			'config-in-the-consumer-ruleset' => array( $floors, array(), false ),
			'floors-ruleset-included-after'  => array( '<rule ref="./floors.xml"/>', array(), true ),
			'runtime-set'                    => array( '', array( '--runtime-set', 'testVersion', '7.4-', '--runtime-set', 'minimum_wp_version', '6.8' ), true ),
		);
	}

	/**
	 * @param list<string> $arguments
	 */
	#[DataProvider( 'provide_floor_overrides' )]
	public function test_a_consumer_lowers_the_floors_only_after_the_profile( string $after_profile, array $arguments, bool $lowered ): void {
		\file_put_contents( $this->scan_dir . '/floors.xml', '<?xml version="1.0"?><ruleset name="Floors"><config name="testVersion" value="7.4-"/><config name="minimum_wp_version" value="6.8"/></ruleset>' );
		\file_put_contents( $this->scan_dir . '/phpcs.xml', '<?xml version="1.0"?><ruleset name="Consumer"><rule ref="' . self::RULESET_DIR . '/phpcs.dist.xml"/>' . $after_profile . '</ruleset>' );
		\file_put_contents( $this->scan_dir . '/floors.php', "<?php declare( strict_types=1 );\n\nfunction acme_probe( mixed \$value ): void {\n\taddslashes_gpc( \$value );\n}\n" );

		$sources = $this->scan( $this->scan_dir . '/phpcs.xml', ...$arguments )['floors.php'] ?? array();

		self::assertSame( $lowered ? 'ERROR' : null, $sources['PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.mixedFound'] ?? null );
		self::assertSame( $lowered ? 'WARNING' : 'ERROR', $sources['WordPress.WP.DeprecatedFunctions.addslashes_gpcFound'] ?? null );
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	protected function scan( string $standard, string ...$arguments ): array {
		$command = \sprintf(
			'%s %s -q --report=json --no-colors --no-cache --standard=%s --basepath=%s %s %s 2>&1',
			\escapeshellarg( \PHP_BINARY ),
			\escapeshellarg( __DIR__ . '/../../vendor/bin/phpcs' ),
			\escapeshellarg( $standard ),
			\escapeshellarg( $this->scan_dir ),
			\implode( ' ', \array_map( 'escapeshellarg', $arguments ) ),
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
				self::assertIsString( $message['type'] );
				$sources[ (string) $file ][ $message['source'] ] = $message['type'];
			}
		}

		return $sources;
	}
}
