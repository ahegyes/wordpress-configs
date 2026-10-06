<?php declare( strict_types=1 );

namespace Acme\Fixtures;

function client_names(): string {
	return ( new \Acme\Library\Client() )->name() . ( new \Prefixed\Acme\Library\Client() )->name();
}
