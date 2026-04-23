<?php declare( strict_types = 1 );

$config = array();
$workingDirectory = getcwd();
$maybePluginFile = basename( $workingDirectory );

foreach ( array( 'src', 'includes', 'models', 'blocks', 'templates' ) as $analyzeDirectory ) {
	if ( is_dir( $workingDirectory . '/' . $analyzeDirectory ) ) {
		$config['parameters']['paths'][] = $workingDirectory . '/' . $analyzeDirectory;
	}
}

foreach ( array( "$maybePluginFile.php", 'functions-bootstrap.php', 'functions.php' ) as $analyzeFile ) {
	if ( is_file( $workingDirectory . '/' . $analyzeFile ) ) {
		$config['parameters']['paths'][] = $workingDirectory . '/' . $analyzeFile;
	}
}

if ( is_file( "$workingDirectory/$maybePluginFile.php" ) ) {
	$config['parameters']['WPCompat']['pluginFile'] = "$workingDirectory/$maybePluginFile.php";
}

return $config;
