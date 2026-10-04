<?php declare( strict_types=1 );
/**
 * A file plugins load by path, outside every autoload key.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @package DeepWebSolutions\Config\Tests
 */

namespace DeepWebSolutions\Framework\Loader;

/**
 * Returns whether the loader ran.
 *
 * @since   2.0.0
 * @version 2.0.0
 *
 * @return  bool
 */
function is_loaded(): bool {
	return true;
}
