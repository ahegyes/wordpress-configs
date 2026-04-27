<?php declare( strict_types = 1 );

namespace DeepWebSolutions\Config\Composer;

use PhpParser\Node;

/**
 * Composer post-autoload-dump hook that compiles a list of WordPress Core
 * functions and classes referenced by the consuming project.
 *
 * The output JSON is consumed by the php-scoper config to leave WP references
 * unprefixed when scoping the project's vendor dependencies.
 */
class FindWPCoreCalls {
	/**
	 * @throws  \JsonException If the JSON encoding fails.
	 */
	public static function postAutoloadDump( \Composer\Script\Event $event ): void {
		$console_IO = $event->getIO();
		$vendorDir  = $event->getComposer()->getConfig()->get( 'vendor-dir' );

		if ( ! $event->isDevMode() ) {
			$console_IO->write( 'Not searching for WordPress Core calls due to not being in dev mode.' );
			return;
		}
		if ( getenv( 'CI' ) ) {
			$console_IO->write( 'Not searching for WordPress Core calls due to environment config.' );
			return;
		}

		$wp_stubs_path = $vendorDir . '/php-stubs/wordpress-stubs/wordpress-stubs.php';
		if ( ! is_file( $wp_stubs_path ) ) {
			$console_IO->write( 'Not searching for WordPress Core calls due to the stubs not being installed.' );
			return;
		}

		// Env var overrides for tests. Unset in real consuming plugins, so they get the
		// defaults: scan project root, exclude vendor/node_modules/languages/tests.
		// `getenv()` returns false when unset and "" when explicitly set to empty.
		$input_dir    = getenv( 'WP_CORE_CALLS_INPUT_DIR' ) ?: dirname( $vendorDir );
		$exclude_env  = getenv( 'WP_CORE_CALLS_EXCLUDE_DIRS' );
		$exclude_dirs = false === $exclude_env
			? array( 'vendor', 'node_modules', 'languages', 'tests' )
			: explode( ',', $exclude_env )|> ( fn( $x ) => array_map( 'trim', $x ) )|> array_filter( ... );

		// Compile a list of WordPress Core classes and functions from the stubs file.
		// Stubs use PHP 7.2 syntax (the WP6.7 minimum), so the parser must be set accordingly.
		$wp_stubs_parser = new \PhpParser\ParserFactory()->createForVersion( \PhpParser\PhpVersion::fromComponents( 7, 2 ) );
		new \PhpParser\NodeTraverser( $wp_stubs_visitor = new _stubsNodeVisitor() )->traverse( $wp_stubs_parser->parse( file_get_contents( $wp_stubs_path ) ) );

		// Cross-check with all the classes and functions used in the project files.
		$project_files_parser    = new \PhpParser\ParserFactory()->createForNewestSupportedVersion();
		$project_files_traverser = new \PhpParser\NodeTraverser( $project_files_visitor = new _projectNodeVisitor( $wp_stubs_visitor->functions, $wp_stubs_visitor->classes ) );

		$project_files = \Symfony\Component\Finder\Finder::create()->files()->in( $input_dir )->exclude( $exclude_dirs )->name( '*.php' );
		foreach ( $project_files as $file ) {
			$project_files_traverser->traverse( $project_files_parser->parse( $file->getContents() ) );
		}

		$output_dir  = getenv( 'WP_CORE_CALLS_OUTPUT_DIR' ) ?: dirname( $vendorDir );
		$output_file = getenv( 'WP_CORE_CALLS_OUTPUT_FILE' ) ?: 'wp-core-calls.json';
		file_put_contents(
			"$output_dir/$output_file",
			json_encode( array(
				'classes'   => array_values( array_unique( $project_files_visitor->found_classes ) ),
				'functions' => array_values( array_unique( $project_files_visitor->found_functions ) ),
			), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT )
		);
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
class _projectNodeVisitor extends \PhpParser\NodeVisitorAbstract {
	protected array $search_classes;
	protected array $search_functions;

	protected(set) array $found_classes = array();
	protected(set) array $found_functions = array();

	public function __construct( array $search_functions, array $search_classes ) {
		$this->search_functions = $search_functions;
		$this->search_classes   = $search_classes;
	}

	public function enterNode( Node $node ): int|null {
		if ( $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod ) {
			// Check return type(s).
			$return_types = $this->type_to_string_array( $node->returnType );
			if ( ! empty( $return_types ) ) {
				foreach ( $this->search_classes as $class ) {
					foreach ( $return_types as $return_type ) {
						if ( strtolower( $return_type ) === strtolower( $class ) ) {
							$this->found_classes[] = $return_type;
						}
					}
				}
			}

			// Check parameters.
			foreach ( $node->getParams() as $param ) {
				$param_types = $this->type_to_string_array( $param->type );
				if ( ! empty( $param_types ) ) {
					foreach ( $this->search_classes as $class ) {
						foreach ( $param_types as $param_type ) {
							if ( strtolower( $param_type ) === strtolower( $class ) ) {
								$this->found_classes[] = $param_type;
							}
						}
					}
				}
			}
		} elseif ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name ) {
			$function_name = $node->name->getParts()[0];
			foreach ( $this->search_functions as $function ) {
				if ( strtolower( $function_name ) === strtolower( $function ) ) {
					$this->found_functions[] = $function_name;
				}
			}
		} elseif ( $node instanceof Node\Stmt\Class_ && $node->extends ) {
			$class_name = $node->extends->getParts()[0];
			foreach ( $this->search_classes as $class ) {
				if ( strtolower( $class_name ) === strtolower( $class ) ) {
					$this->found_classes[] = $class_name;
				}
			}
		} elseif ( $node instanceof Node\Expr\New_ && 'Name' === $node->class->getType() ) {
			$class_name = $node->class->getParts()[0];
			foreach ( $this->search_classes as $class ) {
				if ( strtolower( $class_name ) === strtolower( $class ) ) {
					$this->found_classes[] = $class_name;
				}
			}
		} elseif ( $node instanceof Node\Expr\Instanceof_ || $node instanceof Node\Expr\StaticCall ) {
			if ( $node->class instanceof Node\Name ) {
				$class_name = $node->class->getParts()[0];
				foreach ( $this->search_classes as $class ) {
					if ( strtolower( $class_name ) === strtolower( $class ) ) {
						$this->found_classes[] = $class_name;
					}
				}
			}
		}

		return null;
	}

	protected function type_to_string_array( $type ): array {
		if ( $type instanceof Node\UnionType ) {
			return array_map( static function( $value ): string {
				return $value instanceof Node\Identifier
					? $value->name
					: $value->getParts()[0];
			}, $type->types );
		}

		if ( $type instanceof Node\NullableType ) {
			return (array) ( $type->type instanceof Node\Identifier
				? $type->type->name
				: $type->type->getParts()[0] );
		}

		if ( $type instanceof Node\Name ) {
			return (array) $type->getParts()[0];
		}

		return array();
	}
}
