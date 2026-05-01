<?php declare( strict_types = 1 );

namespace DeepWebSolutions\Config\Composer;

use PhpParser\Node;

/**
 * Composer post-autoload-dump hook. Walks `vendor/<vendor>/<package>/composer.json`
 * + the project root, reads each `extra.scoping-stubs` array, parses every
 * referenced stubs file (path convention: `vendor/<vendor>/<package>/<package>.php`),
 * and writes the unioned class/function symbol set to `scoping-exclusions.json`.
 *
 * Declaration format:
 *
 *     "extra": {
 *         "scoping-stubs": ["php-stubs/wordpress-stubs"]
 *     }
 *
 * The php-scoper base config reads the output JSON into its `exclude-classes`
 * and `exclude-functions` keys.
 */
class CollectScopingStubs {
	/**
	 * @throws  \JsonException If JSON parsing or encoding fails.
	 */
	public static function postAutoloadDump( \Composer\Script\Event $event ): void {
		$console_io  = $event->getIO();
		$vendor_dir  = $event->getComposer()->getConfig()->get( 'vendor-dir' );
		$project_dir = dirname( $vendor_dir );

		if ( ! $event->isDevMode() ) {
			$console_io->write( 'Not collecting scoping stubs due to not being in dev mode.' );
			return;
		}
		if ( getenv( 'CI' ) ) {
			$console_io->write( 'Not collecting scoping stubs due to environment config.' );
			return;
		}

		$declared = self::collect_declarations( $project_dir, $vendor_dir );

		$classes   = array();
		$functions = array();

		if ( ! empty( $declared ) ) {
			$parser = new \PhpParser\ParserFactory()->createForVersion( \PhpParser\PhpVersion::fromComponents( 7, 2 ) );

			foreach ( $declared as $package ) {
				$stubs_path = self::resolve_stubs_path( $vendor_dir, $package );
				if ( ! is_file( $stubs_path ) ) {
					$console_io->write( sprintf( 'Skipping declared stubs package "%s" — file not found at %s.', $package, $stubs_path ) );
					continue;
				}

				$visitor = new _stubsNodeVisitor();
				new \PhpParser\NodeTraverser( $visitor )->traverse( $parser->parse( file_get_contents( $stubs_path ) ) );

				$classes   = array_merge( $classes, $visitor->classes );
				$functions = array_merge( $functions, $visitor->functions );
			}
		}

		$output_dir  = getenv( 'SCOPING_EXCLUSIONS_OUTPUT_DIR' ) ?: $project_dir;
		$output_file = getenv( 'SCOPING_EXCLUSIONS_OUTPUT_FILE' ) ?: 'scoping-exclusions.json';
		file_put_contents(
			"$output_dir/$output_file",
			json_encode( array(
				'classes'   => array_values( array_unique( $classes ) ),
				'functions' => array_values( array_unique( $functions ) ),
			), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
	}

	/**
	 * @return list<string>
	 *
	 * @throws \JsonException If a composer.json file cannot be parsed.
	 */
	private static function collect_declarations( string $project_dir, string $vendor_dir ): array {
		$declared = self::read_declaration( $project_dir . '/composer.json' );

		if ( is_dir( $vendor_dir ) ) {
			// Each installed package's composer.json sits at vendor/<vendor>/<package>/composer.json.
			// Symfony Finder's depth filter is 0-indexed (0 = files directly inside the search root).
			$finder = \Symfony\Component\Finder\Finder::create()
				->in( $vendor_dir )
				->files()
				->name( 'composer.json' )
				->depth( 2 )
				->followLinks();

			foreach ( $finder as $file ) {
				$declared = array_merge( $declared, self::read_declaration( $file->getPathname() ) );
			}
		}

		return array_values( array_unique( $declared ) );
	}

	/**
	 * @return list<string>
	 *
	 * @throws \JsonException If the file exists but cannot be parsed.
	 */
	private static function read_declaration( string $composer_json_path ): array {
		if ( ! is_file( $composer_json_path ) ) {
			return array();
		}
		$data     = json_decode( file_get_contents( $composer_json_path ), true, 512, JSON_THROW_ON_ERROR );
		$declared = $data['extra']['scoping-stubs'] ?? array();

		return is_array( $declared ) ? array_values( array_filter( $declared, 'is_string' ) ) : array();
	}

	/**
	 * Resolves a stubs package name (e.g. "php-stubs/wordpress-stubs") to the
	 * path of its stubs file by convention.
	 */
	private static function resolve_stubs_path( string $vendor_dir, string $package ): string {
		$parts = explode( '/', $package );
		if ( 2 !== count( $parts ) ) {
			return '';
		}
		return $vendor_dir . '/' . $package . '/' . $parts[1] . '.php';
	}
}

class _stubsNodeVisitor extends \PhpParser\NodeVisitorAbstract {
	protected(set) array $classes = array();
	protected(set) array $functions = array();

	/**
	 * @{inheritDoc}
	 */
	public function enterNode( Node $node ): int|null {
		switch ( get_class( $node ) ) {
			case Node\Stmt\Class_::class:
				$this->classes[] = $node->name->name;
				return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
			case Node\Stmt\Function_::class:
				$this->functions[] = $node->name->name;
				break;
		}

		return null;
	}
}
