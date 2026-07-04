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
 * The rewrite is position-aware: only the domain argument of a gettext call is rewritten, and a
 * reserved `wp-framework-*` literal anywhere else fails the scope run. Consumer text-domain metadata
 * is mandatory when framework packages are installed; `"text-domain": false` is the explicit opt-out.
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
	public function throws_on_reserved_literal_in_an_assignment(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		// The `wp-framework-*` literal space is reserved for text domains, and only a gettext
		// call's domain argument is a rewrite site. Anywhere else the literal would have been
		// silently corrupted by a blanket rewrite (a hook name, option key, cache group), so
		// the patcher fails the scope run instead.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \$domain = 'wp-framework-utilities';" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_a_non_gettext_call(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php register_thing( 'wp-framework-core' );" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_a_nested_call_inside_a_gettext_call(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		// The callee stack attributes the literal to its DIRECT enclosing call — a sprintf
		// nested inside __() is not a domain position.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( \\sprintf( 'wp-framework-core' ), 'my-domain' );" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_an_array_literal(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \$map = array( 'domain' => 'wp-framework-core' );" );
	}

	#[Test]
	public function rewrites_domain_in_a_multiline_gettext_call(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\__(\n\t'Text',\n\t'wp-framework-bootstrap'\n);";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
	}

	#[Test]
	public function rewrites_domain_while_a_nested_call_sits_in_an_earlier_argument(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		// The nested sprintf pushes and pops its own callee; by the domain argument the stack
		// top is the gettext call again.
		$input  = "<?php \\__( \\sprintf( '%s!', \$name ), 'wp-framework-utilities' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
	}

	#[Test]
	public function rewrites_unqualified_gettext_call(): void {
		// Scoped framework files may call gettext functions unqualified (T_STRING callee).
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php __( 'Text', 'wp-framework-bootstrap' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
	}

	#[Test]
	public function throws_on_reserved_literal_in_gettext_message_argument(): void {
		// Position-aware, not merely callee-aware: a reserved literal in the MESSAGE argument of
		// a gettext call is not a domain and must not be rewritten into the consumer domain.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( 'wp-framework-core failed', 'wp-framework-bootstrap' );" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_gettext_context_argument(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \\_x( 'T', 'wp-framework-ctx', 'wp-framework-settings' );" );
	}

	#[Test]
	public function throws_on_concatenated_domain_expression(): void {
		// A fragment of a concatenated domain must not be rewritten in isolation.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/compound expression at a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( 'T', 'wp-framework-' . 'x' );" );
	}

	#[Test]
	public function throws_on_concatenated_domain_with_clean_first_fragment(): void {
		// The left fragment is not reserved, but the domain is still runtime-built — throw.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/compound expression at a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( 'T', 'x-' . 'wp-framework-y' );" );
	}

	#[Test]
	public function throws_on_ternary_at_domain_position(): void {
		// The plain-argument rule: a ternary at the domain position carrying reserved literals
		// cannot be rewritten branch-by-branch.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/compound expression at a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( 'T', \$flag ? 'wp-framework-a' : 'wp-framework-b' );" );
	}

	#[Test]
	public function throws_on_arrow_function_at_domain_position(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/compound expression at a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( 'T', fn() => 'wp-framework-a' );" );
	}

	#[Test]
	public function does_not_throw_on_non_reserved_compound_domain(): void {
		// A scoped third-party library may define its own __()/translate(); a compound domain
		// with NO reserved participant must pass through untouched, not fail the consumer's run.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\__( 'T', 'foo' . '-bar' );";
		$output = $patcher( '/third-party.php', 'Prefix', $input );

		self::assertSame( $input, $output );
	}

	#[Test]
	public function throws_on_named_arguments_in_gettext_call(): void {
		// Named-argument labels defeat positional domain detection; reordered labels would let
		// a positional scan silently rewrite the MESSAGE — fail loud on any reserved literal.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/named arguments/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( domain: 'my-domain', text: 'wp-framework-core failed' );" );
	}

	#[Test]
	public function throws_on_named_arguments_even_in_canonical_order(): void {
		// Simple and fail-loud beats label tracking: canonical-order named arguments throw too.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/named arguments/' );

		$patcher( '/file.php', 'Prefix', "<?php \\__( text: 'T', domain: 'wp-framework-bootstrap' );" );
	}

	#[Test]
	public function rewrites_binary_prefixed_domain_literal(): void {
		// A b/B string prefix participates in the rewrite like any plain literal.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$output = $patcher( '/file.php', 'Prefix', "<?php \\__( 'T', b'wp-framework-bootstrap' );" );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
	}

	#[Test]
	public function throws_on_binary_prefixed_reserved_literal_outside_domain_position(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \$x = B'wp-framework-x';" );
	}

	#[Test]
	public function throws_on_hex_escape_obfuscated_reserved_literal(): void {
		// The throw checks match on the DECODED value, so escape obfuscation cannot slip a
		// reserved literal past the fail-loud contract.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', '<?php $x = "\x77p-framework-core";' );
	}

	#[Test]
	public function throws_on_unicode_escape_obfuscated_domain_literal(): void {
		// Decoded-reserved but raw-obfuscated at a plain domain position: flagged for a human
		// rather than silently normalised.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Escape-obfuscated reserved literal/' );

		$patcher( '/file.php', 'Prefix', '<?php \\__( \'T\', "\u{77}p-framework-bootstrap" );' );
	}

	#[Test]
	public function throws_on_uppercase_hex_escape_obfuscated_domain_literal(): void {
		// PHP decodes \X77 identically to \x77; the decoder must not be case-blind to it.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Escape-obfuscated reserved literal/' );

		$patcher( '/file.php', 'Prefix', '<?php \\__( \'T\', "\X77p-framework-core" );' );
	}

	#[Test]
	public function throws_on_escape_obfuscated_reserved_occurrence_in_heredoc(): void {
		// Heredoc bodies decode escapes at runtime, so the reserved-substring check runs on
		// the decoded fragment — obfuscation cannot hide the occurrence.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/heredoc\/nowdoc\/interpolated/' );

		$patcher( '/file.php', 'Prefix', "<?php \$x = <<<EOT\n\\x77p-framework-core here\nEOT;\n" );
	}

	#[Test]
	public function throws_on_escape_obfuscated_reserved_occurrence_in_interpolated_string(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/heredoc\/nowdoc\/interpolated/' );

		$patcher( '/file.php', 'Prefix', '<?php $y = "\x77p-framework-x for {$name}";' );
	}

	#[Test]
	public function does_not_throw_on_escape_shaped_text_in_nowdoc(): void {
		// A nowdoc body never decodes, so \x77p-framework-… stays literal backslash text at
		// runtime — not a reserved occurrence; the raw check must not decode it into one.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \$x = <<<'EOT'\n\\x77p-framework-core here\nEOT;\n";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertSame( $input, $output );
	}

	#[Test]
	public function throws_on_reserved_default_in_gettext_named_function_declaration(): void {
		// `function __( … )` declares, it does not call: the parameter default falls under the
		// ordinary out-of-position rule instead of being silently rewritten.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php function __( \$text, \$domain = 'wp-framework-x' ) {}" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_method_call(): void {
		// A method named like a gettext function is not WP gettext — the literal follows the
		// ordinary out-of-position rule instead of being rewritten.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \$mapper->translate( 'wp-framework-core' );" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_nullsafe_method_call(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php \$mapper?->translate( 'wp-framework-core' );" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_static_call(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php Mapper::translate( 'wp-framework-core' );" );
	}

	#[Test]
	public function throws_on_reserved_literal_in_constructor_call(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/outside a gettext domain position/' );

		$patcher( '/file.php', 'Prefix', "<?php new translate( 'wp-framework-core' );" );
	}

	#[Test]
	public function throws_on_reserved_occurrence_in_interpolated_string(): void {
		// Encapsed fragments cannot be rewritten safely; the fail-loud contract covers them too.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/heredoc\/nowdoc\/interpolated/' );

		$patcher( '/file.php', 'Prefix', '<?php $y = "domain wp-framework-x for {$name}";' );
	}

	#[Test]
	public function throws_on_reserved_occurrence_in_heredoc(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/heredoc\/nowdoc\/interpolated/' );

		$patcher( '/file.php', 'Prefix', "<?php \$x = <<<EOT\nuses wp-framework-core here\nEOT;\n" );
	}

	#[Test]
	public function throws_on_reserved_occurrence_in_nowdoc(): void {
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/heredoc\/nowdoc\/interpolated/' );

		$patcher( '/file.php', 'Prefix', "<?php \$x = <<<'EOT'\nuses wp-framework-core here\nEOT;\n" );
	}

	#[Test]
	public function rewrites_domain_after_long_array_argument_with_commas(): void {
		// Commas inside an array() argument belong to the array's own call frame, so the domain
		// position count is not thrown off.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\translate_nooped_plural( array( 'One', 'Many' ), \$count, 'wp-framework-utilities' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
	}

	#[Test]
	public function rewrites_domain_after_short_array_argument_with_commas(): void {
		// Commas inside a short-array argument sit at square-bracket depth and do not advance
		// the gettext call's argument index.
		$patcher = $this->getTextDomainPatcher( 'my-plugin' );

		$input  = "<?php \\translate_nooped_plural( [ 'One', 'Many' ], \$count, 'wp-framework-utilities' );";
		$output = $patcher( '/file.php', 'Prefix', $input );

		self::assertStringContainsString( "'my-plugin'", $output );
		self::assertStringNotContainsString( 'wp-framework', $output );
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
	public function throws_when_text_domain_absent(): void {
		// Framework packages carry translatable strings, so shipping them without a consumer
		// domain silently breaks i18n — missing metadata fails the scope run.
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
		// Non-plugin consumers (test fixtures with no translation catalog) opt out with
		// `"text-domain": false`; only the always-on Action Scheduler guard remains, which
		// leaves gettext content untouched.
		$this->installFrameworkPackage();
		$this->writeComposerJson( array( 'text-domain' => false ) );

		$config = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );

		self::assertCount( 1, $config['patchers'] );
		$input = "<?php \\__( 'Hello', 'wp-framework-bootstrap' );";
		self::assertSame( $input, $config['patchers'][0]( '/file.php', 'Prefix', $input ) );
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
