<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContribScoperPartialsTest extends TestCase {

	private const PHP_DI_PARTIAL       = __DIR__ . '/../../php/php-scoper/contrib/php-di.inc.php';
	private const WP_FRAMEWORK_PARTIAL = __DIR__ . '/../../php/php-scoper/contrib/wp-framework.inc.php';
	private const SCOPER_BASE          = __DIR__ . '/../../php/php-scoper/scoper-base.inc.php';

	private string $vendor_dir;

	protected function setUp(): void {
		$this->vendor_dir = \sys_get_temp_dir() . '/dws-wp-configs-contrib-' . \uniqid();
		\mkdir( $this->vendor_dir, 0755, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->vendor_dir );
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
		$scoper_config = ( require self::SCOPER_BASE )( array(
			'project_dir'   => $this->vendor_dir,
			'finders'       => $php_di['finders'],
			'exclude_files' => $php_di['exclude_files'],
		) );

		self::assertContains(
			$this->vendor_dir . '/php-di/php-di/src/Compiler/Template.php',
			$scoper_config['exclude-files']
		);
	}

	// endregion

	// region wp-framework.inc.php

	#[Test]
	public function wp_framework_partial_returns_empty_finders_when_no_packages_installed(): void {
		$factory = require self::WP_FRAMEWORK_PARTIAL;
		$config  = $factory( $this->vendor_dir );

		self::assertSame( array( 'finders' => array() ), $config );
	}

	#[Test]
	public function wp_framework_partial_picks_up_installed_framework_packages(): void {
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core/src', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php', '<?php class Kernel {}' );
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-shared/src', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-shared/src/Result.php', '<?php class Result {}' );

		$factory = require self::WP_FRAMEWORK_PARTIAL;
		$config  = $factory( $this->vendor_dir );

		$paths = $this->finderPaths( $config['finders'][0] );

		self::assertContains( \realpath( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php' ),   $paths );
		self::assertContains( \realpath( $this->vendor_dir . '/ahegyes/wp-framework-shared/src/Result.php' ), $paths );
	}

	#[Test]
	public function wp_framework_partial_excludes_tests_directory(): void {
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
		\mkdir( $this->vendor_dir . '/ahegyes/wp-framework-core/src', 0755, true );
		\file_put_contents( $this->vendor_dir . '/ahegyes/wp-framework-core/src/Kernel.php', '<?php class Kernel {}' );

		$wp_framework  = ( require self::WP_FRAMEWORK_PARTIAL )( $this->vendor_dir );
		$scoper_config = ( require self::SCOPER_BASE )( array(
			'project_dir' => $this->vendor_dir,
			'finders'     => $wp_framework['finders'],
		) );

		self::assertSame( $wp_framework['finders'], $scoper_config['finders'] );
	}

	// endregion

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
