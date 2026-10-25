<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\PHPStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Types a variable named $wpdb as wpdb where PHPStan knows it only as mixed, as after global $wpdb;.
 *
 * WordPress sets the global before any plugin loads, so the name identifies the connection, as WPCS's database sniffs assume; a declared or narrowed type stays.
 */
final class WpdbVariableTypeExtension implements ExpressionTypeResolverExtension {
	// region INHERITED METHODS

	/**
	 * {@inheritDoc}
	 */
	#[\Override]
	public function getType( Expr $expr, Scope $scope ): ?Type {
		if ( ! $expr instanceof Variable || 'wpdb' !== $expr->name || $scope->hasVariableType( 'wpdb' )->no() ) {
			return null;
		}

		return $scope->getVariableType( 'wpdb' ) instanceof MixedType ? new ObjectType( 'wpdb' ) : null;
	}

	// endregion
}
