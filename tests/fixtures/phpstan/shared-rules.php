<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Fixtures;

class Greeting {
	public function text(): string {
		return 'Hello.';
	}
}

final class FormalGreeting extends Greeting {
	public function text(): string {
		return 'Good day.';
	}
}

function echo_back( string $text ): string {
	$identity = static fn ( $value ) => $value;

	return $identity( $text );
}
