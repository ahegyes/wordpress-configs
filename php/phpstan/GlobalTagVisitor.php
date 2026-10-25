<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\PHPStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;

/**
 * Gives each global statement the types that the @global tags of its function's or method's docblock declare, as WordPress's documentation standard writes them.
 *
 * PHPStan types a global as mixed but reads an inline @var on the statement, so the visitor attaches one; a global no tag declares stays mixed, and a statement with its own docblock keeps it.
 */
final class GlobalTagVisitor extends NodeVisitorAbstract {
	// region FIELDS AND CONSTANTS

	/**
	 * The types the enclosing functions declare, innermost last, each keyed by variable name.
	 *
	 * @var     list<array<string, string>>
	 */
	protected array $scopes = array();

	// endregion

	// region MAGIC METHODS

	/**
	 * Constructor.
	 *
	 * @param   Lexer        $lexer  PHPStan's PHPDoc lexer.
	 * @param   PhpDocParser $parser PHPStan's PHPDoc parser.
	 */
	public function __construct(
		protected Lexer $lexer,
		protected PhpDocParser $parser
	) {}

	// endregion

	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function beforeTraverse( array $nodes ): null {
		$this->scopes = array();
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function enterNode( Node $node ): null {
		if ( $node instanceof Node\FunctionLike ) {
			$types = array();
			$doc   = $node->getDocComment()?->getText();
			if ( null !== $doc ) {
				// The parser reads @global as an unknown tag, so each one is parsed with @var's grammar of a type and a variable.
				$tokens = new TokenIterator( $this->lexer->tokenize( (string) \preg_replace( '/@global(?=\s)/', '@var', $doc ) ) );
				foreach ( $this->parser->parse( $tokens )->getVarTagValues() as $tag ) {
					if ( '' !== $tag->variableName ) {
						$types[ \substr( $tag->variableName, 1 ) ] = (string) $tag->type;
					}
				}
			}
			$this->scopes[] = $types;
		} elseif ( $node instanceof Node\Stmt\Global_ && null === $node->getDocComment() ) {
			$types = \end( $this->scopes ) ?: array();
			$lines = array();
			foreach ( $node->vars as $var ) {
				if ( $var instanceof Node\Expr\Variable && \is_string( $var->name ) && isset( $types[ $var->name ] ) ) {
					$lines[] = " * @var {$types[ $var->name ]} \${$var->name}";
				}
			}
			if ( array() !== $lines ) {
				$node->setDocComment( new Doc( "/**\n" . \implode( "\n", $lines ) . "\n */" ) );
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function leaveNode( Node $node ): null {
		if ( $node instanceof Node\FunctionLike ) {
			\array_pop( $this->scopes );
		}

		return null;
	}

	// endregion
}
