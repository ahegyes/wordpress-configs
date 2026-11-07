<?php declare( strict_types=1 );
/**
 * Example fixture class for smoke-testing shared configs.
 *
 * @package Acme
 */

namespace Acme\PluginStub;

/**
 * A standards-compliant class for the PHPCS smoke.
 */
class Example {
	/**
	 * Says hello.
	 *
	 * @param string $name Name to greet.
	 *
	 * @return string
	 */
	public function say_hello( string $name ): string {
		return "Hello, {$name}!";
	}
}
