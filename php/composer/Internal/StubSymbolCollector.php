<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer\Internal;

use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects the fully-qualified class, function and constant names a stubs file declares.
 *
 * It reads the namespacedName property that a NameResolver sets earlier in the same traversal, and files every class-like into the class list, because php-scoper's exclude-classes covers them all.
 *
 * @internal
 */
final class StubSymbolCollector extends NodeVisitorAbstract {
	// region FIELDS AND CONSTANTS

	/**
	 * The symbol-name shape php-scoper's exclusion lists accept.
	 *
	 * @var     string
	 */
	protected const SYMBOL_NAME_REGEX = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*$/';

	/**
	 * The class names the stubs declare, including the aliases class_alias() calls declare.
	 *
	 * @var     list<string>
	 */
	public array $classes = array();

	/**
	 * The function names the stubs declare.
	 *
	 * @var     list<string>
	 */
	public array $functions = array();

	/**
	 * The constant names the stubs declare, through const statements and define() calls.
	 *
	 * @var     list<string>
	 */
	public array $constants = array();

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function enterNode( Node $node ): int|null {
		switch ( \get_class( $node ) ) {
			case Node\Stmt\Class_::class:
			case Node\Stmt\Interface_::class:
			case Node\Stmt\Trait_::class:
			case Node\Stmt\Enum_::class:
				if ( null !== $node->namespacedName ) {
					$this->collect( $this->classes, $node->namespacedName->toString() );
				}
				return NodeVisitor::DONT_TRAVERSE_CHILDREN;
			case Node\Stmt\Function_::class:
				if ( null !== $node->namespacedName ) {
					$this->collect( $this->functions, $node->namespacedName->toString() );
				}
				break;
			case Node\Stmt\Const_::class:
				foreach ( $node->consts as $const ) {
					if ( null !== $const->namespacedName ) {
						$this->collect( $this->constants, $const->namespacedName->toString() );
					}
				}
				break;
			case Node\Expr\FuncCall::class:
				if ( ! $node->name instanceof Node\Name ) {
					break;
				}
				// A define() name and a class_alias() alias are real symbols, so scoped code that references them must keep them unprefixed.
				$function = $node->name->toString();
				if ( 'define' === $function ) {
					$this->collect_string_argument( $this->constants, $node, 0 );
				} elseif ( 'class_alias' === $function ) {
					$this->collect_string_argument( $this->classes, $node, 1 );
				}
				break;
		}

		return null;
	}

	// endregion

	// region HELPERS

	/**
	 * Adds a symbol when it has the name shape php-scoper's exclusion lists accept.
	 *
	 * @param   list<string> $symbols The symbol list to append to.
	 * @param   string       $symbol  The symbol name.
	 */
	protected function collect( array &$symbols, string $symbol ): void {
		if ( 1 === \preg_match( self::SYMBOL_NAME_REGEX, $symbol ) ) {
			$symbols[] = $symbol;
		}
	}

	/**
	 * Adds the string literal a function call passes at a position, when there is one.
	 *
	 * @param   list<string>       $symbols The symbol list to append to.
	 * @param   Node\Expr\FuncCall $node    The function call.
	 * @param   int                $index   The zero-based argument position.
	 */
	protected function collect_string_argument( array &$symbols, Node\Expr\FuncCall $node, int $index ): void {
		if (
			isset( $node->args[ $index ] )
			&& $node->args[ $index ] instanceof Node\Arg
			&& $node->args[ $index ]->value instanceof Node\Scalar\String_
		) {
			$this->collect( $symbols, $node->args[ $index ]->value->value );
		}
	}

	// endregion
}
