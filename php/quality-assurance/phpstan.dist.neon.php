<?php declare( strict_types=1 );

/**
 * PHPStan auto-discovery for WordPress plugin / theme / library layouts.
 *
 * Plugin file detected by scanning root-level `*.php` files for a `Plugin Name:` header
 * (same parse strategy as WordPress core's `_get_plugin_data_from_file()`). Theme layout
 * detected by root `style.css`. Library / src-based layout detected by root `src/`.
 *
 * PHPStan loads this file via the consumer's `phpstan.neon` `includes:` directive and
 * uses the bottom `return` statement as merged config.
 */

$project_dir = \getcwd() ?: throw new \RuntimeException( 'getcwd() failed — current working directory is unreadable or does not exist.' );

$config = array();

$plugin_file = null;
foreach ( \glob( $project_dir . '/*.php' ) ?: array() as $candidate ) {
	$header = \file_get_contents( $candidate, false, null, 0, 8192 );
	if ( false !== $header && 1 === \preg_match( '/^[ \t\/*#@]*Plugin Name:/mi', $header ) ) {
		$plugin_file = $candidate;
		break;
	}
}

if ( null !== $plugin_file || \is_dir( $project_dir . '/src' ) ) {
	foreach ( array( 'src', 'includes', 'models', 'blocks', 'templates', 'config', 'tests' ) as $dir ) {
		if ( \is_dir( $project_dir . '/' . $dir ) ) {
			$config['parameters']['paths'][] = $project_dir . '/' . $dir;
		}
	}

	$analyze_files = array( 'functions.php', 'uninstall.php' );
	if ( null !== $plugin_file ) {
		$analyze_files[] = \basename( $plugin_file );
	}

	foreach ( $analyze_files as $file ) {
		if ( \is_file( $project_dir . '/' . $file ) ) {
			$config['parameters']['paths'][] = $project_dir . '/' . $file;
		}
	}
}

if ( \is_file( $project_dir . '/style.css' ) ) {
	foreach ( array( 'inc', 'template-parts', 'parts', 'patterns', 'blocks', 'tests' ) as $dir ) {
		if ( \is_dir( $project_dir . '/' . $dir ) ) {
			$config['parameters']['paths'][] = $project_dir . '/' . $dir;
		}
	}

	foreach ( array( 'functions.php', 'index.php' ) as $file ) {
		if ( \is_file( $project_dir . '/' . $file ) ) {
			$config['parameters']['paths'][] = $project_dir . '/' . $file;
		}
	}
}

// WPCompat's SinceVersionRule needs a minimum WP version or it throws for every analysed file.
// Given `pluginFile` it reads that file's `Requires at least:` header itself and hard-throws when
// the header is absent, aborting the whole run; `requiresAtLeast` is honoured as given and takes
// precedence. So resolve the minimum here from the layout's main file — the plugin file, else the
// theme's style.css (absent for a library, which falls back to the floor) — honouring a valid
// numeric header above this ruleset's floor and flooring anything else. The strict-numeric gate is
// load-bearing: version_compare() alone would rank garbage like "8.x" above 7.0.
$read_requires_at_least = static function ( string $file ): string {
	$floor = '7.0';
	if ( ! \is_file( $file ) ) {
		return $floor;
	}
	$header = \file_get_contents( $file, false, null, 0, 8192 );
	if ( false !== $header && 1 === \preg_match( '/^[ \t\/*#@]*Requires at least:[ \t]*([^\r\n]+)/mi', $header, $matches ) ) {
		$declared = \trim( $matches[1] );
		if ( 1 === \preg_match( '/^\d+(?:\.\d+){0,2}$/', $declared ) && \version_compare( $declared, $floor, '>' ) ) {
			return $declared;
		}
	}
	return $floor;
};

$config['parameters']['WPCompat']['requiresAtLeast'] = $read_requires_at_least( $plugin_file ?? ( $project_dir . '/style.css' ) );

$vendor_dir    = 'vendor';
$composer_json = $project_dir . '/composer.json';
if ( \is_file( $composer_json ) ) {
	$contents      = \file_get_contents( $composer_json ) ?: throw new \RuntimeException( \sprintf( 'Could not read %s', $composer_json ) );
	$composer_data = \json_decode( $contents, true, flags: JSON_THROW_ON_ERROR );
	if ( \is_array( $composer_data ) && isset( $composer_data['config']['vendor-dir'] ) && \is_string( $composer_data['config']['vendor-dir'] ) ) {
		$vendor_dir = $composer_data['config']['vendor-dir'];
	}
}

$wp_stubs_path = $project_dir . '/' . $vendor_dir . '/php-stubs/wordpress-stubs/wordpress-stubs.php';
if ( \is_file( $wp_stubs_path ) ) {
	$config['parameters']['bootstrapFiles'][] = $wp_stubs_path;
}

return $config;
