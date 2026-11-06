<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer\Visitors;

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
	public const SYMBOL_NAME_REGEX = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*$/';

	/**
	 * The class names the stubs declare, including the aliases class_alias() calls declare.
	 *
	 * @var     list<string>
	 */
	protected(set) array $classes = array();

	/**
	 * The function names the stubs declare.
	 *
	 * @var     list<string>
	 */
	protected(set) array $functions = array();

	/**
	 * The constant names the stubs declare, through const statements and define() calls.
	 *
	 * @var     list<string>
	 */
	protected(set) array $constants = array();

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
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function leaveNode( Node $node ): null {
		// A define() name and a class_alias() alias are real symbols, so scoped code that references them must keep them unprefixed.
		$declaration = $node instanceof Node\Expr\FuncCall ? self::declared_by_call( $node ) : null;
		if ( 'class' === ( $declaration[0] ?? null ) ) {
			$this->collect( $this->classes, $declaration[1] );
		} elseif ( 'constant' === ( $declaration[0] ?? null ) ) {
			$this->collect( $this->constants, $declaration[1] );
		}

		return null;
	}

	// endregion

	// region METHODS

	/**
	 * Returns the kind and name of the symbol a define() or class_alias() call declares, or null when the call declares none by a string or a Foo::class name.
	 *
	 * The call's arguments must have been visited, since a NameResolver resolves a Foo::class name only when the traversal reaches it.
	 *
	 * @param   Node\Expr\FuncCall $node The function call.
	 *
	 * @return  array{'class'|'constant', string}|null
	 */
	public static function declared_by_call( Node\Expr\FuncCall $node ): ?array {
		$function = $node->name instanceof Node\Name ? $node->name->toLowerString() : '';
		$argument = match ( $function ) {
			'define'      => $node->args[0] ?? null,
			'class_alias' => $node->args[1] ?? null,
			default       => null,
		};
		$value = $argument instanceof Node\Arg ? $argument->value : null;
		$name  = match ( true ) {
			$value instanceof Node\Scalar\String_ => \ltrim( $value->value, '\\' ),
			$value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && 'class' === $value->name->toLowerString() => $value->class->toString(),
			default => null,
		};

		return \is_null( $name ) ? null : array( 'define' === $function ? 'constant' : 'class', $name );
	}

	// endregion

	// region HELPERS

	/**
	 * Adds a symbol when it has the name shape php-scoper's exclusion lists accept.
	 *
	 * @param   list<string> $symbols The symbol list to append to.
	 * @param   string       $symbol  The symbol name.
	 *
	 * @return  void
	 */
	protected function collect( array &$symbols, string $symbol ): void {
		if ( 1 === \preg_match( self::SYMBOL_NAME_REGEX, $symbol ) ) {
			$symbols[] = $symbol;
		}
	}

	// endregion
}
