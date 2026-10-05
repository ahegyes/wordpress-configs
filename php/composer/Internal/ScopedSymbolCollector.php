<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer\Internal;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;

/**
 * Collects the symbols scoped code declares and the prefixed names it references.
 *
 * It reads the parent and namespacedName attributes that a ParentConnectingVisitor and a NameResolver set earlier in the same traversal.
 *
 * @internal
 */
final class ScopedSymbolCollector extends NodeVisitorAbstract {
	// region FIELDS AND CONSTANTS

	/**
	 * The lowercase names of the declared classes, interfaces, traits, enums, functions and constants, keyed by themselves.
	 *
	 * @var     array<string, string>
	 */
	public array $declared = array();

	/**
	 * The prefixed names the code references, each with its location.
	 *
	 * @var     list<array{name: string, location: string}>
	 */
	public array $references = array();

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
		if ( $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_ ) {
			$this->declare( $node->namespacedName );
		} elseif ( $node instanceof Node\Stmt\Const_ ) {
			foreach ( $node->consts as $const ) {
				$this->declare( $const->namespacedName );
			}
		} elseif ( $node instanceof Node\Scalar\String_ ) {
			$this->string( $node );
		} elseif ( $node instanceof Node\Name && ! $node->getAttribute( 'parent' ) instanceof Node\Stmt\Namespace_ ) {
			$use = $node->getAttribute( 'parent' ) instanceof Node\UseItem ? $node->getAttribute( 'parent' )->getAttribute( 'parent' ) : null;
			$this->reference( $use instanceof Node\Stmt\GroupUse ? $use->prefix->toString() . '\\' . $node->toString() : $node->toString(), $node );
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function leaveNode( Node $node ): null {
		// The names in a call's arguments are resolved only once the traversal has visited them.
		if ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && \in_array( $node->name->toLowerString(), array( 'define', 'class_alias' ), true ) ) {
			$argument = $node->args[ 'define' === $node->name->toLowerString() ? 0 : 1 ] ?? null;
			if ( $argument instanceof Node\Arg && $argument->value instanceof Node\Scalar\String_ ) {
				$this->declare( new Node\Name( \ltrim( $argument->value->value, '\\' ) ) );
			} elseif ( $argument instanceof Node\Arg && $argument->value instanceof Node\Expr\ClassConstFetch && $argument->value->class instanceof Node\Name ) {
				$this->declare( $argument->value->class );
			}
		}

		return null;
	}

	// endregion

	// region HELPERS

	/**
	 * Records a declared symbol.
	 *
	 * @param   Node\Name|null $name The resolved name of the symbol, or null for an anonymous class.
	 */
	protected function declare( ?Node\Name $name ): void {
		if ( ! \is_null( $name ) ) {
			$this->declared[ $name->toLowerString() ] = $name->toLowerString();
		}
	}

	/**
	 * Records a string literal shaped like a symbol name, and reads the PHP code of a nowdoc string that opens with a PHP tag, which php-scoper scopes like a file.
	 *
	 * @param   Node\Scalar\String_ $node The string literal.
	 */
	protected function string( Node\Scalar\String_ $node ): void {
		if ( Node\Scalar\String_::KIND_NOWDOC === $node->getAttribute( 'kind' ) && \str_starts_with( $node->value, '<?php' ) ) {
			new NodeTraverser( new NameResolver(), new ParentConnectingVisitor(), $this )->traverse( $this->parser->parse( $node->value ) ?? array() );
		} elseif ( 1 === \preg_match( '/^\\\\?([A-Za-z_\x80-\xff][\w\x80-\xff\\\\]*)$/', $node->value, $match ) ) {
			$this->reference( $match[1], $node );
		}
	}

	/**
	 * Records a name that carries the prefix, with its location.
	 *
	 * @param   string $name The name.
	 * @param   Node   $node The node that references it.
	 */
	protected function reference( string $name, Node $node ): void {
		if ( 0 === \stripos( $name, $this->prefix . '\\' ) ) {
			$this->references[] = array(
				'name'     => $name,
				'location' => $this->file . ':' . $node->getStartLine(),
			);
		}
	}

	// endregion
}
