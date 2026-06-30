<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the framework-textdomain rewriter patcher returned by `php-scoper/contrib/wp-framework.inc.php`.
 *
 * The patcher rewrites the framework's per-package `wp-framework-*` text domains (the SOURCE domains
 * framework gettext calls use) to the consumer plugin's `extra.text-domain` at scope time, so framework
 * strings ship under the consumer's domain. The framework never uses a bare `wp-framework` domain.
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
	public function rewrites_framework_domain_to_target(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\__( 'Hello', 'wp-framework-bootstrap' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
	}

	#[Test]
	#[DataProvider( 'gettextCallProvider' )]
	public function rewrites_framework_domain_across_gettext_call_shapes( string $input ): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function gettextCallProvider(): array {
		return array(
			'__'         => array( "<?php \\__( 'Text', 'wp-framework-bootstrap' );" ),
			'_e'         => array( "<?php \\_e( 'Text', 'wp-framework-bootstrap' );" ),
			'esc_html__' => array( "<?php \\esc_html__( 'Text', 'wp-framework-bootstrap' );" ),
			'esc_attr__' => array( "<?php \\esc_attr__( 'Text', 'wp-framework-bootstrap' );" ),
			'esc_html_e' => array( "<?php \\esc_html_e( 'Text', 'wp-framework-bootstrap' );" ),
			'_x'         => array( "<?php \\_x( 'Text', 'Context', 'wp-framework-bootstrap' );" ),
			'_ex'        => array( "<?php \\_ex( 'Text', 'Context', 'wp-framework-bootstrap' );" ),
			'esc_html_x' => array( "<?php \\esc_html_x( 'Text', 'Context', 'wp-framework-bootstrap' );" ),
			'_n'         => array( "<?php \\_n( 'One', 'Many', \$count, 'wp-framework-bootstrap' );" ),
			'_nx'        => array( "<?php \\_nx( 'One', 'Many', \$count, 'Context', 'wp-framework-bootstrap' );" ),
			'_n_noop'    => array( "<?php \\_n_noop( 'One', 'Many', 'wp-framework-bootstrap' );" ),
			'_nx_noop'   => array( "<?php \\_nx_noop( 'One', 'Many', 'Context', 'wp-framework-bootstrap' );" ),
			'double'     => array( '<?php \\__( "Text", "wp-framework-bootstrap" );' ),
		);
	}

	#[Test]
	public function rewrites_per_package_family_domains(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\__( 'a', 'wp-framework-bootstrap' ); \\__( 'b', 'wp-framework-core' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringNotContainsString( 'wp-framework', $output );
		self::assertSame( 2, \substr_count( $output, "'my-plugin'" ) );
	}

	#[Test]
	public function rewrites_reserved_prefix_even_outside_gettext_calls(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		// Policy (locked): the entire `wp-framework-*` literal space is reserved for framework
		// textdomains and is rewritten wherever it appears as a string literal — not only inside
		// gettext calls. Framework source keeps non-i18n strings (hooks, options) off this prefix.
		$input  = "<?php \$domain = 'wp-framework-utilities'; register_thing( 'wp-framework-core' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringNotContainsString( 'wp-framework', $output );
		self::assertSame( 2, \substr_count( $output, "'my-plugin'" ) );
	}

	#[Test]
	public function leaves_non_framework_domain_untouched(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\__( 'Hello', 'some-other-domain' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertSame( $input, $output );
	}

	#[Test]
	public function leaves_bare_wp_framework_untouched(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		// The framework always uses a `wp-framework-*` domain; a bare `wp-framework` is not in the
		// reserved space (the prefix requires the trailing hyphen) and must be left alone.
		$input  = "<?php \$x = 'wp-framework'; \\__( 'Hi', 'wp-framework' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertSame( $input, $output );
	}

	#[Test]
	public function does_not_rewrite_vendor_prefixed_or_unrelated_literals(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		// The prefix is start-anchored: a vendor-qualified package name and a literal that merely
		// contains the prefix (but does not start with it) are both left alone.
		$input  = "<?php \$a = 'ahegyes/wp-framework-core'; \$b = 'my-wp-framework-helper';";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertSame( $input, $output );
	}

	#[Test]
	public function ignores_token_inside_line_comment(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php\n// domain is 'wp-framework-bootstrap' here\n\\__( 'Hi', 'wp-framework-bootstrap' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "// domain is 'wp-framework-bootstrap' here", $output );
		self::assertStringContainsString( "'my-plugin'", $output );
	}

	#[Test]
	public function ignores_token_inside_doc_comment(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php\n/** @see textdomain 'wp-framework-bootstrap' */\n\\__( 'Hi', 'wp-framework-bootstrap' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "@see textdomain 'wp-framework-bootstrap'", $output );
		self::assertStringContainsString( "'my-plugin'", $output );
	}

	#[Test]
	public function emits_target_via_var_export_single_quoted(): void {
		$patcher = $this->getTextDomainPatcher( 'linked-orders-for-woocommerce' );

		$input  = "<?php \\__( 'Hello', 'wp-framework-bootstrap' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'linked-orders-for-woocommerce'", $output );
	}

	#[Test]
	public function reads_target_from_extra_text_domain(): void {
		$patcher = $this->getTextDomainPatcher( 'configured-domain' );

		$output = $patcher( '/file.php', 'Prefix', "<?php \\__( 'x', 'wp-framework-bootstrap' );" );

		self::assertStringContainsString( "'configured-domain'", $output );
	}

	#[Test]
	public function non_php_content_is_called_but_inert(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		// A composer.json blob whose values include a `wp-framework-*` string: it tokenizes as
		// T_INLINE_HTML, so there is no string-literal token to match — returned verbatim.
		$json   = '{"name":"ahegyes/wp-framework-core","require":{"ahegyes/wp-framework-bootstrap":"^2.0"}}';
		$output = $patcher( '/composer.json', 'Prefix', $json );

		self::assertSame( $json, $output );
	}

	#[Test]
	public function no_patcher_when_text_domain_absent(): void {
		$this->installFrameworkPackage();
		$this->writeComposerJson( array( 'other-key' => 'value' ) );

		$config = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );

		self::assertSame( array(), $config['patchers'] );
	}

	#[Test]
	public function no_patcher_when_composer_json_missing(): void {
		$this->installFrameworkPackage();

		$config = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );

		self::assertSame( array(), $config['patchers'] );
	}

	#[Test]
	public function no_patcher_when_no_framework_packages_installed(): void {
		// No framework package in vendor — even with a text domain set, there is nothing to rewrite.
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
	public function throws_on_malformed_composer_json(): void {
		$this->installFrameworkPackage();
		\file_put_contents( $this->project_dir . '/composer.json', '{ "extra": { not valid json' );

		$this->expectException( \JsonException::class );

		( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );
	}

	private function installFrameworkPackage(): void {
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core', 0755, true );
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

	private function rrmdir( string $dir ): void {
		// SAFETY: never follow symlinks — is_dir() returns true for symlink-to-dir.
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
