<?php declare( strict_types=1 );
/**
 * Declares the package's functions, which Composer loads through autoload.files.
 *
 * @package WordPressConfigs\Tests
 */

namespace Acme\Commerce;

/**
 * The default label of commerce sections.
 *
 * @var string
 */
const LABEL = 'Commerce';

/**
 * Returns the prefix of commerce labels.
 *
 * @return  string
 */
function get_label_prefix(): string {
	return 'Commerce';
}

\class_alias( Formats\Csv::class, Formats\Comma::class );

\DI\value( LABEL );
