<?php declare( strict_types=1 );

namespace Acme\Library;

final class Client {
	public function name(): string {
		return 'library';
	}

	public function untyped( $value ) {
		return $value;
	}
}
