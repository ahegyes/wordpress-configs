<?php declare( strict_types = 1 );

$config = array();
$workingDirectory = getcwd();
$maybePluginFile = basename( $workingDirectory );

foreach ( array( 'src', 'includes', 'models', 'blocks', 'templates' ) as $analyzeDirectory ) {
	if ( is_dir( $workingDirectory . '/' . $analyzeDirectory ) ) {
		$config['parameters']['paths'][] = $workingDirectory . '/' . $analyzeDirectory;
	}
}

foreach ( array( "$maybePluginFile.php", 'functions.php' ) as $analyzeFile ) {
	if ( is_file( $workingDirectory . '/' . $analyzeFile ) ) {
		$config['parameters']['paths'][] = $workingDirectory . '/' . $analyzeFile;
	}
}

if ( is_file( "$workingDirectory/$maybePluginFile.php" ) ) {
	$config['parameters']['WPCompat']['pluginFile'] = "$workingDirectory/$maybePluginFile.php";
} elseif ( ! is_file( "$workingDirectory/plugin.php" ) && ! is_file( "$workingDirectory/style.css" ) ) {
	$config['parameters']['WPCompat']['requiresAtLeast'] = '7.0';
}

$vendorDir = 'vendor';
$composerJson = "$workingDirectory/composer.json";
if ( is_file( $composerJson ) ) {
	$composerData = json_decode( (string) file_get_contents( $composerJson ), true );
	if ( is_array( $composerData ) && isset( $composerData['config']['vendor-dir'] ) && is_string( $composerData['config']['vendor-dir'] ) ) {
		$vendorDir = $composerData['config']['vendor-dir'];
	}
}

$wpStubsPath = "$workingDirectory/$vendorDir/php-stubs/wordpress-stubs/wordpress-stubs.php";
if ( is_file( $wpStubsPath ) ) {
	$config['parameters']['bootstrapFiles'][] = $wpStubsPath;
}

return $config;
