<?php declare( strict_types=1 );

namespace Prefixed\Acme\Library;

class Client {
	public function name(): string {
		return 'library';
	}

	public function untyped( $value ) {
		return $value;
	}
}
