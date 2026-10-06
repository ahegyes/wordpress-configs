<?php declare( strict_types=1 );
/**
 * Declares a function plugins load by path, outside every autoload key.
 *
 * @package WordPressConfigs\Tests
 */

namespace Acme\Loader;

/**
 * Returns whether the loader ran.
 *
 * @return  bool
 */
function is_loaded(): bool {
	return true;
}
