<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer\Visitors;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;

/**
 * Collects the symbols scoped code declares and the prefixed names it references.
 *
 * It reads the parent attribute that a ParentConnectingVisitor sets and the namespacedName property that a NameResolver sets, both earlier in the same traversal.
 *
 * @internal
 */
final class ScopedSymbolCollector extends NodeVisitorAbstract {
	// region FIELDS AND CONSTANTS

	/**
	 * The names of the declared classes, interfaces, traits and enums, functions and constants, by kind, each keyed by its lookup key.
	 *
	 * @var     array{class: array<string, string>, function: array<string, string>, constant: array<string, string>}
	 */
	protected(set) array $declared = array(
		'class'    => array(),
		'function' => array(),
		'constant' => array(),
	);

	/**
	 * The prefixed names the code references, each with its location, its lookup key for each kind of symbol it can name, and whether a function_exists() call checks it.
	 *
	 * @var     list<array{name: string, location: string, keys: array<'class'|'function'|'constant'|'namespace', string>, checked: bool}>
	 */
	protected(set) array $references = array();

	/**
	 * The file the next traversal reads.
	 *
	 * @var     string
	 */
	public string $file = '';

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @param   string $prefix The scoping prefix, without leading or trailing backslashes.
	 * @param   Parser $parser The parser that reads the PHP code php-scoper scopes inside nowdoc strings.
	 */
	public function __construct(
		protected readonly string $prefix,
		protected readonly Parser $parser
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function enterNode( Node $node ): int|null {
		$parent = $node->getAttribute( 'parent' );
		if ( $node instanceof Node\Stmt\ClassLike ) {
			$this->declare( 'class', $node->namespacedName?->toString() );
		} elseif ( $node instanceof Node\Stmt\Function_ ) {
			$this->declare( 'function', $node->namespacedName?->toString() );
		} elseif ( $node instanceof Node\Stmt\Const_ ) {
			foreach ( $node->consts as $const ) {
				$this->declare( 'constant', $const->namespacedName?->toString() );
			}
		} elseif ( $node instanceof Node\Scalar\String_ ) {
			// php-scoper scopes the PHP code of a nowdoc that opens with a PHP tag like a file, and a string that names a symbol or a class constant like a reference.
			$code = \ltrim( $node->value );
			if ( Node\Scalar\String_::KIND_NOWDOC === $node->getAttribute( 'kind' ) && \str_starts_with( $code, '<?php' ) ) {
				try {
					$statements = $this->parser->parse( $code ) ?? array();
				} catch ( Error ) {
					return null; // php-scoper leaves a nowdoc that does not parse as PHP unscoped.
				}
				// php-scoper parses the code as written, so behind leading whitespace it scopes only code without a namespace, which it puts in the prefix namespace.
				$first = $statements[0] ?? null;
				if ( $code !== $node->value && ! ( $first instanceof Node\Stmt\Namespace_ && $this->prefix === $first->name?->toString() ) ) {
					return null;
				}
				new NodeTraverser( new NameResolver(), new ParentConnectingVisitor(), $this )->traverse( $statements );
			} else {
				$parts = \explode( '::', $node->value, 2 );
				if ( 1 === \preg_match( StubSymbolCollector::SYMBOL_NAME_REGEX, $parts[0] ) ) {
					$this->reference( \ltrim( $parts[0], '\\' ), $node, isset( $parts[1] ) ? array( 'class' ) : array( 'class', 'function', 'constant', 'namespace' ) );
				}
			}
		} elseif ( $node instanceof Node\Name && ! $parent instanceof Node\Stmt\Namespace_ ) {
			$use   = $parent instanceof Node\UseItem ? $parent->getAttribute( 'parent' ) : null;
			$type  = $parent instanceof Node\UseItem && ( $use instanceof Node\Stmt\Use_ || $use instanceof Node\Stmt\GroupUse ) ? ( $parent->type ?: $use->type ) : null;
			$kinds = match ( true ) {
				$parent instanceof Node\Expr\FuncCall, Node\Stmt\Use_::TYPE_FUNCTION === $type => array( 'function' ),
				$parent instanceof Node\Expr\ConstFetch, Node\Stmt\Use_::TYPE_CONSTANT === $type => array( 'constant' ),
				$parent instanceof Node\Stmt\GroupUse => array( 'namespace' ),
				// An import can name a namespace, which the code then extends.
				$parent instanceof Node\UseItem => array( 'class', 'namespace' ),
				default => array( 'class' ),
			};
			$this->reference( $use instanceof Node\Stmt\GroupUse ? $use->prefix->toString() . '\\' . $node->toString() : $node->toString(), $node, $kinds );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function leaveNode( Node $node ): null {
		// The names in a call's arguments are resolved only once the traversal has visited them.
		$declaration = $node instanceof Node\Expr\FuncCall ? StubSymbolCollector::declared_by_call( $node ) : null;
		if ( ! \is_null( $declaration ) ) {
			$this->declare( $declaration[0], $declaration[1] );
		}

		return null;
	}

	// endregion

	// region HELPERS

	/**
	 * Records a declared symbol.
	 *
	 * @param   'class'|'function'|'constant' $kind The kind of symbol.
	 * @param   string|null                   $name The name of the symbol, or null for an anonymous class.
	 *
	 * @return  void
	 */
	protected function declare( string $kind, ?string $name ): void {
		if ( ! \is_null( $name ) ) {
			$this->declared[ $kind ][ self::key( $kind, $name ) ] = $name;
		}
	}

	/**
	 * Records a name that carries the prefix, with its location, its lookup key for each kind of symbol it can name, and whether a function_exists() call checks it.
	 *
	 * @param   string                                                    $name  The name.
	 * @param   Node                                                      $node  The node that references it.
	 * @param   non-empty-list<'class'|'function'|'constant'|'namespace'> $kinds The kinds of symbol the name can stand for where it appears.
	 *
	 * @return  void
	 */
	protected function reference( string $name, Node $node, array $kinds ): void {
		if ( 0 === \stripos( $name, $this->prefix . '\\' ) ) {
			$call               = $node->getAttribute( 'parent' ) instanceof Node\Arg ? $node->getAttribute( 'parent' )->getAttribute( 'parent' ) : null;
			$this->references[] = array(
				'name'     => $name,
				'location' => $this->file . ':' . $node->getStartLine(),
				'keys'     => \array_combine( $kinds, \array_map( static fn ( string $kind ): string => self::key( $kind, $name ), $kinds ) ),
				'checked'  => $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && 'function_exists' === $call->name->toString(),
			);
		}
	}

	/**
	 * Returns the key a symbol of a kind is looked up by: its lowercase name, but a constant keeps the case of its name below a lowercase namespace, as PHP compares them.
	 *
	 * @param   string $kind The kind of symbol: class, function, constant or namespace.
	 * @param   string $name The name of the symbol.
	 *
	 * @return  string
	 */
	protected static function key( string $kind, string $name ): string {
		$name      = \trim( $name, '\\' );
		$separator = \strrpos( $name, '\\' );
		if ( 'constant' !== $kind ) {
			return \strtolower( $name );
		}

		return false === $separator ? $name : \strtolower( \substr( $name, 0, $separator ) ) . \substr( $name, $separator );
	}

	// endregion
}
