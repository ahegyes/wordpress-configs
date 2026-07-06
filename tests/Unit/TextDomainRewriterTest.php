<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the framework text-domain rewriter returned by `php-scoper/contrib/wp-framework.inc.php`.
 *
 * Framework CI enforces plain literal `wp-framework-*` text domains in gettext calls. The scope-time
 * partial rewrites those plain literals to the consumer domain and trips on reserved occurrences
 * outside that lint guarantee.
 */
final class TextDomainRewriterTest extends TestCase {

	private const WP_FRAMEWORK_PARTIAL = __DIR__ . '/../../php/php-scoper/contrib/wp-framework.inc.php';

	private string $project_dir;

	private string $vendor_dir;

	protected function setUp(): void {
		$this->project_dir = \sys_get_temp_dir() . '/dws-wp-configs-textdomain-' . \uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		\mkdir( $this->vendor_dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	#[DataProvider( 'plainFrameworkLiteralProvider' )]
	public function rewrites_plain_framework_literals_to_target( string $input, string $expected ): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		self::assertSame( $expected, $patcher( '/file.php', 'Prefix', $input ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function plainFrameworkLiteralProvider(): array {
		return array(
			'single quoted'       => array(
				"<?php \$domain = 'wp-framework-bootstrap';",
				"<?php \$domain = 'my-plugin';",
			),
			'double quoted'       => array(
				'<?php $domain = "wp-framework-bootstrap";',
				"<?php \$domain = 'my-plugin';",
			),
			'lowercase b prefix'  => array(
				"<?php \$domain = b'wp-framework-bootstrap';",
				"<?php \$domain = 'my-plugin';",
			),
			'uppercase b prefix'  => array(
				'<?php $domain = B"wp-framework-bootstrap";',
				"<?php \$domain = 'my-plugin';",
			),
			'per-package domain'  => array(
				"<?php \$domain = 'wp-framework-core';",
				"<?php \$domain = 'my-plugin';",
			),
			'multiline gettext'   => array(
				"<?php \\__(\n\t'Text',\n\t'wp-framework-bootstrap'\n);",
				"<?php \\__(\n\t'Text',\n\t'my-plugin'\n);",
			),
			'unqualified gettext' => array(
				"<?php __( 'Text', 'wp-framework-bootstrap' );",
				"<?php __( 'Text', 'my-plugin' );",
			),
		);
	}

	#[Test]
	public function rewrites_exact_installed_framework_domains_in_any_code_position(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input    = "<?php \$domain = 'wp-framework-core'; register_thing( 'wp-framework-bootstrap' );";
		$expected = "<?php \$domain = 'my-plugin'; register_thing( 'my-plugin' );";

		self::assertSame( $expected, $patcher( '/file.php', 'Prefix', $input ) );
	}

	#[Test]
	public function rewrites_each_plain_framework_literal(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\__( 'a', 'wp-framework-bootstrap' ); \\__( 'b', 'wp-framework-core' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringNotContainsString( 'wp-framework', $output );
		self::assertSame( 2, \substr_count( $output, "'my-plugin'" ) );
	}

	#[Test]
	public function leaves_non_reserved_domains_untouched(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input = "<?php \\__( 'Hello', 'some-other-domain' ); \\translate( 'Hello', 'third-party-domain' );";

		self::assertSame( $input, $patcher( '/third-party.php', 'Prefix', $input ) );
	}

	#[Test]
	public function rewrites_framework_domain_literal_even_when_its_package_is_not_installed(): void {
		// Shape-based rewrite: a well-formed "wp-framework-<slug>" literal is rewritten even when no
		// installed package carries that exact name, so the rewrite is not coupled to the installed set.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		self::assertSame(
			"<?php \\__( 'Setting', 'my-plugin' );",
			$patcher( '/merged-away.php', 'Prefix', "<?php \\__( 'Setting', 'wp-framework-settings' );" )
		);
	}

	#[Test]
	public function trips_on_installed_framework_domain_with_extra_path_suffix(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectFrameworkLintTripwire( '/path-suffix.php' );

		$patcher( '/path-suffix.php', 'Prefix', "<?php \$asset = 'wp-framework-core/assets/x';" );
	}

	#[Test]
	public function leaves_compound_expressions_without_reserved_literals_untouched(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input = "<?php \\__( 'T', 'foo' . '-bar' ); \$domain = 'plain' . '-value';";

		self::assertSame( $input, $patcher( '/third-party.php', 'Prefix', $input ) );
	}

	#[Test]
	public function leaves_bare_wp_framework_untouched(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input = "<?php \$x = 'wp-framework'; \\__( 'Hi', 'wp-framework' );";

		self::assertSame( $input, $patcher( '/file.php', 'Prefix', $input ) );
	}

	#[Test]
	public function trips_on_mid_string_constant_occurrence(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectFrameworkLintTripwire( '/mid-string.php' );

		$patcher( '/mid-string.php', 'Prefix', "<?php \$x = 'vendor/wp-framework-core';" );
	}

	#[Test]
	public function trips_on_escape_obfuscated_constant_occurrence(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectFrameworkLintTripwire( '/obfuscated.php' );

		$patcher( '/obfuscated.php', 'Prefix', '<?php $x = "\x77p-framework-core";' );
	}

	#[Test]
	public function trips_on_reserved_occurrence_in_heredoc(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectFrameworkLintTripwire( '/heredoc.php' );

		$patcher( '/heredoc.php', 'Prefix', "<?php \$x = <<<EOT\nuses wp-framework-core here\nEOT;\n" );
	}

	#[Test]
	public function trips_on_reserved_occurrence_in_nowdoc(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectFrameworkLintTripwire( '/nowdoc.php' );

		$patcher( '/nowdoc.php', 'Prefix', "<?php \$x = <<<'EOT'\nuses wp-framework-core here\nEOT;\n" );
	}

	#[Test]
	public function trips_on_reserved_occurrence_in_interpolated_string(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectFrameworkLintTripwire( '/interpolated.php' );

		$patcher( '/interpolated.php', 'Prefix', '<?php $y = "domain wp-framework-x for {$name}";' );
	}

	#[Test]
	public function trips_on_escape_obfuscated_reserved_occurrence_in_heredoc(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectFrameworkLintTripwire( '/heredoc-obfuscated.php' );

		$patcher( '/heredoc-obfuscated.php', 'Prefix', "<?php \$x = <<<EOT\n\\x77p-framework-core here\nEOT;\n" );
	}

	#[Test]
	public function ignores_reserved_occurrences_inside_comments(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php\n// domain is 'wp-framework-bootstrap' here\n/** @see wp-framework-core */\n\$domain = 'wp-framework-bootstrap';";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "// domain is 'wp-framework-bootstrap' here", $output );
		self::assertStringContainsString( '/** @see wp-framework-core */', $output );
		self::assertStringContainsString( "\$domain = 'my-plugin';", $output );
	}

	#[Test]
	public function emits_target_via_var_export_single_quoted(): void {
		$patcher = $this->getTextDomainPatcher( 'linked-orders-for-woocommerce' );

		$output = $patcher( '/file.php', 'Prefix', "<?php \\__( 'Hello', 'wp-framework-bootstrap' );" );

		self::assertStringContainsString( "'linked-orders-for-woocommerce'", $output );
	}

	#[Test]
	public function non_php_content_is_called_but_inert(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$json = '{"name":"ahegyes/wp-framework-core","require":{"ahegyes/wp-framework-bootstrap":"^2.0"}}';

		self::assertSame( $json, $patcher( '/composer.json', 'Prefix', $json ) );
	}

	#[Test]
	public function throws_when_text_domain_absent(): void {
		$this->installFrameworkPackage();
		$this->writeComposerJson( array( 'other-key' => 'value' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/"extra\.text-domain" is missing or empty/' );

		( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );
	}

	#[Test]
	public function throws_when_text_domain_is_empty(): void {
		$this->installFrameworkPackage();
		$this->writeComposerJson( array( 'text-domain' => '' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/"extra\.text-domain" is missing or empty/' );

		( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );
	}

	#[Test]
	public function throws_when_composer_json_missing(): void {
		$this->installFrameworkPackage();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/no composer\.json exists/' );

		( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );
	}

	#[Test]
	public function explicit_false_opts_out_of_the_textdomain_rewrite(): void {
		$this->installFrameworkPackage();
		$this->writeComposerJson( array( 'text-domain' => false ) );

		$config = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );

		self::assertCount( 1, $config['patchers'] );
		$input = "<?php \\__( 'Hello', 'wp-framework-bootstrap' );";
		self::assertSame( $input, $config['patchers'][0]( '/file.php', 'Prefix', $input ) );
	}

	#[Test]
	public function no_patcher_when_no_framework_packages_installed(): void {
		$this->writeComposerJson( array( 'text-domain' => 'my-plugin' ) );

		$config = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );

		self::assertSame( array(), $config['patchers'] );
	}

	#[Test]
	public function throws_on_invalid_text_domain_slug(): void {
		$this->installFrameworkPackage();
		$this->writeComposerJson( array( 'text-domain' => 'Not A Slug!' ) );

		$this->expectException( \RuntimeException::class );

		( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );
	}

	#[Test]
	public function accepts_text_domain_slug_with_underscores(): void {
		$patcher = $this->getTextDomainPatcher( 'my_plugin_domain' );

		$output = $patcher( '/file.php', 'Prefix', "<?php \\__( 'Hello', 'wp-framework-core' );" );

		self::assertStringContainsString( "'my_plugin_domain'", $output );
	}

	#[Test]
	public function throws_on_malformed_composer_json(): void {
		$this->installFrameworkPackage();
		\file_put_contents( $this->project_dir . '/composer.json', '{ "extra": { not valid json' );

		$this->expectException( \JsonException::class );

		( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );
	}

	private function installFrameworkPackage(): void {
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core', 0755, true );
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-bootstrap', 0755, true );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function writeComposerJson( array $extra ): void {
		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode( array( 'extra' => $extra ), JSON_THROW_ON_ERROR )
		);
	}

	private function getTextDomainPatcher( string $target ): callable {
		$this->installFrameworkPackage();
		$this->writeComposerJson( array( 'text-domain' => $target ) );

		$config = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );

		self::assertArrayHasKey( 0, $config['patchers'], 'textdomain patcher should be present' );
		return $config['patchers'][0];
	}

	private function expectFrameworkLintTripwire( string $file_path ): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/' . \preg_quote( $file_path, '/' ) . '.*outside the framework lint guarantee.*WPCS I18n text_domain/' );
	}

	private function rrmdir( string $dir ): void {
		// SAFETY: never follow symlinks; is_dir() returns true for symlink-to-dir.
		if ( \is_link( $dir ) ) {
			\unlink( $dir );
			return;
		}
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		foreach ( \scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( \is_link( $path ) ) {
				\unlink( $path );
			} elseif ( \is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				\unlink( $path );
			}
		}
		\rmdir( $dir );
	}
}
