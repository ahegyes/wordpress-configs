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
