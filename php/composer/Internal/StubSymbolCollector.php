<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer\Internal;

/**
 * PhpParser visitor that harvests fully-qualified class, function, and constant names from a
 * parsed stubs file. Class-likes (class/interface/trait/enum) all flow into `$classes` —
 * php-scoper's `exclude-classes` covers them uniformly. A `NameResolver` must run ahead of this
 * visitor in the same traversal so `namespacedName` is populated on the collected nodes.
 *
 * @internal
 */
final class StubSymbolCollector extends \PhpParser\NodeVisitorAbstract {
	/**
	 * PHP symbol-name shape accepted by php-scoper's exclusion lists.
	 *
	 * @var string
	 */
	private const SYMBOL_NAME_REGEX = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*$/';

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
					$this->collect( $this->classes, $node->namespacedName->toString() );
				}
				return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
			case \PhpParser\Node\Stmt\Function_::class:
				if ( null !== $node->namespacedName ) {
					$this->collect( $this->functions, $node->namespacedName->toString() );
				}
				break;
			case \PhpParser\Node\Stmt\Const_::class:
				foreach ( $node->consts as $const ) {
					if ( null !== $const->namespacedName ) {
						$this->collect( $this->constants, $const->namespacedName->toString() );
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
					$this->collect( $this->constants, $node->args[0]->value->value );
				}
				break;
		}
		return null;
	}

	/**
	 * Adds a symbol only when it matches php-scoper's exclusion-name shape.
	 *
	 * @param list<string> $symbols Symbol list to append to.
	 * @param string       $symbol  Harvested symbol name.
	 *
	 * @return void
	 */
	private function collect( array &$symbols, string $symbol ): void {
		if ( 1 === \preg_match( self::SYMBOL_NAME_REGEX, $symbol ) ) {
			$symbols[] = $symbol;
		}
	}
}
