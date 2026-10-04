<?php declare( strict_types=1 );
/**
 * Returns the php-scoper config that prefixes a project's framework packages, PHP-DI and the PSR interfaces with the project's own namespace.
 *
 * @package DeepWebSolutions\Config
 */

use DeepWebSolutions\Config\Composer\CollectScopingStubs;
use Symfony\Component\Finder\Finder;

/**
 * Returns the php-scoper config for a project.
 *
 * @param   string $project_dir The root directory of the project, which holds composer.json and vendor/.
 * @param   string ...$packages Optional. More installed packages to scope, such as dompdf/dompdf. Default is none.
 *
 * @throws  RuntimeException Thrown when composer.json declares no scoping prefix or text domain, or when no package to scope is installed.
 *
 * @return  array<string, mixed>
 */
return static function ( string $project_dir, string ...$packages ): array {
	$manifest    = json_decode( (string) file_get_contents( "$project_dir/composer.json" ), true, flags: JSON_THROW_ON_ERROR );
	$extra       = is_array( $manifest ) && isset( $manifest['extra'] ) && is_array( $manifest['extra'] ) ? $manifest['extra'] : array();
	$prefix      = $extra['scoping-prefix'] ?? null;
	$text_domain = $extra['text-domain'] ?? null;
	if ( ! is_string( $prefix ) || '' === $prefix ) {
		throw new RuntimeException( "'$project_dir/composer.json' declares no extra.scoping-prefix." );
	}
	if ( false !== $text_domain && ( ! is_string( $text_domain ) || '' === $text_domain ) ) {
		throw new RuntimeException( "'$project_dir/composer.json' declares no extra.text-domain. Set it to the plugin's text domain, or to false when no framework string ships." );
	}

	$vendor_dir   = "$project_dir/vendor";
	$package_dirs = array();
	foreach ( array( 'ahegyes/wp-framework-*', 'php-di/*', 'laravel/serializable-closure', 'psr/container', 'psr/log', ...$packages ) as $pattern ) {
		$matches = glob( "$vendor_dir/$pattern", GLOB_ONLYDIR );
		if ( false === $matches ) {
			throw new RuntimeException( "Could not list '$vendor_dir/$pattern'." );
		}
		$package_dirs = array_merge( $package_dirs, $matches );
	}
	$package_dirs = array_values( array_unique( $package_dirs ) );
	if ( array() === $package_dirs ) {
		throw new RuntimeException( "No package to scope is installed in '$vendor_dir'." ); // Without finders, php-scoper scopes the whole working directory.
	}

	// A single-vendor closure needs its vendor directory set here, because php-scoper writes every file below the deepest directory its inputs share.
	$vendors    = array_unique( array_map( static fn ( string $dir ): string => basename( dirname( $dir ) ), $package_dirs ) );
	$output_dir = "$project_dir/vendor-prefixed";
	if ( 1 === count( $vendors ) ) {
		$output_dir .= '/' . reset( $vendors ) . ( 1 === count( $package_dirs ) ? '/' . basename( $package_dirs[0] ) : '' );
	}

	$symbols = CollectScopingStubs::collect( $package_dirs );

	return array(
		'prefix'            => $prefix,
		'output-dir'        => $output_dir,
		'finders'           => array( Finder::create()->files()->in( $package_dirs ) ),
		// PHP-DI renders this file as a raw template, which a prefixed namespace declaration would break.
		'exclude-files'     => array_filter( array( "$vendor_dir/php-di/php-di/src/Compiler/Template.php" ), 'is_file' ),
		'exclude-classes'   => $symbols['classes'],
		'exclude-functions' => $symbols['functions'],
		'exclude-constants' => $symbols['constants'],
		'patchers'          => array(
			static function ( string $file_path, string $prefix, string $contents ): string {
				if ( ! str_ends_with( $file_path, '/php-di/php-di/src/Invoker/FactoryParameterResolver.php' ) ) {
					return $contents;
				}

				return str_replace(
					array( "'Psr\\Container\\ContainerInterface'", "'DI\\Factory\\RequestedEntry'" ),
					array( "'$prefix\\Psr\\Container\\ContainerInterface'", "'$prefix\\DI\\Factory\\RequestedEntry'" ),
					$contents
				);
			},
			static function ( string $file_path, string $prefix, string $contents ) use ( $text_domain ): string {
				if ( ! str_contains( $file_path, '/ahegyes/wp-framework-' ) || ! str_ends_with( $file_path, '.php' ) ) {
					return $contents;
				}

				if ( false !== $text_domain ) {
					$contents = str_replace( array( "'wp-framework'", '"wp-framework"' ), var_export( $text_domain, true ), $contents );
				}
				if ( 1 === preg_match( '/' . preg_quote( "$prefix\\", '/' ) . '(?:WC_|wc_|WC\(|woocommerce_|as_|Automattic\\\\WooCommerce\\\\)[\w\\\\]*|(?<=\')wp-framework(?=\')/', $contents, $residue ) ) {
					throw new RuntimeException( sprintf( "'%s' still contains '%s' after scoping: a host symbol the stubs do not declare has the prefix, or a framework string has the reserved text domain.", $file_path, $residue[0] ) );
				}

				return $contents;
			},
		),
	);
};
