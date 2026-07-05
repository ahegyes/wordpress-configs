<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Composer\Internal\StubSymbolCollector;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( StubSymbolCollector::class )]
final class StubSymbolCollectorTest extends TestCase {

	#[Test]
	public function collects_classlikes_functions_and_constants_with_fqcn(): void {
		$collector = $this->collect(
			<<<'PHP'
			<?php
			namespace A;
			class Foo {}
			interface Bar {}
			trait Baz {}
			enum Qux {}
			function helper() {}
			const ALPHA = 1;
			define('GLOBAL_DEFINE', 2);
			PHP
		);

		// Class-likes all flow into one bag, in source order, fully namespace-qualified.
		self::assertSame( array( 'A\\Foo', 'A\\Bar', 'A\\Baz', 'A\\Qux' ), $collector->classes );
		self::assertSame( array( 'A\\helper' ), $collector->functions );
		// `const` declarations are namespace-qualified; `define()` is taken verbatim.
		self::assertContains( 'A\\ALPHA', $collector->constants );
		self::assertContains( 'GLOBAL_DEFINE', $collector->constants );
	}

	#[Test]
	public function ignores_non_define_function_calls(): void {
		$collector = $this->collect(
			<<<'PHP'
			<?php
			some_other_call('NOT_A_CONSTANT');
			PHP
		);

		self::assertSame( array(), $collector->constants );
		self::assertSame( array(), $collector->functions );
		self::assertSame( array(), $collector->classes );
	}

	#[Test]
	public function skips_define_calls_with_invalid_constant_names(): void {
		$collector = $this->collect(
			<<<'PHP'
			<?php
			define('VALID_DEFINE', 1);
			define('invalid-define', 2);
			define('1_INVALID_DEFINE', 3);
			PHP
		);

		self::assertSame( array( 'VALID_DEFINE' ), $collector->constants );
	}

	#[Test]
	public function does_not_descend_into_class_members(): void {
		// Class-like declarations short-circuit traversal (DONT_TRAVERSE_CHILDREN), so methods
		// and class constants inside them are never collected as top-level symbols.
		$collector = $this->collect(
			<<<'PHP'
			<?php
			class Outer {
				const INNER = 1;
				public function method() {}
			}
			PHP
		);

		self::assertSame( array( 'Outer' ), $collector->classes );
		self::assertSame( array(), $collector->functions );
		self::assertSame( array(), $collector->constants );
	}

	#[Test]
	public function collects_class_alias_target_names(): void {
		// class_alias() registers its second argument as a real, referenceable class name; a stub
		// that declares one must have the alias excluded, or scoped code referencing it fatals.
		$collector = $this->collect(
			<<<'PHP'
			<?php
			class_alias( 'Real\Thing', 'Legacy\Alias' );
			\class_alias( Real\Other::class, 'Global_Alias' );
			PHP
		);

		self::assertContains( 'Legacy\\Alias', $collector->classes );
		self::assertContains( 'Global_Alias', $collector->classes );
	}

	private function collect( string $code ): StubSymbolCollector {
		$parsed = new ParserFactory()->createForNewestSupportedVersion()->parse( $code )
			?? self::fail( 'Fixture source failed to parse.' );

		$collector = new StubSymbolCollector();
		$traverser = new NodeTraverser();
		$traverser->addVisitor( new NameResolver() );
		$traverser->addVisitor( $collector );
		$traverser->traverse( $parsed );

		return $collector;
	}
}
