<?php declare( strict_types=1 );

/**
 * Auto-discovery for single-plugin and single-theme layouts. Other layouts (libraries, monorepos) get
 * an empty discovery and must declare `parameters.paths` themselves.
 */

$config = array();

$workingDirectory = getcwd() ?: throw new \RuntimeException( 'getcwd() failed — current working directory is unreadable or does not exist.' );
$maybePluginFile  = basename( $workingDirectory );

if ( is_dir( "$workingDirectory/src" ) || is_file( "$workingDirectory/$maybePluginFile.php" ) ) {
	$analyzeDirectories = array( 'src', 'includes', 'models', 'blocks', 'templates', 'config', 'tests' );
	foreach ( $analyzeDirectories as $analyzeDirectory ) {
		if ( is_dir( "$workingDirectory/$analyzeDirectory" ) ) {
			$config['parameters']['paths'][] = "$workingDirectory/$analyzeDirectory";
		}
	}

	$analyzeFiles = array( "$maybePluginFile.php", 'functions.php', 'uninstall.php' );
	foreach ( $analyzeFiles as $analyzeFile ) {
		if ( is_file( "$workingDirectory/$analyzeFile" ) ) {
			$config['parameters']['paths'][] = "$workingDirectory/$analyzeFile";
		}
	}
}

if ( is_file( "$workingDirectory/style.css" ) ) {
	$analyzeDirectories = array( 'inc', 'template-parts', 'parts', 'patterns', 'blocks', 'tests' );
	foreach ( $analyzeDirectories as $analyzeDirectory ) {
		if ( is_dir( "$workingDirectory/$analyzeDirectory" ) ) {
			$config['parameters']['paths'][] = "$workingDirectory/$analyzeDirectory";
		}
	}

	$analyzeFiles = array( 'functions.php', 'index.php' );
	foreach ( $analyzeFiles as $analyzeFile ) {
		if ( is_file( "$workingDirectory/$analyzeFile" ) ) {
			$config['parameters']['paths'][] = "$workingDirectory/$analyzeFile";
		}
	}
}

// WPCompat needs EITHER pluginFile OR requiresAtLeast set or it errors per-file.
// Themes fall through to requiresAtLeast — WPCompat has no themeFile key.
if ( is_file( "$workingDirectory/$maybePluginFile.php" ) ) {
	$config['parameters']['WPCompat']['pluginFile'] = "$workingDirectory/$maybePluginFile.php";
} else {
	$config['parameters']['WPCompat']['requiresAtLeast'] = '7.0';
}

$vendorDir    = 'vendor';
$composerJson = "$workingDirectory/composer.json";
if ( is_file( $composerJson ) ) {
	$contents     = file_get_contents( $composerJson ) ?: throw new \RuntimeException( sprintf( 'Could not read %s', $composerJson ) );
	$composerData = json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );
	if ( is_array( $composerData ) && isset( $composerData['config']['vendor-dir'] ) && is_string( $composerData['config']['vendor-dir'] ) ) {
		$vendorDir = $composerData['config']['vendor-dir'];
	}
}

$wpStubsPath = "$workingDirectory/$vendorDir/php-stubs/wordpress-stubs/wordpress-stubs.php";
if ( is_file( $wpStubsPath ) ) {
	$config['parameters']['bootstrapFiles'][] = $wpStubsPath;
}

return $config;
