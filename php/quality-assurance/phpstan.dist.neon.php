<?php declare( strict_types=1 );
/**
 * Adds a plugin's conventional root files and source directories to the analyzed paths, and its scoped dependencies to the scanned ones.
 *
 * @package WordPressConfigs
 */

$project_dir = getcwd() ?: throw new RuntimeException( 'The current working directory is unreadable.' );
$paths       = array();

foreach ( array( 'functions-bootstrap.php', 'functions.php', 'uninstall.php' ) as $file ) {
	if ( is_file( "$project_dir/$file" ) ) {
		$paths[] = "$project_dir/$file";
	}
}

foreach ( array( 'src', 'includes', 'models', 'blocks', 'templates' ) as $dir ) {
	if ( is_dir( "$project_dir/$dir" ) ) {
		$paths[] = "$project_dir/$dir";
	}
}

return array(
	'parameters' => array(
		'paths'           => $paths,
		'scanDirectories' => is_dir( "$project_dir/vendor-prefixed" ) ? array( "$project_dir/vendor-prefixed" ) : array(),
	),
);
