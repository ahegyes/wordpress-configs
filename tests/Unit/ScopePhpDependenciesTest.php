<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use DeepWebSolutions\Config\Composer\ScopePhpDependencies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( ScopePhpDependencies::class )]
final class ScopePhpDependenciesTest extends TestCase {

	private string $project_dir;
	private string $vendor_dir;

	protected function setUp(): void {
		$this->project_dir = \sys_get_temp_dir() . '/dws-wp-configs-scope-' . \uniqid();
		$this->vendor_dir  = $this->project_dir . '/vendor';
		\mkdir( $this->vendor_dir, 0755, true );

		\putenv( 'COMPOSER=' . $this->project_dir . '/composer.json' );
	}

	protected function tearDown(): void {
		\putenv( 'COMPOSER' );
		$this->rrmdir( $this->project_dir );
	}

	#[Test]
	public function pre_autoload_dump_creates_missing_scoped_paths(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/scoper-autoload.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/dependencies/scoper-autoload.php' );
	}

	#[Test]
	public function pre_autoload_dump_does_not_create_paths_outside_the_scoped_dir(): void {
		// A typo'd real autoload path must NOT become an empty placeholder — Composer's own dump
		// should fail loudly on it. Only paths under the scoped-dependencies-dir are pre-created.
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/scoper-autoload.php', 'src/boostrap.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertFileDoesNotExist( $this->project_dir . '/src/boostrap.php' );
	}

	#[Test]
	public function pre_autoload_dump_is_a_noop_without_a_scoped_dependencies_dir(): void {
		// A consumer that does not scope has nothing to pre-create.
		$this->writeComposerJson(
			array(
				'autoload' => array( 'files' => array( 'src/missing.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileDoesNotExist( $this->project_dir . '/src/missing.php' );
	}

	#[Test]
	public function pre_autoload_dump_creates_missing_scoped_classmap_directories(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'classmap' => array( 'dependencies/scoped-pkg' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertDirectoryExists( $this->project_dir . '/dependencies/scoped-pkg' );
	}

	#[Test]
	public function pre_autoload_dump_is_idempotent_for_existing_scoped_paths(): void {
		\mkdir( $this->project_dir . '/dependencies', 0755, true );
		\file_put_contents( $this->project_dir . '/dependencies/scoper-autoload.php', "<?php\n// keep this content\n" );

		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/scoper-autoload.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertSame( "<?php\n// keep this content\n", \file_get_contents( $this->project_dir . '/dependencies/scoper-autoload.php' ) );
	}

	#[Test]
	public function pre_autoload_dump_omits_dev_scoped_entries_in_non_dev_mode(): void {
		// Catches Coalesce + UnwrapArrayMerge on the autoload-dev fallback.
		$this->writeComposerJson(
			array(
				'extra'        => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload'     => array( 'files' => array( 'dependencies/prod.php' ) ),
				'autoload-dev' => array( 'files' => array( 'dependencies/dev-only.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: false ) );

		self::assertFileExists( $this->project_dir . '/dependencies/prod.php' );
		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/dev-only.php' );
	}

	#[Test]
	public function pre_autoload_dump_includes_dev_scoped_entries_in_dev_mode(): void {
		$this->writeComposerJson(
			array(
				'extra'        => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload'     => array( 'files' => array( 'dependencies/prod.php' ) ),
				'autoload-dev' => array( 'files' => array( 'dependencies/dev.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: true ) );

		self::assertFileExists( $this->project_dir . '/dependencies/prod.php' );
		self::assertFileExists( $this->project_dir . '/dependencies/dev.php' );
	}

	#[Test]
	public function pre_autoload_dump_handles_composer_json_without_autoload_dev_section(): void {
		// Catches Coalesce on `$composer_config['autoload-dev']['files'] ?? array()`.
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/only-prod.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event( devMode: true ) );

		self::assertFileExists( $this->project_dir . '/dependencies/only-prod.php' );
	}

	#[Test]
	public function post_autoload_dump_skips_in_non_dev_mode(): void {
		// The skip's observable effect is that no scoped autoload is generated — the wording of the
		// informational message is incidental. php-scoper is installed and the scope script is wired,
		// so non-dev mode is the sole reason nothing is produced; in dev mode this same setup writes
		// dependencies/scoper-autoload.php (see post_autoload_dump_runs_scope_script_...).
		$this->installFakePhpScoper();
		\mkdir( $this->project_dir . '/dependencies', 0755, true );
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::succeedingScopeScript' ),
				'extra'   => array(
					'scoped-dependencies-dir' => 'dependencies',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		$io = new BufferIO();
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( $io, devMode: false ) );

		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/scoper-autoload.php' );
		// A skip must surface a notice to the developer; the exact wording is incidental, but some output is required.
		self::assertNotSame( '', $io->getOutput() );
	}

	#[Test]
	public function post_autoload_dump_skips_when_php_scoper_is_not_installed(): void {
		// No fake php-scoper installed: the skip's observable effect is that no scoped autoload is
		// generated, independent of the message wording. Everything else is wired to succeed in dev
		// mode, so the missing php-scoper binary is the sole reason nothing is produced.
		\mkdir( $this->project_dir . '/dependencies', 0755, true );
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::succeedingScopeScript' ),
				'extra'   => array(
					'scoped-dependencies-dir' => 'dependencies',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		$io = new BufferIO();
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( $io ) );

		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/scoper-autoload.php' );
		// A skip must surface a notice to the developer; the exact wording is incidental, but some output is required.
		self::assertNotSame( '', $io->getOutput() );
	}

	#[Test]
	public function pre_autoload_dump_rejects_scoped_dependencies_dir_with_traversal(): void {
		$this->writeComposerJson(
			array(
				'extra' => array( 'scoped-dependencies-dir' => '../escape' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/parent-directory traversal/' );
		ScopePhpDependencies::preAutoloadDump( $this->event() );
	}

	#[Test]
	public function pre_autoload_dump_rejects_absolute_scoped_dependencies_dir(): void {
		$this->writeComposerJson(
			array(
				'extra' => array( 'scoped-dependencies-dir' => '/etc/escape' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/absolute autoload path/' );
		ScopePhpDependencies::preAutoloadDump( $this->event() );
	}

	#[Test]
	public function pre_autoload_dump_rejects_scoped_path_with_parent_traversal(): void {
		// A path that starts under the scoped dir but still escapes via `..` is rejected.
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( 'dependencies/../../escape.php' ) ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/parent-directory traversal/' );
		ScopePhpDependencies::preAutoloadDump( $this->event() );
	}

	#[Test]
	public function post_autoload_dump_runs_raw_script_then_generates_autoload_in_dev_mode(): void {
		// Dev mode + php-scoper present: the scope-php-dependencies:raw script is dispatched (it
		// writes a marker here, standing in for php-scoper populating dependencies/), THEN the
		// scoped autoload is generated. Asserting the marker proves the dispatch actually ran —
		// without a dispatched script the generation alone would still pass, so this guards both steps.
		$this->installFakePhpScoper();
		\mkdir( $this->project_dir . '/dependencies', 0755, true );
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::succeedingScopeScript' ),
				'extra'   => array(
					'scoped-dependencies-dir' => 'dependencies',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );

		// The generated autoload references the package the dispatched script created — proving
		// generation ran AFTER the scope script populated dependencies/, not before.
		$generated = (string) \file_get_contents( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertStringContainsString( 'MyPlugin\\\\Scoped\\\\Acme\\\\Lib', $generated );
	}

	#[Test]
	public function post_autoload_dump_passes_derived_output_dir_to_the_raw_script(): void {
		// Single-source contract: the raw script receives --output-dir derived from
		// extra.scoped-dependencies-dir as a dispatched argument — the flag lives nowhere else.
		$this->installFakePhpScoper();
		\mkdir( $this->project_dir . '/dependencies', 0755, true );
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::argumentRecordingScopeScript' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );

		$recorded = (string) \file_get_contents( $this->project_dir . '/recorded-args.txt' );
		self::assertStringContainsString(
			'--output-dir=' . $this->project_dir . DIRECTORY_SEPARATOR . 'dependencies',
			$recorded
		);
	}

	#[Test]
	public function post_autoload_dump_skips_autoload_generation_without_scoping_prefix(): void {
		// The autoload generator stays opt-in via extra.scoping-prefix; scoping alone completes.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::succeedingScopeScript' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );

		self::assertDirectoryExists( $this->project_dir . '/dependencies/acme/lib' );
		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/scoper-autoload.php' );
	}

	#[Test]
	public function post_autoload_dump_throws_when_raw_script_missing_but_scoping_configured(): void {
		// A declared scoped-dependencies-dir with no raw scoper script is the un-migrated (or
		// half-wired) state; it fails loudly with the wiring instructions instead of silently
		// skipping the scoping the consumer expects.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'extra' => array(
					'scoped-dependencies-dir' => 'dependencies',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Missing the "scope-php-dependencies:raw" script/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_scoped_dir_is_not_declared(): void {
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::succeedingScopeScript' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Missing extra\.scoped-dependencies-dir/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_raw_script_carries_output_dir_flag(): void {
		// The output dir is single-sourced from extra.scoped-dependencies-dir; a stale flag in
		// the raw script would silently lose to the appended derived flag, so it is rejected.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( 'echo add-prefix --output-dir=./elsewhere' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must not carry --output-dir/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_public_script_is_a_raw_scoper_invocation(): void {
		// The pre-split shape: the raw `add-prefix` invocation parked under the public name. A
		// manual run of it would scope without regenerating the autoload, so it is rejected
		// loudly — even when the consumer has not opted into the extras yet.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => array( 'scope-php-dependencies' => '@php vendor/bin/php-scoper add-prefix --output-dir=./dependencies --force' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must run the full pipeline/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_notes_and_skips_when_not_opted_in(): void {
		// Neither the raw script nor the extras: a consumer wiring the hook without scoping.
		$this->installFakePhpScoper();
		$this->writeComposerJson( array( 'name' => 'acme/plain' ) );

		$io = new BufferIO();
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( $io ) );

		self::assertFileDoesNotExist( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertNotSame( '', $io->getOutput() );
	}

	#[Test]
	public function post_autoload_dump_throws_when_raw_script_produces_no_output_dir(): void {
		// The raw script reports success but the derived output dir does not exist afterwards —
		// pipeline breakage that must not degrade into an empty scoped autoload.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::noopScopeScript' ),
				'extra'   => array(
					'scoped-dependencies-dir' => 'dependencies',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/produced no output/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_public_script_is_not_bound_to_run(): void {
		// The positive half of the public-script contract: the binding must exist, or a manual
		// `composer scope-php-dependencies` is undefined while the hooks silently work.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => array( ScopePhpDependencies::RAW_SCOPER_SCRIPT => self::class . '::succeedingScopeScript' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be bound to/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_public_script_aliases_the_raw_script(): void {
		// An alias onto the raw script scopes WITHOUT regenerating the autoload on a manual
		// run — exactly the stale-autoload trap; only the ::run binding is accepted.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => array(
					ScopePhpDependencies::PIPELINE_SCRIPT => '@' . ScopePhpDependencies::RAW_SCOPER_SCRIPT,
					ScopePhpDependencies::RAW_SCOPER_SCRIPT => self::class . '::succeedingScopeScript',
				),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must be bound to/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_short_output_flag_in_raw_script(): void {
		// php-scoper's short alias for --output-dir must not slip past the single-source check.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( 'echo add-prefix -o ./elsewhere' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must not carry --output-dir/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_short_output_flag_equals_form_in_raw_script(): void {
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( 'echo add-prefix -o=./elsewhere' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must not carry --output-dir/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_short_output_flag_glued_form_in_raw_script(): void {
		// Symfony console accepts a value glued directly onto a short flag (-o./dir).
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( 'echo add-prefix -o./elsewhere' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must not carry --output-dir/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_short_output_flag_glued_quoted_form_in_raw_script(): void {
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( "echo add-prefix -o'./elsewhere'" ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must not carry --output-dir/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_short_output_flag_quote_wrapped_form_in_raw_script(): void {
		// The shell strips the quotes, so a quote-wrapped '-o./dir' reaches php-scoper as a
		// live flag; the quote before -o must not hide it from the rejection.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( "echo add-prefix '-o./elsewhere'" ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/must not carry --output-dir/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_throws_when_no_output_produced_even_without_scoping_prefix(): void {
		// The missing-output throw is unconditional — a consumer that has not opted into the
		// autoload generator (no scoping-prefix declared) still gets pipeline breakage loudly.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::noopScopeScript' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/produced no output/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function run_executes_the_full_pipeline(): void {
		// The public scope-php-dependencies entry point scopes AND regenerates the autoload, so
		// a manual scoping run can never leave scoper-autoload.php stale.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::succeedingScopeScript' ),
				'extra'   => array(
					'scoped-dependencies-dir' => 'dependencies',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		ScopePhpDependencies::run( $this->factoryEvent( new BufferIO() ) );

		$generated = (string) \file_get_contents( $this->project_dir . '/dependencies/scoper-autoload.php' );
		self::assertStringContainsString( 'MyPlugin\\\\Scoped\\\\Acme\\\\Lib', $generated );
	}

	#[Test]
	public function run_throws_when_php_scoper_is_not_installed(): void {
		// Unlike the post-autoload-dump hook (which must stay quiet on prod installs), an
		// explicit manual invocation that cannot scope is an error.
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::succeedingScopeScript' ),
				'extra'   => array(
					'scoped-dependencies-dir' => 'dependencies',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/php-scoper is not installed/' );
		ScopePhpDependencies::run( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function pre_autoload_dump_creates_scoped_path_with_leading_dot_slash(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'files' => array( './dependencies/scoper-autoload.php' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertFileExists( $this->project_dir . '/dependencies/scoper-autoload.php' );
	}

	#[Test]
	public function pre_autoload_dump_creates_classmap_dir_equal_to_the_scoped_dir(): void {
		$this->writeComposerJson(
			array(
				'extra'    => array( 'scoped-dependencies-dir' => 'dependencies' ),
				'autoload' => array( 'classmap' => array( 'dependencies' ) ),
			)
		);

		ScopePhpDependencies::preAutoloadDump( $this->event() );

		self::assertDirectoryExists( $this->project_dir . '/dependencies' );
	}

	#[Test]
	public function post_autoload_dump_throws_when_the_raw_script_fails(): void {
		// A raw-scoper callback returning false yields a non-zero dispatch code, which must stop
		// the run before generating the autoload over partial output.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'scripts' => $this->pipelineScripts( self::class . '::failingScopeScript' ),
				'extra'   => array( 'scoped-dependencies-dir' => 'dependencies' ),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/scope-php-dependencies:raw script failed/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_scoped_dependencies_dir_with_traversal(): void {
		// The scoped-dir confinement guards the generation path too, not only preAutoloadDump.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'extra' => array(
					'scoped-dependencies-dir' => '../escape',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/parent-directory traversal/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_rejects_absolute_scoped_dependencies_dir(): void {
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'extra' => array(
					'scoped-dependencies-dir' => '/etc/escape',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/absolute autoload path/' );
		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );
	}

	#[Test]
	public function post_autoload_dump_skips_generation_for_empty_scoped_dependencies_dir(): void {
		// An empty scoped-dependencies-dir must not resolve to the project root.
		$this->installFakePhpScoper();
		$this->writeComposerJson(
			array(
				'extra' => array(
					'scoped-dependencies-dir' => '',
					'scoping-prefix'          => 'MyPlugin\\Scoped',
				),
			)
		);

		ScopePhpDependencies::postAutoloadDump( $this->factoryEvent( new BufferIO() ) );

		self::assertFileDoesNotExist( $this->project_dir . '/scoper-autoload.php' );
	}

	/**
	 * @param array<string, mixed> $contents
	 */
	private function writeComposerJson( array $contents ): void {
		\file_put_contents(
			$this->project_dir . '/composer.json',
			\json_encode( $contents, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	/**
	 * The canonical consumer script wiring: the public pipeline script bound to `run` and the
	 * raw scoper script bound to the given listener.
	 *
	 * @param  string $raw_listener Listener for the raw scoper script.
	 *
	 * @return array<string, string>
	 */
	private function pipelineScripts( string $raw_listener ): array {
		return array(
			ScopePhpDependencies::PIPELINE_SCRIPT   => ScopePhpDependencies::class . '::run',
			ScopePhpDependencies::RAW_SCOPER_SCRIPT => $raw_listener,
		);
	}

	private function event( bool $devMode = true, ?BufferIO $io = null ): Event {
		$composer = new Composer();
		$config   = new Config();
		$config->merge( array( 'config' => array( 'vendor-dir' => $this->vendor_dir ) ) );
		$composer->setConfig( $config );

		return new Event( 'post-autoload-dump', $composer, $io ?? new BufferIO(), $devMode );
	}

	private function installFakePhpScoper(): void {
		\mkdir( $this->vendor_dir . '/bin', 0755, true );
		\file_put_contents( $this->vendor_dir . '/bin/php-scoper', "#!/usr/bin/env php\n" );
	}

	/**
	 * Builds an event from a real Composer the Factory assembles from the test composer.json (the
	 * COMPOSER env var is set in setUp), so postAutoloadDump's `dispatchScript` path — including a
	 * PHP-callback script — runs against a fully-wired Composer.
	 */
	private function factoryEvent( BufferIO $io, bool $devMode = true ): Event {
		// Pass the fixture composer.json explicitly so Composer's base dir is the temp project — its
		// `vendor-dir` then resolves to this test's vendor/ (the fake php-scoper), not the repo's.
		$composer = \Composer\Factory::create( $io, $this->project_dir . '/composer.json', true );

		return new Event( 'post-autoload-dump', $composer, $io, $devMode );
	}

	/** Scope-script stub that signals failure (Composer maps a `false` return to a non-zero code). */
	public static function failingScopeScript(): bool {
		return false;
	}

	/** Scope-script stub that succeeds without producing any output directory. */
	public static function noopScopeScript(): void {
	}

	/** Scope-script stub that records the dispatched script arguments for assertion. */
	public static function argumentRecordingScopeScript( Event $event ): void {
		$project = \dirname( \Composer\Factory::getComposerFile() );
		\file_put_contents( $project . '/recorded-args.txt', \implode( "\n", $event->getArguments() ) );
	}

	/** Scope-script stub: stands in for php-scoper by writing one scoped package into dependencies/. */
	public static function succeedingScopeScript(): void {
		$project = \dirname( \Composer\Factory::getComposerFile() );
		$pkg_dir = $project . '/dependencies/acme/lib';
		\mkdir( $pkg_dir, 0755, true );
		\file_put_contents(
			$pkg_dir . '/composer.json',
			\json_encode(
				array(
					'name'     => 'acme/lib',
					'autoload' => array( 'psr-4' => array( 'MyPlugin\\Scoped\\Acme\\Lib\\' => 'src/' ) ),
				),
				JSON_THROW_ON_ERROR
			)
		);
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
