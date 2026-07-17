<?php declare( strict_types=1 );
/**
 * Example fixture class for smoke-testing the shared PHPCS baseline.
 *
 * @package DeepWebSolutions\Config\Tests
 */

namespace DeepWebSolutions\Config\Tests;

/**
 * A minimal, standards-compliant class used only to prove the shipped
 * ruleset can parse and scan real WP-shaped PHP without crashing.
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
