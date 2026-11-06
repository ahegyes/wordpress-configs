<?php declare( strict_types=1 );
/**
 * Returns the php-scoper config that prefixes the packages a project names, and their runtime dependencies, with the project's own namespace.
 *
 * @package DeepWebSolutions\Config
 */

use DeepWebSolutions\Config\Composer\GlobalSymbols;
use DeepWebSolutions\Config\Composer\Manifest;
use DeepWebSolutions\Config\Composer\ScopedPackages;
use Symfony\Component\Finder\Finder;

/**
 * Returns the php-scoper config for a project.
 *
 * @param   string $project_dir The root directory of the project, which holds composer.json.
 * @param   string ...$names    The packages to scope, as names or fnmatch() patterns, where a name that starts with ! drops the packages it matches.
 *
 * @throws  RuntimeException Thrown when composer.json declares no scoping prefix, or no text domain while a scoped package declares one, when a package to scope is missing, or when a recipe's target is missing.
 *
 * @return  array<string, mixed>
 */
return static function ( string $project_dir, string ...$names ): array {
	$project = Manifest::read( "$project_dir/composer.json" );
	$prefix  = Manifest::scoping_prefix( $project_dir );

	$vendor     = is_array( $project['config'] ?? null ) && is_string( $project['config']['vendor-dir'] ?? null ) ? $project['config']['vendor-dir'] : 'vendor';
	$vendor_dir = str_starts_with( $vendor, '/' ) ? $vendor : "$project_dir/$vendor";
	$packages   = ScopedPackages::resolve( $vendor_dir, array_values( $names ) );
	if ( array() === $packages['scoped'] ) {
		throw new RuntimeException( "No package to scope is installed in '$vendor_dir'." ); // Without finders, php-scoper scopes the whole working directory.
	}
	$symbols = GlobalSymbols::collect( $project_dir, array_values( $packages['scoped'] ), array_values( $packages['dropped'] ) );

	$exclude_files      = array();
	$exclude_namespaces = array();
	$patchers           = array();
	$roots              = array();
	$domains            = array();
	foreach ( $packages['scoped'] as $name => $dir ) {
		$package  = Manifest::read( "$dir/composer.json" );
		$domain   = Manifest::extra( $package )['scoping-text-domain'] ?? null;
		$autoload = isset( $package['autoload'] ) && is_array( $package['autoload'] ) ? $package['autoload'] : array();
		if ( ! is_null( $domain ) ) {
			$domains[ $dir ] = is_string( $domain ) && '' !== $domain ? $domain : throw new RuntimeException( "'$dir/composer.json' declares an empty extra.scoping-text-domain." );
		}
		foreach ( array( 'psr-4', 'psr-0' ) as $standard ) {
			foreach ( array_keys( (array) ( $autoload[ $standard ] ?? array() ) ) as $namespace ) {
				if ( str_contains( (string) $namespace, '\\' ) ) {
					$roots[] = trim( (string) $namespace, '\\' );
				}
			}
		}

		$recipe_file = __DIR__ . "/recipes/$name.inc.php";
		if ( ! is_file( $recipe_file ) ) {
			continue;
		}
		/**
		 * A recipe returns php-scoper settings for one package, with paths relative to it.
		 *
		 * @var array{exclude-files?: list<string>, exclude-namespaces?: list<string>, replacements?: array<string, array<string, string>>} $recipe
		 */
		$recipe  = require $recipe_file;
		$targets = array_merge( $recipe['exclude-files'] ?? array(), array_keys( $recipe['replacements'] ?? array() ) );
		foreach ( $targets as $path ) {
			if ( ! is_file( "$dir/$path" ) ) {
				throw new RuntimeException( "The '$name' recipe targets '$path', which the installed package no longer ships." );
			}
		}
		foreach ( $recipe['exclude-files'] ?? array() as $excluded_file ) {
			$exclude_files[] = "$dir/$excluded_file";
		}
		array_push( $exclude_namespaces, ...( $recipe['exclude-namespaces'] ?? array() ) );
		foreach ( $recipe['replacements'] ?? array() as $target => $replacements ) {
			$patchers[] = static function ( string $file_path, string $prefix, string $contents ) use ( $name, $dir, $target, $replacements ): string {
				if ( "$dir/$target" !== $file_path ) {
					return $contents;
				}
				foreach ( $replacements as $search => $replace ) {
					$search = str_replace( '{prefix}', $prefix, (string) $search );
					if ( ! str_contains( $contents, $search ) ) {
						throw new RuntimeException( "The '$name' recipe expects '$search' in '$target', which the installed package no longer contains." );
					}
					$contents = str_replace( $search, str_replace( '{prefix}', $prefix, $replace ), $contents );
				}

				return $contents;
			};
		}
	}

	$text_domain = Manifest::extra( $project )['text-domain'] ?? null;
	if ( array() !== $domains && ( ! is_string( $text_domain ) || '' === $text_domain ) ) {
		throw new RuntimeException( "'$project_dir/composer.json' declares no extra.text-domain, which the strings of the scoped packages take." );
	}
	$domains            = array_filter( $domains, static fn ( string $domain ): bool => $text_domain !== $domain );
	$exclude_namespaces = array( ...$exclude_namespaces, ...$symbols['namespaces'] );

	// Libraries build class names in strings php-scoper cannot see, and a string that starts with a scoped package's own namespace names one of its classes.
	if ( array() !== $roots ) {
		$separated  = static fn ( string $name ): string => implode( '\\\\{1,2}', array_map( static fn ( string $segment ): string => preg_quote( $segment, '/' ), explode( '\\', $name ) ) );
		$own        = '/^(\\\\{0,2})((?:' . implode( '|', array_map( $separated, array_unique( $roots ) ) ) . ')(\\\\{1,2}))/';
		$patchers[] = static function ( string $file_path, string $prefix, string $contents ) use ( $own, $separated, $exclude_namespaces ): string {
			if ( ! str_ends_with( $file_path, '.php' ) ) {
				return $contents;
			}
			$scoped = '/^\\\\{0,2}' . $separated( $prefix ) . '\\\\{1,2}/';
			$tokens = PhpToken::tokenize( $contents );
			foreach ( $tokens as $index => $token ) {
				$starts_string = $token->is( T_CONSTANT_ENCAPSED_STRING ) || ( $token->is( T_ENCAPSED_AND_WHITESPACE ) && $index > 0 && ( '"' === $tokens[ $index - 1 ]->text || $tokens[ $index - 1 ]->is( T_START_HEREDOC ) ) );
				if ( ! $starts_string ) {
					continue;
				}
				$opening = $token->is( T_CONSTANT_ENCAPSED_STRING ) ? strcspn( $token->text, '\'"' ) + 1 : 0;
				$body    = substr( $token->text, $opening );
				if ( 1 === preg_match( $scoped, $body ) || 1 !== preg_match( $own, $body, $match ) ) {
					continue;
				}
				// A string that names a namespace php-scoper keeps global, or a symbol in one, names the host's symbol, and a name the string follows with a separator is a namespace.
				$named     = 1 === preg_match( '/^\\\\{0,2}((?:[\w\x80-\xff]+\\\\{1,2})*[\w\x80-\xff]+)(\\\\?)/', $body, $name_match ) ? (string) preg_replace( '/\\\\{2}/', '\\', $name_match[1] ) : '';
				$namespace = '' === ( $name_match[2] ?? '' ) ? substr( $named, 0, (int) strrpos( $named, '\\' ) ) : $named;
				if ( array_any( $exclude_namespaces, static fn ( string $pattern ): bool => 1 === preg_match( $pattern, $named ) || 1 === preg_match( $pattern, $namespace ) ) ) {
					continue;
				}
				// Double-quoted strings and heredocs read escape sequences, so a prefix segment such as Vendor\name takes doubled backslashes there.
				$escapes     = $token->is( T_CONSTANT_ENCAPSED_STRING ) ? str_contains( substr( $token->text, 0, $opening ), '"' ) : ! str_contains( $tokens[ $index - 1 ]->text, "'" );
				$separator   = $escapes ? '\\\\' : $match[3];
				$token->text = substr( $token->text, 0, $opening ) . $match[1] . str_replace( '\\', $separator, $prefix ) . $separator . substr( $body, strlen( $match[1] ) );
			}

			return implode( '', array_column( $tokens, 'text' ) );
		};
	}

	if ( array() !== $domains ) {
		$rewrite    = static function ( string $code, string $domain, string $file_path ) use ( &$rewrite, $text_domain ): string {
			$tokens = PhpToken::tokenize( $code );
			foreach ( $tokens as $index => $token ) {
				if ( $token->is( T_CONSTANT_ENCAPSED_STRING ) && in_array( $token->text, array( "'$domain'", "\"$domain\"" ), true ) ) {
					$token->text = var_export( $text_domain, true );
				} elseif ( $token->is( T_ENCAPSED_AND_WHITESPACE ) && $index > 0 && $tokens[ $index - 1 ]->is( T_START_HEREDOC ) && str_contains( $tokens[ $index - 1 ]->text, "'" ) && str_starts_with( ltrim( $token->text ), '<?php' ) ) {
					// php-scoper scopes the PHP code of a nowdoc like a file, so its literals take the project's domain too.
					$token->text = $rewrite( $token->text, $domain, $file_path );
				} elseif ( ! $token->is( array( T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML ) ) && 1 === preg_match( '/(?<![\w-])' . preg_quote( $domain, '/' ) . '(?![\w-])/', $token->text ) ) {
					throw new RuntimeException( "'$file_path' still contains the text domain '$domain' after scoping, outside a string literal of its own." );
				}
			}

			return implode( '', array_column( $tokens, 'text' ) );
		};
		$patchers[] = static function ( string $file_path, string $prefix, string $contents ) use ( $domains, $rewrite ): string {
			foreach ( $domains as $dir => $domain ) {
				if ( str_starts_with( $file_path, "$dir/" ) && str_ends_with( $file_path, '.php' ) ) {
					$contents = $rewrite( $contents, $domain, $file_path );
				}
			}

			return $contents;
		};
	}

	return array(
		'prefix'                  => $prefix,
		// A package's tests run against development tools that are never scoped, so they would only ship dead, unresolvable code.
		'finders'                 => array( Finder::create()->files()->in( array_values( $packages['scoped'] ) )->exclude( array( 'tests', 'Tests' ) ) ),
		'exclude-files'           => $exclude_files,
		'exclude-namespaces'      => $exclude_namespaces,
		'exclude-classes'         => $symbols['classes'],
		'exclude-functions'       => $symbols['functions'],
		'exclude-constants'       => $symbols['constants'],
		'expose-global-classes'   => false,
		'expose-global-functions' => false,
		'patchers'                => $patchers,
	);
};
