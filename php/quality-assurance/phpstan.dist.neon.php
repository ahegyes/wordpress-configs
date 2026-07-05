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

// WPCompat needs EITHER pluginFile OR requiresAtLeast set or it errors per-file.
// Themes fall through to requiresAtLeast — WPCompat has no themeFile key; honour the
// theme's own `Requires at least:` contract, floored at this ruleset's minimum.
if ( null !== $plugin_file ) {
	$config['parameters']['WPCompat']['pluginFile'] = $plugin_file;
} else {
	$requires_at_least = '7.0';
	$style_css         = $project_dir . '/style.css';
	if ( \is_file( $style_css ) ) {
		$style_header = \file_get_contents( $style_css, false, null, 0, 8192 );
		if ( false !== $style_header && 1 === \preg_match( '/^[ \t\/*#@]*Requires at least:[ \t]*([^\r\n]+)/mi', $style_header, $matches ) ) {
			$declared = \trim( $matches[1] );
			// Validate a strict numeric version first — version_compare() would rank garbage like "8.x" above 7.0.
			if ( 1 === \preg_match( '/^\d+(?:\.\d+){0,2}$/', $declared ) && \version_compare( $declared, '7.0', '>' ) ) {
				$requires_at_least = $declared;
			}
		}
	}
	$config['parameters']['WPCompat']['requiresAtLeast'] = $requires_at_least;
}

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
