<?php declare( strict_types=1 );
/**
 * Declares the package's functions, which Composer loads through autoload.files.
 *
 * @package DeepWebSolutions\Config\Tests
 */

namespace DeepWebSolutions\Framework\Commerce;

/**
 * Returns the prefix of commerce labels.
 *
 * @return  string
 */
function get_label_prefix(): string {
	return 'Commerce';
}
