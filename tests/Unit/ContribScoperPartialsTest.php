<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContribScoperPartialsTest extends TestCase {

	private const PHP_DI_PARTIAL       = __DIR__ . '/../../php/php-scoper/contrib/php-di.inc.php';
	private const WP_FRAMEWORK_PARTIAL = __DIR__ . '/../../php/php-scoper/contrib/wp-framework.inc.php';
	private const SCOPER_BASE          = __DIR__ . '/../../php/php-scoper/scoper-base.inc.php';

	private string $project_dir;

	private string $vendor_dir;

	protected function setUp(): void {
		// vendor lives under a unique project dir so the wp-framework partial's default
		// `dirname( $vendor_dir )` resolves to an empty project dir (no stray composer.json).
		$this->project_dir = \sys_get_temp_dir() . '/dws-wp-configs-contrib-' . \uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		\mkdir( $this->vendor_dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->project_dir );
	}

	// region php-di.inc.php

	#[Test]
	public function php_di_partial_template_exclusion_propagates_through_scoper_base(): void {
		// Seam test: pipes the partial through scoper-base and verifies the Template.php
		// exclusion reaches php-scoper's `exclude-files` (hyphenated) output. Catches drift
		// where the partial's `exclude_files` key stops matching what scoper-base reads.
		\mkdir( $this->vendor_dir . '/php-di/php-di/src/Compiler', 0755, true );
		\mkdir( $this->vendor_dir . '/laravel/serializable-closure', 0755, true );

		$php_di        = ( require self::PHP_DI_PARTIAL )( $this->vendor_dir );
		$scoper_config = ( require self::SCOPER_BASE )(
			array(
				'project_dir'   => $this->vendor_dir,
				'finders'       => $php_di['finders'],
				'exclude_files' => $php_di['exclude_files'],
			)
		);

		self::assertContains(
			$this->vendor_dir . '/php-di/php-di/src/Compiler/Template.php',
			$scoper_config['exclude-files']
		);
	}

	#[Test]
	public function php_di_partial_returns_no_finders_when_packages_are_not_installed(): void {
		$php_di = ( require self::PHP_DI_PARTIAL )( $this->vendor_dir );

		self::assertSame(
			array(
				'finders'       => array(),
				'exclude_files' => array(),
			),
			$php_di
		);
	}

	// endregion

	// region wp-framework.inc.php

	#[Test]
	public function wp_framework_partial_returns_empty_finders_and_patchers_when_no_packages_installed(): void {
		$factory = require self::WP_FRAMEWORK_PARTIAL;
		$config  = $factory( $this->vendor_dir );

		self::assertSame(
			array(
				'finders'  => array(),
				'patchers' => array(),
			),
			$config
		);
	}

	#[Test]
	public function wp_framework_partial_picks_up_installed_framework_packages(): void {
		$this->writeOptedOutComposerJson();
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core/src', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php', '<?php class Kernel {}' );
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-shared/src', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-shared/src/Result.php', '<?php class Result {}' );

		$factory = require self::WP_FRAMEWORK_PARTIAL;
		$config  = $factory( $this->vendor_dir );

		$paths = $this->finderPaths( $config['finders'][0] );

		self::assertContains( \realpath( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php' ), $paths );
		self::assertContains( \realpath( $this->vendor_dir . '/ahegyes/wp-framework-shared/src/Result.php' ), $paths );
	}

	#[Test]
	public function wp_framework_partial_excludes_tests_directory(): void {
		$this->writeOptedOutComposerJson();
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core/src', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php', '<?php class Kernel {}' );
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core/tests', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-core/tests/KernelTest.php', '<?php class KernelTest {}' );

		$factory = require self::WP_FRAMEWORK_PARTIAL;
		$config  = $factory( $this->vendor_dir );

		$paths = $this->finderPaths( $config['finders'][0] );

		self::assertContains( \realpath( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php' ), $paths );
		self::assertNotContains( \realpath( $this->vendor_dir . '/ahegyes/wp-framework-core/tests/KernelTest.php' ), $paths );
	}

	#[Test]
	public function wp_framework_partial_only_globs_wp_framework_prefix(): void {
		$this->writeOptedOutComposerJson();
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-core/Kernel.php', '<?php class Kernel {}' );
		// Sibling under ahegyes/ that is NOT a framework package — must not be scoped.
		\mkdir( $this->vendor_dir . '/ahegyes/wordpress-configs', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wordpress-configs/SomeFile.php', '<?php class SomeFile {}' );

		$factory = require self::WP_FRAMEWORK_PARTIAL;
		$config  = $factory( $this->vendor_dir );

		$paths = $this->finderPaths( $config['finders'][0] );

		self::assertContains( \realpath( $this->vendor_dir . '/ahegyes/wp-framework-core/Kernel.php' ), $paths );
		self::assertNotContains( \realpath( $this->vendor_dir . '/ahegyes/wordpress-configs/SomeFile.php' ), $paths );
	}

	#[Test]
	public function wp_framework_partial_finders_propagate_through_scoper_base(): void {
		// Seam test: pipes the partial's finders through scoper-base and verifies they
		// land in the final config. Catches drift where the input key for finders renames.
		$this->writeOptedOutComposerJson();
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core/src', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php', '<?php class Kernel {}' );

		$wp_framework  = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir );
		$scoper_config = ( require self::SCOPER_BASE )(
			array(
				'project_dir' => $this->vendor_dir,
				'finders'     => $wp_framework['finders'],
			)
		);

		self::assertSame( $wp_framework['finders'], $scoper_config['finders'] );
	}

	// endregion

	// region wp-framework.inc.php — Action Scheduler guard

	#[Test]
	public function as_guard_throws_on_a_prefixed_action_scheduler_call(): void {
		// A prefixed as_* call in scoped output is an undefined-function fatal at runtime —
		// the guard fails the scope run instead.
		$guard = $this->getAsGuardPatcher();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Prefixed Action Scheduler reference/' );

		$guard( '/file.php', 'Prefix', "<?php \\Prefix\\as_schedule_single_action( 1, 'hook' );" );
	}

	#[Test]
	public function as_guard_throws_on_a_prefixed_relative_action_scheduler_call(): void {
		$guard = $this->getAsGuardPatcher();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Prefixed Action Scheduler reference/' );

		$guard( '/file.php', 'Prefix', "<?php namespace Local; namespace\\Prefix\\as_schedule_single_action( 1, 'hook' );" );
	}

	#[Test]
	public function as_guard_throws_on_a_prefixed_action_scheduler_string_reference(): void {
		// php-scoper also rewrites function-name string literals; the guard decodes the doubled
		// backslashes and catches those too.
		$guard = $this->getAsGuardPatcher();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Prefixed Action Scheduler reference/' );

		$guard( '/file.php', 'Prefix', "<?php \\function_exists( 'Prefix\\\\as_has_scheduled_action' );" );
	}

	#[Test]
	public function as_guard_passes_unprefixed_action_scheduler_calls_verbatim(): void {
		$guard = $this->getAsGuardPatcher();

		$content = "<?php \\as_schedule_recurring_action( 1, 60, 'hook' ); \\function_exists( 'as_has_scheduled_action' );";

		self::assertSame( $content, $guard( '/file.php', 'Prefix', $content ) );
	}

	#[Test]
	public function as_guard_ignores_prefixed_pattern_inside_comments(): void {
		$guard = $this->getAsGuardPatcher();

		$content = "<?php\n// mentions Prefix\\as_schedule_single_action in prose\n/** @see Prefix\\as_supports */\n\\as_supports( 'x' );";

		self::assertSame( $content, $guard( '/file.php', 'Prefix', $content ) );
	}

	#[Test]
	public function as_guard_leaves_other_prefixed_symbols_alone(): void {
		// Only the as_* family is host-pinned by the guard; other prefixed names are the
		// scoper's normal output.
		$guard = $this->getAsGuardPatcher();

		$content = '<?php \\Prefix\\assert_something(); new \\Prefix\\Astronomy();';

		self::assertSame( $content, $guard( '/file.php', 'Prefix', $content ) );
	}

	#[Test]
	public function as_guard_throws_on_a_prefixed_reference_inside_a_heredoc(): void {
		$guard = $this->getAsGuardPatcher();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Prefixed Action Scheduler reference/' );

		$guard( '/file.php', 'Prefix', "<?php \$x = <<<EOT\ncalls Prefix\\as_supports at runtime\nEOT;\n" );
	}

	#[Test]
	public function as_guard_throws_on_a_prefixed_reference_inside_an_interpolated_string(): void {
		$guard = $this->getAsGuardPatcher();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Prefixed Action Scheduler reference/' );

		$guard( '/file.php', 'Prefix', '<?php $s = "call Prefix\\\\as_has_scheduled_action for {$hook}";' );
	}

	#[Test]
	public function as_guard_catches_case_variant_prefixed_call(): void {
		// PHP resolves function and namespace names case-insensitively, so \Prefix\AS_… is
		// the same runtime fatal as \Prefix\as_….
		$guard = $this->getAsGuardPatcher();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Prefixed Action Scheduler reference/' );

		$guard( '/file.php', 'Prefix', "<?php \\Prefix\\AS_enqueue_async_action( 'hook' );" );
	}

	#[Test]
	public function as_guard_throws_on_escape_obfuscated_reference_in_heredoc(): void {
		// Heredoc fragments decode at runtime; \x5C is a backslash, so the decoded body
		// carries a live prefixed reference.
		$guard = $this->getAsGuardPatcher();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Prefixed Action Scheduler reference/' );

		$guard( '/file.php', 'Prefix', "<?php \$x = <<<EOT\ncalls Prefix\\x5Cas_supports\nEOT;\n" );
	}

	#[Test]
	public function as_guard_ignores_double_backslash_reference_in_nowdoc(): void {
		// A nowdoc body never decodes: a doubled backslash stays two literal backslashes at
		// runtime, which is not a live \Prefix\as_* reference — the raw check must not
		// collapse it into one.
		$guard = $this->getAsGuardPatcher();

		$content = "<?php \$x = <<<'EOT'\ncalls Prefix\\\\as_supports here\nEOT;\n";

		self::assertSame( $content, $guard( '/file.php', 'Prefix', $content ) );
	}

	// endregion

	/**
	 * Writes a consumer composer.json with the explicit `"text-domain": false` opt-out, so
	 * finder-focused tests satisfy the partial's mandatory-metadata contract without a domain.
	 */
	private function writeOptedOutComposerJson(): void {
		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode( array( 'extra' => array( 'text-domain' => false ) ), JSON_THROW_ON_ERROR )
		);
	}

	/**
	 * Returns the always-on Action Scheduler guard patcher — the sole patcher under the
	 * text-domain opt-out.
	 */
	private function getAsGuardPatcher(): callable {
		$this->writeOptedOutComposerJson();
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-utilities', 0755, true );

		$config = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir, $this->project_dir );

		self::assertCount( 1, $config['patchers'], 'expected only the Action Scheduler guard patcher' );
		return $config['patchers'][0];
	}

	/**
	 * @param  iterable<\Symfony\Component\Finder\SplFileInfo> $finder
	 *
	 * @return list<string>
	 */
	private function finderPaths( iterable $finder ): array {
		$paths = array();
		foreach ( $finder as $file ) {
			$paths[] = $file->getRealPath();
		}
		return $paths;
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
