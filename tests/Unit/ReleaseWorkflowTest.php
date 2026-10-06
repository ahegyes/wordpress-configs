<?php declare( strict_types=1 );

namespace WordPressConfigs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WordPressConfigs\Tests\Support\RunsWorkflowSteps;

final class ReleaseWorkflowTest extends TestCase {

	use RunsWorkflowSteps;

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function provide_entry_files(): array {
		return array(
			'root-file'      => array( 'main.php', 0 ),
			'dot-prefixed'   => array( './main.php', 1 ),
			'in-a-subfolder' => array( 'src/main.php', 1 ),
		);
	}

	#[DataProvider( 'provide_entry_files' )]
	public function test_entry_file_must_be_a_file_at_the_project_root( string $entry_file, int $expected_status ): void {
		$result = $this->run_build_step(
			'Enforce release inputs',
			array(
				'ENTRY_FILE'   => $entry_file,
				'REF_TYPE'     => 'branch',
				'REF_NAME'     => 'trunk',
				'PUBLISH'      => 'false',
				'PROJECT_PATH' => '.',
			)
		);

		self::assertSame( $expected_status, $result['status'], $result['output'] );
	}

	/**
	 * @return array<string, array{string, string, ?string, int}>
	 */
	public static function provide_versions(): array {
		return array(
			'tag-header-and-readme-agree' => array( '1.2.3', 'true', '1.2.3', 0 ),
			'github-build-without-readme' => array( '1.2.3', 'false', null, 0 ),
			'dry-run-without-tag'         => array( '', 'false', null, 0 ),
			'tag-differs-from-header'     => array( '1.2.4', 'false', null, 1 ),
			'readme-differs-from-header'  => array( '1.2.3', 'false', '1.2.2', 1 ),
			'wp-org-build-without-readme' => array( '1.2.3', 'true', null, 1 ),
		);
	}

	#[DataProvider( 'provide_versions' )]
	public function test_versions_must_agree( string $tag_version, string $wp_org, ?string $stable_tag, int $expected_status ): void {
		$files = array( 'main.php' => self::plugin_header( array( 'Version' => '1.2.3' ) ) );
		if ( ! \is_null( $stable_tag ) ) {
			$files['readme.txt'] = "=== Acme Plugin ===\nStable tag: $stable_tag\n";
		}
		$this->write_project( $files );

		$result = $this->run_build_step(
			'Verify version consistency',
			array(
				'TAG_VERSION' => $tag_version,
				'WP_ORG'      => $wp_org,
			)
		);

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( 0 === $expected_status ) {
			self::assertSame( '1.2.3', $result['outputs']['version'] ?? null );
		}
	}

	/**
	 * @return array<string, array{string, ?string, int}>
	 */
	public static function provide_text_domains(): array {
		return array(
			'header-and-composer-match-the-slug' => array( 'acme-plugin', 'acme-plugin', 0 ),
			'no-composer-text-domain'            => array( 'acme-plugin', null, 0 ),
			'header-differs-from-the-slug'       => array( 'acme', null, 1 ),
			'composer-differs-from-the-slug'     => array( 'acme-plugin', 'acme', 1 ),
		);
	}

	#[DataProvider( 'provide_text_domains' )]
	public function test_text_domain_must_equal_the_slug( string $header, ?string $composer, int $expected_status ): void {
		$extra = \is_null( $composer ) ? array() : array( 'text-domain' => $composer );
		$this->write_project(
			array(
				'main.php'      => self::plugin_header( array( 'Text Domain' => $header ) ),
				'composer.json' => (string) \json_encode( array( 'extra' => (object) $extra ) ),
			)
		);

		$result = $this->run_build_step( 'Verify the text domain', array() );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function provide_pot_files(): array {
		return array(
			'pot-references-the-scoped-strings' => array( "#: vendor-prefixed/acme/library/src/strings.php:3\nmsgid \"Hello\"\nmsgstr \"\"\n", 0 ),
			'pot-misses-the-scoped-strings'     => array( "#: main.php:12\nmsgid \"Hello\"\nmsgstr \"\"\n", 1 ),
		);
	}

	#[DataProvider( 'provide_pot_files' )]
	public function test_pot_must_cover_the_scoped_strings( string $pot, int $expected_status ): void {
		$this->write_project(
			array(
				'vendor-prefixed/acme/library/src/strings.php' => "<?php\n\n__( 'Hello', 'acme-plugin' );\n",
				'languages/acme-plugin.pot' => $pot,
			)
		);

		$result = $this->run_build_step( 'Verify the POT covers the scoped strings', array( 'REQUIRES_SCOPED' => 'true' ) );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
	}

	/**
	 * @return array<string, array{array<string, string>, array<string, array<string, mixed>>, int, string}>
	 */
	public static function provide_header_floors(): array {
		$framework = array(
			'require' => array( 'php' => '>=8.5' ),
			'extra'   => array(
				'requires-wp'      => '7.1',
				'requires-plugins' => array( 'woocommerce' ),
			),
		);

		return array(
			'headers-cover-every-package'               => array(
				array(
					'Requires PHP'      => '8.5',
					'Requires at least' => '7.1',
					'Requires Plugins'  => 'akismet, woocommerce',
				),
				array( 'acme/framework' => $framework ),
				0,
				'',
			),
			'bootstrap-only-plugin-keeps-its-own-floor' => array(
				array(
					'Requires PHP'      => '7.4',
					'Requires at least' => '6.8',
				),
				array( 'acme/bootstrap' => array( 'require' => array( 'php' => '^7.4 || ^8.0' ) ) ),
				0,
				'',
			),
			'php-header-below-a-scoped-package'         => array(
				array(
					'Requires PHP'      => '8.4',
					'Requires at least' => '7.1',
					'Requires Plugins'  => 'woocommerce',
				),
				array( 'acme/framework' => $framework ),
				1,
				'acme/framework',
			),
			'wordpress-header-below-a-package'          => array(
				array(
					'Requires PHP'      => '8.5',
					'Requires at least' => '7.0',
					'Requires Plugins'  => 'woocommerce',
				),
				array( 'acme/framework' => $framework ),
				1,
				'acme/framework',
			),
			'required-plugin-missing-from-header'       => array(
				array(
					'Requires PHP'      => '8.5',
					'Requires at least' => '7.1',
				),
				array( 'acme/framework' => $framework ),
				1,
				'woocommerce',
			),
			'constraint-forms-composer-reads'           => array(
				array( 'Requires PHP' => '7.4' ),
				array(
					'acme/range'   => array( 'require' => array( 'php' => '7.4 - 8.3' ) ),
					'acme/preview' => array( 'require' => array( 'php' => '>=7.2.0-dev' ) ),
				),
				0,
				'',
			),
			'php-header-below-a-vendor-package'         => array(
				array( 'Requires PHP' => '7.1' ),
				array(),
				1,
				'acme/runtime',
			),
		);
	}

	/**
	 * @param array<string, string>               $headers
	 * @param array<string, array<string, mixed>> $scoped
	 */
	#[DataProvider( 'provide_header_floors' )]
	public function test_headers_must_cover_the_shipped_packages( array $headers, array $scoped, int $expected_status, string $named ): void {
		$files = array(
			'main.php'                       => self::plugin_header( $headers ),
			'vendor/composer/installed.json' => (string) \json_encode(
				array(
					'packages'          => array(
						array(
							'name'    => 'acme/runtime',
							'require' => array( 'php' => '>=7.2' ),
						),
						array(
							'name'    => 'phpunit/phpunit',
							'require' => array( 'php' => '>=8.6' ),
						),
					),
					'dev'               => true,
					'dev-package-names' => array( 'phpunit/phpunit' ),
				)
			),
		);
		foreach ( $scoped as $name => $manifest ) {
			$files[ "vendor-prefixed/$name/composer.json" ] = (string) \json_encode( array( 'name' => $name ) + $manifest );
		}
		$this->write_project( $files );

		$result = $this->run_build_step( 'Verify the headers cover the shipped packages', array() );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( '' !== $named ) {
			self::assertMatchesRegularExpression( '/^::error::.*' . \preg_quote( $named, '/' ) . '/m', $result['output'] );
		}
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function provide_archive_entries(): array {
		return array(
			'plugin-files-only'     => array( 'src/Plugin.php', 0 ),
			'tests-ruleset'         => array( 'phpcs.tests.dist.xml', 1 ),
			'hidden-tests-ruleset'  => array( '.phpcs.tests.xml', 1 ),
			'dist-suffixed-ruleset' => array( 'phpcs.xml.dist', 1 ),
		);
	}

	#[DataProvider( 'provide_archive_entries' )]
	public function test_archive_must_not_ship_development_files( string $entry, int $expected_status ): void {
		$stage = $this->work_dir . '/stage/acme-plugin';
		\mkdir( \dirname( "$stage/$entry" ), 0777, true );
		\file_put_contents( "$stage/acme-plugin.php", "<?php\n" );
		\file_put_contents( "$stage/$entry", "<?php\n" );
		\exec( \sprintf( 'cd %s && zip -qr %s acme-plugin', \escapeshellarg( \dirname( $stage ) ), \escapeshellarg( $this->work_dir . '/project/acme-plugin.zip' ) ), $output, $zip_status );
		self::assertSame( 0, $zip_status );

		$result = $this->run_build_step( 'Assert forbidden entries absent', array() );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
	}

	public function test_required_files_are_found_in_a_large_archive(): void {
		$stage = $this->work_dir . '/stage/acme-plugin';
		\mkdir( "$stage/assets", 0777, true );
		\file_put_contents( "$stage/main.php", "<?php\n" );
		for ( $i = 0; $i < 2500; $i++ ) {
			\file_put_contents( \sprintf( '%s/assets/%s-%04d.txt', $stage, \str_repeat( 'a', 40 ), $i ), '' );
		}
		$zip = \escapeshellarg( $this->work_dir . '/project/acme-plugin.zip' );
		\exec( \sprintf( 'cd %s && zip -q %s acme-plugin/main.php && zip -qr %s acme-plugin/assets', \escapeshellarg( \dirname( $stage ) ), $zip, $zip ), $output, $zip_status );
		self::assertSame( 0, $zip_status );

		$result = $this->run_build_step( 'Assert required files present', array( 'WP_ORG' => 'false' ) );

		self::assertSame( 0, $result['status'], $result['output'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_wp_env_config_files(): array {
		return array(
			'default-config' => array( '', 'start' ),
			'named-config'   => array( '.wp-env.tests.json', '--config .wp-env.tests.json start' ),
		);
	}

	#[DataProvider( 'provide_wp_env_config_files' )]
	public function test_wp_env_gets_a_config_flag_only_for_a_named_file( string $config_file, string $expected_arguments ): void {
		$this->write_project( array( 'node_modules/.bin/wp-env' => "#!/usr/bin/env bash\necho \"\$*\" > wp-env-arguments\n" ) );
		\chmod( $this->work_dir . '/project/node_modules/.bin/wp-env', 0755 );

		$result = $this->run_step( 'reusable-release.yml', 'test', 'Start wp-env', array( 'WP_ENV_CONFIG_FILE' => $config_file ) );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( "$expected_arguments\n", \file_get_contents( $this->work_dir . '/project/wp-env-arguments' ) );
	}

	public function test_artifact_redirect_defaults_to_the_standard_config(): void {
		$this->write_project( array( '.wp-env.json' => (string) \json_encode( array( 'mappings' => array( 'wp-content/plugins/acme-plugin' => '.' ) ) ) ) );

		$result = $this->run_step(
			'reusable-release.yml',
			'test',
			'Redirect wp-env at built artifact',
			array(
				'PLUGIN_SLUG'        => 'acme-plugin',
				'WP_ENV_CONFIG_FILE' => '',
				'BUILT_PATH'         => '/built/acme-plugin',
			)
		);

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertStringContainsString( '/built/acme-plugin', (string) \file_get_contents( $this->work_dir . '/project/.wp-env.override.json' ) );
	}

	/**
	 * @param array<string, string> $env
	 *
	 * @return array{status: int, output: string, outputs: array<string, string>}
	 */
	protected function run_build_step( string $name, array $env ): array {
		return $this->run_step(
			'reusable-release.yml',
			'build',
			$name,
			$env + array(
				'PLUGIN_SLUG' => 'acme-plugin',
				'ENTRY_FILE'  => 'main.php',
			)
		);
	}

	/**
	 * @param array<string, string> $headers
	 */
	protected static function plugin_header( array $headers ): string {
		$header = "<?php\n/**\n * Plugin Name: Acme Plugin\n";
		foreach ( $headers as $name => $value ) {
			$header .= " * $name: $value\n";
		}

		return $header . " */\n";
	}
}
