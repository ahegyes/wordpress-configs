<?php declare( strict_types=1 );

use Symfony\Component\Finder\Finder;

/**
 * Scoping partial for `ahegyes/wp-framework-*` packages. Auto-detects which
 * are installed in the vendor folder; composer require dictates what gets scoped.
 *
 * When framework packages are present and the consumer's `composer.json` (at `$project_dir`,
 * defaulting to the parent of `$vendor_dir`) declares `extra.text-domain`, it also returns a
 * patcher that rewrites the framework's per-package `wp-framework-*` text domains (the SOURCE
 * domains framework gettext calls use, e.g. `wp-framework-bootstrap`) to the consumer's text
 * domain at scope time, so framework strings ship under the consumer's domain. The
 * `wp-framework-*` space is reserved for framework textdomains. Callers (a consumer's
 * `scoper.inc.php`) must forward the returned `patchers` into the base config's `patchers`
 * override to activate the rewrite.
 *
 * @throws \JsonException    If the consumer composer.json exists but cannot be parsed.
 * @throws \RuntimeException If glob() fails, the consumer composer.json cannot be read, or extra.text-domain is not a valid slug.
 */
return static function ( string $vendor_dir, string $project_dir = '' ): array {
	$packages = \glob( $vendor_dir . '/ahegyes/wp-framework-*', GLOB_ONLYDIR );
	if ( false === $packages ) {
		throw new \RuntimeException( sprintf( 'glob() failed for %s', $vendor_dir ) );
	}

	if ( 0 === count( $packages ) ) {
		return array(
			'finders'  => array(),
			'patchers' => array(),
		);
	}

	$finder = Finder::create()
		->files()
		->ignoreVCS( true )
		->name( array( '*.php', 'LICENSE', 'composer.json' ) )
		->exclude( array( 'tests', 'changelog' ) );

	foreach ( $packages as $dir ) {
		$finder->in( $dir );
	}

	// Resolve the consumer's text domain from its composer.json (parent of vendor when not given).
	$project_dir   = '' !== $project_dir ? $project_dir : \dirname( $vendor_dir );
	$patchers      = array();
	$composer_path = $project_dir . '/composer.json';
	if ( \is_file( $composer_path ) ) {
		$composer_contents = file_get_contents( $composer_path ) ?: throw new \RuntimeException( sprintf( 'Could not read %s', $composer_path ) );
		$composer_config   = json_decode( $composer_contents, true, flags: JSON_THROW_ON_ERROR );

		$target_text_domain = $composer_config['extra']['text-domain'] ?? null;
		if ( \is_string( $target_text_domain ) && '' !== $target_text_domain ) {
			if ( 1 !== \preg_match( '/^[a-z0-9][a-z0-9-]*$/', $target_text_domain ) ) {
				throw new \RuntimeException( \sprintf( 'Invalid "extra.text-domain" %s in %s — expected a lowercase slug (a-z, 0-9, hyphen).', \var_export( $target_text_domain, true ), $composer_path ) );
			}

			// Rewrites any `wp-framework-*` textdomain to the consumer's domain. Operates on tokenized
			// PHP so comments are untouched and non-PHP finder files (composer.json, LICENSE) are inert —
			// they tokenize as a single T_INLINE_HTML token. The prefix is start-anchored, so a
			// vendor-qualified package name like `ahegyes/wp-framework-core` is left alone.
			$framework_domain_prefix = 'wp-framework-';
			$patchers[]              = static function ( string $file_path, string $prefix, string $content ) use ( $framework_domain_prefix, $target_text_domain ): string {
				$replacement = \var_export( $target_text_domain, true );

				$out = '';
				foreach ( \token_get_all( $content ) as $token ) {
					if ( \is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
						$value = \substr( $token[1], 1, -1 );
						if ( \str_starts_with( $value, $framework_domain_prefix ) ) {
							$out .= $replacement;
							continue;
						}
					}
					$out .= \is_array( $token ) ? $token[1] : $token;
				}

				return $out;
			};
		}
	}

	return array(
		'finders'  => array( $finder ),
		'patchers' => $patchers,
	);
};
