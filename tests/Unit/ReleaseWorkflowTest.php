<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Tests\Support\RunsWorkflowStepsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowTest extends TestCase {
	use RunsWorkflowStepsTrait;

	/**
	 * @return array<string, array{array<string, string>, ?string, string}>
	 */
	public static function provide_main_plugin_files(): array {
		$header = self::plugin_header( array() );

		return array(
			'named-after-the-slug'      => array(
				array(
					'acme-plugin.php' => $header,
					'uninstall.php'   => "<?php\n",
				),
				'acme-plugin.php',
				'',
			),
			'named-apart-from-the-slug' => array( array( 'main.php' => $header ), 'main.php', '' ),
			'no-header'                 => array( array( 'uninstall.php' => "<?php\n" ), null, 'none' ),
			'two-headers'               => array(
				array(
					'main.php'  => $header,
					'other.php' => $header,
				),
				null,
				'main.php other.php',
			),
			'header-in-a-subfolder'     => array( array( 'src/main.php' => $header ), null, 'none' ),
			'header-past-8-kb'          => array( array( 'main.php' => "<?php\n" . \str_repeat( "//\n", 3000 ) . "/**\n * Plugin Name: Acme Plugin\n */\n" ), null, 'none' ),
		);
	}

	/**
	 * @param array<string, string> $files
	 */
	#[DataProvider( 'provide_main_plugin_files' )]
	public function test_the_main_plugin_file_is_the_one_root_php_file_with_a_plugin_header( array $files, ?string $expected, string $named ): void {
		$this->write_project( $files );

		$result = $this->run_build_step( 'Find the main plugin file', array() );

		if ( \is_null( $expected ) ) {
			self::assertSame( 1, $result['status'], $result['output'] );
			self::assertMatchesRegularExpression( '/^::error::.*' . \preg_quote( $named, '/' ) . '/m', $result['output'] );
			self::assertSame( array(), $result['env'] );
			return;
		}

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( array( 'ENTRY_FILE' => $expected ), $result['env'] );
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
	public static function provide_project_floors(): array {
		return array(
			'header-meets-the-project-floor' => array( '8.5', 0 ),
			'header-below-the-project-floor' => array( '7.4', 1 ),
		);
	}

	#[DataProvider( 'provide_project_floors' )]
	public function test_headers_must_cover_the_project_s_own_floor( string $requires_php, int $expected_status ): void {
		$this->write_project(
			array(
				'main.php'      => self::plugin_header( array( 'Requires PHP' => $requires_php ) ),
				'composer.json' => (string) \json_encode(
					array(
						'name'    => 'acme/plugin',
						'require' => array( 'php' => '>=8.5' ),
					)
				),
			)
		);

		$result = $this->run_build_step( 'Verify the headers cover the shipped packages', array() );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( 1 === $expected_status ) {
			self::assertMatchesRegularExpression( '/^::error::.*acme\/plugin/m', $result['output'] );
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
			'dist-named-phpunit'    => array( 'phpunit.dist.xml', 1 ),
			'node-modules'          => array( 'node_modules/x', 1 ),
			'secret-env-file'       => array( '.env', 1 ),
			'nested-auth-json'      => array( 'nested/auth.json', 1 ),
			'bundled-certificate'   => array( 'vendor/acme/ca.pem', 0 ),
		);
	}

	#[DataProvider( 'provide_archive_entries' )]
	public function test_archive_must_not_ship_development_files( string $entry, int $expected_status ): void {
		$this->write_archive( array( 'acme-plugin.php', $entry ) );

		$result = $this->run_build_step( 'Assert forbidden entries absent', array() );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
	}

	/**
	 * @return array<string, array{list<string>, bool, int, string}>
	 */
	public static function provide_scoped_archives(): array {
		$complete = array( 'acme-plugin.php', 'vendor-prefixed/scoper-autoload.php', 'vendor-prefixed/acme/library/src/Client.php' );

		return array(
			'every-scoped-file-shipped' => array( $complete, true, 0, '' ),
			'autoloader-missing'        => array( array( 'acme-plugin.php', 'vendor-prefixed/acme/library/src/Client.php' ), true, 1, 'scoper-autoload.php' ),
			'scoped-package-missing'    => array( array( 'acme-plugin.php', 'vendor-prefixed/scoper-autoload.php' ), true, 1, 'vendor-prefixed/acme/library/' ),
			'scoping-never-ran'         => array( $complete, false, 1, 'did not run' ),
		);
	}

	/**
	 * @param list<string> $archived
	 */
	#[DataProvider( 'provide_scoped_archives' )]
	public function test_the_archive_must_ship_every_scoped_package( array $archived, bool $scoped, int $expected_status, string $named ): void {
		$this->write_archive( $archived );
		if ( $scoped ) {
			$this->write_project( array( 'vendor-prefixed/acme/library/composer.json' => '{"name": "acme/library"}' ) );
		}

		$result = $this->run_build_step( 'Assert scoped packages bundled', array() );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( '' !== $named ) {
			self::assertMatchesRegularExpression( '/^::error::.*' . \preg_quote( $named, '/' ) . '/m', $result['output'] );
		}
	}

	/**
	 * @return array<string, array{string, list<array<string, string>>|null, int, string}>
	 */
	public static function provide_provenance_runs(): array {
		$required = '[".github/workflows/tests.yml", ".github/workflows/quality.yml"]';
		$tests    = self::workflow_run( '.github/workflows/tests.yml' );

		return array(
			'every-required-workflow-passed' => array( $required, array( $tests, self::workflow_run( '.github/workflows/quality.yml' ) ), 0, '' ),
			'one-required-workflow-missing'  => array( $required, array( $tests ), 1, '.github/workflows/quality.yml' ),
			'pull-request-run'               => array( $required, array( $tests, self::workflow_run( '.github/workflows/quality.yml', array( 'event' => 'pull_request' ) ) ), 1, '.github/workflows/quality.yml' ),
			'failed-run'                     => array( $required, array( $tests, self::workflow_run( '.github/workflows/quality.yml', array( 'conclusion' => 'failure' ) ) ), 1, '.github/workflows/quality.yml' ),
			'run-of-another-commit'          => array( $required, array( $tests, self::workflow_run( '.github/workflows/quality.yml', array( 'head_sha' => 'def456' ) ) ), 1, '.github/workflows/quality.yml' ),
			'required-workflows-not-a-list'  => array( '".github/workflows/tests.yml"', array(), 1, 'non-empty JSON array' ),
			'actions-api-unavailable'        => array( $required, null, 1, 'Unable to list' ),
		);
	}

	/**
	 * @param list<array<string, string>>|null $runs
	 */
	#[DataProvider( 'provide_provenance_runs' )]
	public function test_a_release_needs_a_successful_push_run_of_every_required_workflow( string $required, ?array $runs, int $expected_status, string $named ): void {
		// Like the runs API, the stub answers only the runs of the head_sha it is asked for, and it filters them through the step's own --jq.
		$this->write_executable( 'bin/gh', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > \"\$HOME/gh-arguments\"\nif [ ! -f \"\$HOME/runs.json\" ]; then exit 1; fi\nwhile [ \$# -gt 0 ]; do\n\tcase \"\$1\" in\n\t\t--raw-field) case \"\$2\" in head_sha=*) sha=\"\${2#head_sha=}\" ;; esac; shift 2 ;;\n\t\t--jq) filter=\"\$2\"; shift 2 ;;\n\t\t*) shift ;;\n\tesac\ndone\njq --arg sha \"\$sha\" '.workflow_runs |= map( select( .head_sha == \$sha ) )' \"\$HOME/runs.json\" | jq -r \"\$filter\"\n" );
		if ( ! \is_null( $runs ) ) {
			\file_put_contents( $this->work_dir . '/runs.json', (string) \json_encode( array( 'workflow_runs' => $runs ) ) );
		}

		$result = $this->run_step(
			'reusable-release.yml',
			'provenance',
			'Verify required workflows passed',
			array(
				'GH_TOKEN'           => 'token',
				'REPOSITORY'         => 'acme/acme-plugin',
				'SHA'                => 'abc123',
				'REQUIRED_WORKFLOWS' => $required,
			)
		);

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( '' !== $named ) {
			self::assertMatchesRegularExpression( '/^::error::.*' . \preg_quote( $named, '/' ) . '/m', $result['output'] );
		}
		if ( \is_file( $this->work_dir . '/gh-arguments' ) ) {
			$arguments = \file( $this->work_dir . '/gh-arguments', \FILE_IGNORE_NEW_LINES ) ?: array();
			self::assertContains( 'repos/acme/acme-plugin/actions/runs', $arguments );
			self::assertContains( 'head_sha=abc123', $arguments );
			self::assertContains( 'status=success', $arguments );
		}
	}

	/**
	 * @return array<string, array{string, string, string, string, string, int, string}>
	 */
	public static function provide_release_inputs(): array {
		return array(
			'version-tag'             => array( 'tag', 'v1.2.3', '.', 'true', 'acme-plugin', 0, '1.2.3' ),
			'tag-with-a-leading-zero' => array( 'tag', 'v1.02.3', '.', 'true', 'acme-plugin', 1, 'no leading zeros' ),
			'branch-with-publish'     => array( 'branch', 'trunk', '.', 'true', 'acme-plugin', 1, 'is not a tag' ),
			'branch-without-publish'  => array( 'branch', 'trunk', 'plugins/acme-plugin', 'false', 'acme-plugin', 0, '' ),
			'dot-dot-path-segment'    => array( 'tag', 'v1.2.3', 'plugins/../acme-plugin', 'true', 'acme-plugin', 1, 'must not contain a .. path segment' ),
			'absolute-path'           => array( 'tag', 'v1.2.3', '/tmp/acme-plugin', 'true', 'acme-plugin', 1, 'must be relative' ),
			'slug-out-of-pattern'     => array( 'tag', 'v1.2.3', '.', 'true', 'Acme_Plugin', 1, 'plugin-slug must match' ),
		);
	}

	#[DataProvider( 'provide_release_inputs' )]
	public function test_a_release_runs_from_a_version_tag_with_a_safe_slug_and_path( string $ref_type, string $ref_name, string $project_path, string $publish, string $slug, int $expected_status, string $expected ): void {
		$result = $this->run_build_step(
			'Enforce release inputs',
			array(
				'REF_TYPE'     => $ref_type,
				'REF_NAME'     => $ref_name,
				'PROJECT_PATH' => $project_path,
				'PUBLISH'      => $publish,
				'PLUGIN_SLUG'  => $slug,
			)
		);

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( 1 === $expected_status ) {
			self::assertMatchesRegularExpression( '/^::error::.*' . \preg_quote( $expected, '/' ) . '/m', $result['output'] );
		} else {
			self::assertSame( array( 'tag-version' => $expected ), $result['outputs'] );
		}
	}

	/**
	 * @return array<string, array{string|null, int, string}>
	 */
	public static function provide_changelogs(): array {
		return array(
			'dated-section'              => array( "# Changelog\n\n## 1.2.0 - 2026-10-29\n\n- Fixed a thing.\n\n## 1.1.0 - 2026-10-01\n\n- An older thing.\n", 0, "\n- Fixed a thing.\n\n" ),
			'bracketed-section'          => array( "## [1.2.0] - 2026-10-29\n\n- Fixed a thing.\n", 0, "\n- Fixed a thing.\n" ),
			'dots-are-not-wildcards'     => array( "## 1a2a0 - 2026-10-29\n\n- Not this version.\n", 1, '' ),
			'no-section-for-the-version' => array( "## 1.1.0 - 2026-10-01\n\n- An older thing.\n", 1, '' ),
			'no-changelog'               => array( null, 0, '' ),
		);
	}

	#[DataProvider( 'provide_changelogs' )]
	public function test_the_release_notes_are_the_version_s_changelog_section( ?string $changelog, int $expected_status, string $notes ): void {
		$this->write_executable( 'bin/gh', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > \"\$HOME/gh-arguments\"\n" );
		if ( ! \is_null( $changelog ) ) {
			$this->write_project( array( 'CHANGELOG.md' => $changelog ) );
		}
		\mkdir( $this->work_dir . '/runner-temp' );

		$result = $this->run_step(
			'reusable-release.yml',
			'release',
			'Create the GitHub release',
			array(
				'GH_TOKEN'          => 'token',
				'CHANGELOG'         => 'CHANGELOG.md',
				'VERSION'           => '1.2.0',
				'RUNNER_TEMP'       => $this->work_dir . '/runner-temp',
				'GITHUB_REPOSITORY' => 'acme/acme-plugin',
				'GITHUB_REF_NAME'   => 'v1.2.0',
				'PLUGIN_SLUG'       => 'acme-plugin',
			)
		);

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		if ( 1 === $expected_status ) {
			self::assertMatchesRegularExpression( '/^::error::.*no section for 1\.2\.0/m', $result['output'] );
			self::assertFileDoesNotExist( $this->work_dir . '/gh-arguments' );
			return;
		}

		$arguments = \file( $this->work_dir . '/gh-arguments', \FILE_IGNORE_NEW_LINES );
		self::assertIsArray( $arguments );
		if ( \is_null( $changelog ) ) {
			self::assertContains( '--generate-notes', $arguments );
			self::assertNotContains( '--notes-file', $arguments );
		} else {
			self::assertContains( '--notes-file', $arguments );
			self::assertSame( $notes, \file_get_contents( $this->work_dir . '/runner-temp/release-notes.md' ) );
		}
	}

	public function test_required_files_are_found_in_a_large_archive(): void {
		$files = array( 'main.php' );
		for ( $i = 0; $i < 2500; $i++ ) {
			$files[] = \sprintf( 'assets/%s-%04d.txt', \str_repeat( 'a', 40 ), $i );
		}
		$this->write_archive( $files );

		$result = $this->run_build_step( 'Assert required files present', array( 'WP_ORG' => 'false' ) );

		self::assertSame( 0, $result['status'], $result['output'] );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function provide_archived_main_files(): array {
		return array(
			'main-file-shipped' => array( 'main.php', 0 ),
			'main-file-missing' => array( 'other.php', 1 ),
		);
	}

	#[DataProvider( 'provide_archived_main_files' )]
	public function test_the_archive_must_ship_the_main_plugin_file( string $archived, int $expected_status ): void {
		$this->write_archive( array( $archived ) );

		$result = $this->run_build_step( 'Assert required files present', array( 'WP_ORG' => 'false' ) );

		self::assertSame( $expected_status, $result['status'], $result['output'] );
	}

	public function test_the_release_tools_install_from_the_lock_in_the_plugin_s_configs(): void {
		$this->write_project(
			array(
				'vendor/ahegyes/wordpress-configs/php/release/composer.json' => '{"require": {"wp-cli/wp-cli": "^2.12"}}',
				'vendor/ahegyes/wordpress-configs/php/release/composer.lock' => '{"packages": []}',
			)
		);
		$this->write_fake_composer();
		\mkdir( $this->work_dir . '/runner-temp' );

		$result = $this->run_build_step( 'Install the release tools', $this->release_tools_env() );

		self::assertSame( 0, $result['status'], $result['output'] );
		$tools = $this->work_dir . '/runner-temp/release-tools';
		self::assertSame( array( "composer install --working-dir=$tools --no-interaction --no-progress --prefer-dist" ), \file( $this->work_dir . '/calls', \FILE_IGNORE_NEW_LINES ) );
		self::assertFileEquals( $this->work_dir . '/project/vendor/ahegyes/wordpress-configs/php/release/composer.lock', "$tools/composer.lock" );
		self::assertSame( "$tools/vendor/bin\n", \file_get_contents( $this->work_dir . '/github-path' ) );
	}

	public function test_a_plugin_that_does_not_require_configs_gets_no_release_tools(): void {
		$this->write_fake_composer();

		$result = $this->run_build_step( 'Install the release tools', $this->release_tools_env() );

		self::assertSame( 1, $result['status'], $result['output'] );
		self::assertMatchesRegularExpression( '/^::error::.*ahegyes\/wordpress-configs.*require-dev/m', $result['output'] );
		self::assertFileDoesNotExist( $this->work_dir . '/calls' );
	}

	public function test_the_archive_command_runs_from_the_release_tools(): void {
		$this->write_executable( 'bin/wp', "#!/usr/bin/env bash\necho \"wp \$*\" >> \"\$HOME/calls\"\n" );

		$result = $this->run_build_step( 'Create plugin archive', array() );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertSame( array( 'wp dist-archive . ./acme-plugin.zip --plugin-dirname=acme-plugin' ), \file( $this->work_dir . '/calls', \FILE_IGNORE_NEW_LINES ) );
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
		$this->write_executable( 'project/node_modules/.bin/wp-env', "#!/usr/bin/env bash\necho \"\$*\" > wp-env-arguments\n" );

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

		$mapping = array( 'wp-content/plugins/acme-plugin' => '/built/acme-plugin' );
		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertJsonStringEqualsJsonString(
			(string) \json_encode(
				array(
					'core'     => null,
					'mappings' => $mapping,
					'env'      => array(
						'development' => array( 'mappings' => $mapping ),
						'tests'       => array( 'mappings' => $mapping ),
					),
				)
			),
			(string) \file_get_contents( $this->work_dir . '/project/.wp-env.override.json' )
		);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_wp_env_ports(): array {
		return array(
			'development-port' => array( '{ "port": 8811, "env": { "development": { "port": 8822 } } }', '8822' ),
			'root-port'        => array( '{ "port": 8811 }', '8811' ),
			'no-port'          => array( '{}', '8888' ),
		);
	}

	#[DataProvider( 'provide_wp_env_ports' )]
	public function test_the_site_is_requested_on_the_config_s_development_port( string $config, string $port ): void {
		$this->write_project( array( '.wp-env.json' => $config ) );
		$this->write_executable( 'bin/curl', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > \"\$HOME/curl-arguments\"\n" );

		$result = $this->run_step( 'reusable-release.yml', 'test', 'Assert the site serves', array( 'WP_ENV_CONFIG_FILE' => '' ) );

		self::assertSame( 0, $result['status'], $result['output'] );
		self::assertContains( "http://localhost:$port/", \file( $this->work_dir . '/curl-arguments', \FILE_IGNORE_NEW_LINES ) ?: array() );
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function provide_expected_versions(): array {
		return array(
			'built-version-is-active'   => array( '1.2.3', 0 ),
			'another-version-is-active' => array( '9.9.9', 1 ),
		);
	}

	#[DataProvider( 'provide_expected_versions' )]
	public function test_the_active_plugin_must_carry_the_built_version( string $expected_version, int $expected_status ): void {
		// Like wp-env's own `run`, the stub consumes the first `--` and appends the arguments after it to the command.
		$this->write_executable( 'project/node_modules/.bin/wp-env', "#!/usr/bin/env bash\nuntil [ \"\$1\" = run ]; do shift; done\nshift 2\ncommand=()\nfor argument in \"\$@\"; do\n\tif [ -z \"\$dashed\" ] && [ \"\$argument\" = -- ]; then dashed=1; continue; fi\n\tcommand+=( \"\$argument\" )\ndone\nexec \"\${command[@]}\"\n" );
		$this->write_executable( 'bin/wp', "#!/usr/bin/env bash\ncase \"\$*\" in\n\t'plugin activate acme-plugin') ;;\n\t'plugin get acme-plugin --field=version') echo 1.2.3 ;;\n\t*) echo \"Error: The '\$3' plugin could not be found.\" >&2; exit 1 ;;\nesac\n" );

		$result = $this->run_step(
			'reusable-release.yml',
			'test',
			'Activate the built artifact',
			array(
				'PLUGIN_SLUG'        => 'acme-plugin',
				'WP_ENV_CONFIG_FILE' => '.wp-env.tests.json',
				'REQUIRES_SCOPED'    => 'false',
				'VERSION'            => $expected_version,
			)
		);

		self::assertSame( $expected_status, $result['status'], $result['output'] );
		self::assertStringContainsString( "Active plugin version: 1.2.3 (expected $expected_version)", $result['output'] );
	}

	/**
	 * @param list<string> $files
	 */
	protected function write_archive( array $files ): void {
		$stage = $this->work_dir . '/stage/acme-plugin';
		foreach ( $files as $file ) {
			if ( ! \is_dir( \dirname( "$stage/$file" ) ) ) {
				\mkdir( \dirname( "$stage/$file" ), 0777, true );
			}
			\file_put_contents( "$stage/$file", "<?php\n" );
		}
		// zip -@ reads the entries in the order given, which keeps the first entry first in a large archive.
		\file_put_contents( $this->work_dir . '/stage/entries', \implode( "\n", \array_map( static fn ( string $file ): string => "acme-plugin/$file", $files ) ) . "\n" );
		\exec( \sprintf( 'cd %s && zip -q %s -@ < entries', \escapeshellarg( $this->work_dir . '/stage' ), \escapeshellarg( $this->work_dir . '/project/acme-plugin.zip' ) ), $output, $zip_status );
		self::assertSame( 0, $zip_status, \implode( "\n", $output ) );
	}

	/**
	 * @param array<string, string> $overrides
	 *
	 * @return array<string, string>
	 */
	protected static function workflow_run( string $path, array $overrides = array() ): array {
		return $overrides + array(
			'path'       => $path,
			'event'      => 'push',
			'conclusion' => 'success',
			'head_sha'   => 'abc123',
		);
	}

	// Answers `composer config vendor-dir` as Composer's default does, and records every other call.
	protected function write_fake_composer(): void {
		$this->write_executable( 'bin/composer', "#!/usr/bin/env bash\nif [ \"\$1\" = config ]; then echo vendor; exit 0; fi\necho \"composer \$*\" >> \"\$HOME/calls\"\n" );
	}

	/**
	 * @return array<string, string>
	 */
	protected function release_tools_env(): array {
		return array( 'RUNNER_TEMP' => $this->work_dir . '/runner-temp' );
	}

	/**
	 * @param array<string, string> $env
	 *
	 * @return array{status: int, output: string, outputs: array<string, string>, env: array<string, string>}
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
