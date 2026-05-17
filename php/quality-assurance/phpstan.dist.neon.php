<?php declare( strict_types=1 );

/**
 * PHPStan config extension for DWS WordPress projects. The auto-discovery logic
 * lives in `phpstan-auto-discovery.php` so it can be unit-tested directly with
 * fixture project layouts; this file is the consumer-facing entry point.
 */

$project_dir = \getcwd() ?: throw new \RuntimeException( 'getcwd() failed — current working directory is unreadable or does not exist.' );
$discover    = require __DIR__ . '/phpstan-auto-discovery.php';

return $discover( $project_dir );
