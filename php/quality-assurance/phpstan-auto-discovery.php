<?php declare( strict_types=1 );

/**
 * PHPStan auto-discovery closure for DWS WordPress plugin / theme / library layouts.
 *
 * Plugin layouts are detected by scanning root-level `*.php` files for a `Plugin Name:`
 * header — same parse strategy as WordPress core's `_get_plugin_data_from_file()`. Theme
 * layouts are detected by a root `style.css`. Library / src-based layouts (no plugin
 * file, no style.css) are detected by a root `src/` directory.
 *
 * Returned arrays are merged into the consumer's PHPStan config by the `.neon.php` wrapper.
 *
 * @return \Closure(string): array<string, array<string, mixed>>
 */
return static function ( string $project_dir ): array {
	$config = array();

	$plugin_file = ( static function ( string $dir ): ?string {
		$candidates = \glob( $dir . '/*.php' ) ?: array();

		foreach ( $candidates as $candidate ) {
			$header = \file_get_contents( $candidate, false, null, 0, 8192 );
			if ( false !== $header && 1 === \preg_match( '/^[ \t\/*#@]*Plugin Name:/mi', $header ) ) {
				return $candidate;
			}
		}

		return null;
	} )( $project_dir );

	if ( null !== $plugin_file || \is_dir( $project_dir . '/src' ) ) {
		$analyze_directories = array( 'src', 'includes', 'models', 'blocks', 'templates', 'config', 'tests' );
		foreach ( $analyze_directories as $dir ) {
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
		$analyze_directories = array( 'inc', 'template-parts', 'parts', 'patterns', 'blocks', 'tests' );
		foreach ( $analyze_directories as $dir ) {
			if ( \is_dir( $project_dir . '/' . $dir ) ) {
				$config['parameters']['paths'][] = $project_dir . '/' . $dir;
			}
		}

		$analyze_files = array( 'functions.php', 'index.php' );
		foreach ( $analyze_files as $file ) {
			if ( \is_file( $project_dir . '/' . $file ) ) {
				$config['parameters']['paths'][] = $project_dir . '/' . $file;
			}
		}
	}

	// WPCompat needs EITHER pluginFile OR requiresAtLeast set or it errors per-file.
	// Themes fall through to requiresAtLeast — WPCompat has no themeFile key.
	if ( null !== $plugin_file ) {
		$config['parameters']['WPCompat']['pluginFile'] = $plugin_file;
	} else {
		$config['parameters']['WPCompat']['requiresAtLeast'] = '7.0';
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
};
