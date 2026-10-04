<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Fixtures;

use Psr\Container\ContainerInterface;

use function PHPStan\Testing\assertType;

final class Service {}

function resolve( ContainerInterface $container ): void {
	assertType( Service::class, $container->get( Service::class ) );
	assertType( 'mixed', $container->get( 'service' ) );
}
