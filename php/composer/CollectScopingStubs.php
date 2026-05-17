<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

/**
 * Composer post-autoload-dump hook. Walks `vendor/<vendor>/<package>/composer.json`
 * + the project root, reads each `extra.scoping-stubs` array, parses every stubs
 * file each declared package ships (via its own `autoload.files`, or the
 * `vendor/<vendor>/<package>/<package>.php` convention when autoload.files is
 * absent), and writes the unioned class/function/constant symbol set to
 * `scoping-exclusions.json`.
 *
 * Declaration format:
 *
 *     "extra": {
 *         "scoping-stubs": ["php-stubs/wordpress-stubs"]
 *     }
 *
 * The php-scoper base config reads the output JSON into its `exclude-classes`,
 * `exclude-functions`, and `exclude-constants` keys.
 */
class CollectScopingStubs {
	/**
	 * Composer event handler for `post-autoload-dump`. See class docblock for what it does.
	 *
	 * @param \Composer\Script\Event $event Composer event object.
	 *
	 * @throws \JsonException    If JSON parsing or encoding fails.
	 * @throws \PhpParser\Error  If the PHP parser fails to initialise.
	 * @throws \RuntimeException If a declared stubs file or a vendor composer.json cannot be read, or the output file cannot be written.
	 */
	public static function postAutoloadDump( \Composer\Script\Event $event ): void {
		$console_io  = $event->getIO();
		$vendor_dir  = $event->getComposer()->getConfig()->get( 'vendor-dir' );
		$project_dir = \dirname( \Composer\Factory::getComposerFile() );

		if ( ! $event->isDevMode() ) {
			$console_io->write( 'Not collecting scoping stubs due to not being in dev mode.' );
			return;
		}

		$declared = self::collect_declarations( $project_dir, $vendor_dir );

		$classes   = array();
		$functions = array();
		$constants = array();

		if ( \count( $declared ) > 0 ) {
			$parser = new \PhpParser\ParserFactory()->createForNewestSupportedVersion();

			foreach ( $declared as $package ) {
				$stubs_paths = self::resolve_stubs_paths( $vendor_dir, $package );
				if ( array() === $stubs_paths ) {
					$console_io->write( \sprintf( 'Skipping declared stubs package "%s" — no stubs file found in its autoload.files and the conventional path is absent.', $package ) );
					continue;
				}

				foreach ( $stubs_paths as $stubs_path ) {
					$contents = \file_get_contents( $stubs_path ) ?: throw new \RuntimeException( \sprintf( 'Could not read stubs file %s', $stubs_path ) );
					$parsed   = $parser->parse( $contents );
					if ( null === $parsed ) {
						$console_io->write( \sprintf( 'Skipping stubs file %s — could not parse.', $stubs_path ) );
						continue;
					}

					$visitor   = new class() extends \PhpParser\NodeVisitorAbstract {
						/**
						 * Fully-qualified class names collected from the parsed stubs.
						 *
						 * @var list<string>
						 */
						public array $classes = array();

						/**
						 * Fully-qualified function names collected from the parsed stubs.
						 *
						 * @var list<string>
						 */
						public array $functions = array();

						/**
						 * Fully-qualified constant names collected from the parsed stubs.
						 * Includes both `const FOO = ...;` declarations and `define('FOO', ...)` calls.
						 *
						 * @var list<string>
						 */
						public array $constants = array();

						/**
						 * {@inheritDoc}
						 *
						 * @param \PhpParser\Node $node Node being visited.
						 */
						public function enterNode( \PhpParser\Node $node ): int|null {
							switch ( \get_class( $node ) ) {
								// Class-like declarations all flow into `$classes` — php-scoper's `exclude-classes` covers all of them.
								case \PhpParser\Node\Stmt\Class_::class:
								case \PhpParser\Node\Stmt\Interface_::class:
								case \PhpParser\Node\Stmt\Trait_::class:
								case \PhpParser\Node\Stmt\Enum_::class:
									if ( null !== $node->namespacedName ) {
										$this->classes[] = $node->namespacedName->toString();
									}
									return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
								case \PhpParser\Node\Stmt\Function_::class:
									if ( null !== $node->namespacedName ) {
										$this->functions[] = $node->namespacedName->toString();
									}
									break;
								case \PhpParser\Node\Stmt\Const_::class:
									foreach ( $node->consts as $const ) {
										if ( null !== $const->namespacedName ) {
											$this->constants[] = $const->namespacedName->toString();
										}
									}
									break;
								case \PhpParser\Node\Expr\FuncCall::class:
									if (
										$node->name instanceof \PhpParser\Node\Name
										&& 'define' === $node->name->toString()
										&& isset( $node->args[0] )
										&& $node->args[0] instanceof \PhpParser\Node\Arg
										&& $node->args[0]->value instanceof \PhpParser\Node\Scalar\String_
									) {
										$this->constants[] = $node->args[0]->value->value;
									}
									break;
							}
							return null;
						}
					};
					$traverser = new \PhpParser\NodeTraverser();
					// NameResolver populates `namespacedName` on Class_, Function_, Const_ — needed for FQCN distinction across namespaced stubs.
					$traverser->addVisitor( new \PhpParser\NodeVisitor\NameResolver() );
					$traverser->addVisitor( $visitor );
					$traverser->traverse( $parsed );

					$classes   = \array_merge( $classes, $visitor->classes );
					$functions = \array_merge( $functions, $visitor->functions );
					$constants = \array_merge( $constants, $visitor->constants );
				}
			}
		}

		$output_dir  = \getenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR' ) ?: \dirname( $vendor_dir );
		$output_file = \getenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE' ) ?: 'scoping-exclusions.json';

		self::write_atomically(
			"$output_dir/$output_file",
			\json_encode(
				array(
					'classes'   => \array_values( \array_unique( $classes ) ),
					'functions' => \array_values( \array_unique( $functions ) ),
					'constants' => \array_values( \array_unique( $constants ) ),
				),
				JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Walks the vendor directory + project root, collecting all
	 * `extra.scoping-stubs` package declarations.
	 *
	 * @param string $project_dir Project root directory.
	 * @param string $vendor_dir  Composer vendor directory.
	 *
	 * @throws \JsonException    If a composer.json file cannot be parsed.
	 * @throws \RuntimeException If a composer.json file exists but cannot be read.
	 *
	 * @return list<string>
	 */
	private static function collect_declarations( string $project_dir, string $vendor_dir ): array {
		$declared = self::read_declaration( $project_dir . '/composer.json' );

		if ( \is_dir( $vendor_dir ) ) {
			// Each installed package's composer.json sits at vendor/<vendor>/<package>/composer.json.
			// Symfony Finder's depth filter is 0-indexed (0 = files directly inside the search root).
			$finder = \Symfony\Component\Finder\Finder::create()
				->in( $vendor_dir )
				->files()
				->name( 'composer.json' )
				->depth( 2 )
				->followLinks();

			foreach ( $finder as $file ) {
				$declared = \array_merge( $declared, self::read_declaration( $file->getPathname() ) );
			}
		}

		return \array_values( \array_unique( $declared ) );
	}

	/**
	 * Reads `extra.scoping-stubs` from a single composer.json file.
	 *
	 * @param string $composer_json_path Path to the composer.json file.
	 *
	 * @return list<string>
	 *
	 * @throws \JsonException    If the file exists but cannot be parsed.
	 * @throws \RuntimeException If the file exists but cannot be read.
	 */
	private static function read_declaration( string $composer_json_path ): array {
		if ( ! \is_file( $composer_json_path ) ) {
			return array();
		}
		$contents = \file_get_contents( $composer_json_path ) ?: throw new \RuntimeException( \sprintf( 'Could not read %s', $composer_json_path ) );
		$data     = \json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );
		$declared = $data['extra']['scoping-stubs'] ?? array();

		return \is_array( $declared ) ? \array_values( \array_filter( $declared, 'is_string' ) ) : array();
	}

	/**
	 * Writes `$payload` to `$output_path` via a temp-file-and-rename so a concurrent
	 * composer run can't read the file mid-write.
	 *
	 * @infection-ignore-all
	 *
	 * @param string $output_path Target path.
	 * @param string $payload     Bytes to write.
	 *
	 * @throws \RuntimeException If the temp file cannot be written or the rename fails.
	 */
	private static function write_atomically( string $output_path, string $payload ): void {
		$temp_path = $output_path . '.tmp.' . \getmypid();
		\file_put_contents( $temp_path, $payload ) ?: throw new \RuntimeException( \sprintf( 'Could not write %s', $temp_path ) );
		\rename( $temp_path, $output_path ) ?: throw new \RuntimeException( \sprintf( 'Could not rename %s to %s', $temp_path, $output_path ) );
	}

	/**
	 * Resolves a stubs package name to its file paths. Reads `autoload.files`
	 * (multi-file catalogs like `php-stubs/woocommerce-stubs`); falls back to
	 * the `<name>/<name>.php` convention for minimal hand-rolled catalogs.
	 *
	 * @param string $vendor_dir Composer vendor directory.
	 * @param string $package    Stubs package name (vendor/package format).
	 *
	 * @throws \JsonException    If the package's composer.json exists but cannot be parsed.
	 * @throws \RuntimeException If the package's composer.json exists but cannot be read.
	 *
	 * @return list<string> Existing-file paths in order; empty if nothing found.
	 */
	private static function resolve_stubs_paths( string $vendor_dir, string $package ): array {
		$parts = \explode( '/', $package );
		if ( 2 !== \count( $parts ) ) {
			return array();
		}

		$package_dir   = $vendor_dir . '/' . $package;
		$composer_json = $package_dir . '/composer.json';

		$candidates = array();

		if ( \is_file( $composer_json ) ) {
			$contents = \file_get_contents( $composer_json ) ?: throw new \RuntimeException( \sprintf( 'Could not read %s', $composer_json ) );
			$data     = \json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );

			$files = $data['autoload']['files'] ?? array();
			if ( \is_array( $files ) ) {
				foreach ( $files as $relative ) {
					if ( \is_string( $relative ) ) {
						$candidates[] = $package_dir . '/' . $relative;
					}
				}
			}
		}

		$candidates[] = $package_dir . '/' . $parts[1] . '.php';

		$existing = array();
		foreach ( $candidates as $candidate ) {
			if ( \is_file( $candidate ) && ! \in_array( $candidate, $existing, true ) ) {
				$existing[] = $candidate;
			}
		}

		return $existing;
	}
}
