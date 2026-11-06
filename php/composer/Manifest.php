<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer;

/**
 * Reads Composer's JSON files and the extra entries the scoping pipeline takes from a manifest.
 *
 * @internal
 */
final class Manifest {
	// region MAGIC METHODS

	/**
	 * Constructor.
	 */
	protected function __construct() {}

	// endregion

	// region METHODS

	/**
	 * Returns the decoded contents of a Composer JSON file.
	 *
	 * @param   string $file The path of the file.
	 *
	 * @throws  \RuntimeException Thrown when the file cannot be read.
	 *
	 * @return  array<array-key, mixed>
	 */
	public static function read( string $file ): array {
		$contents = \is_file( $file ) ? \file_get_contents( $file ) : false;
		if ( false === $contents ) {
			throw new \RuntimeException( "Could not read '$file'." );
		}

		$decoded = \json_decode( $contents, true, flags: \JSON_THROW_ON_ERROR );
		return \is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Returns the extra entries a decoded manifest declares.
	 *
	 * @param   array<array-key, mixed> $manifest The decoded manifest.
	 *
	 * @return  array<array-key, mixed>
	 */
	public static function extra( array $manifest ): array {
		return isset( $manifest['extra'] ) && \is_array( $manifest['extra'] ) ? $manifest['extra'] : array();
	}

	/**
	 * Returns the scoping prefix a project's manifest declares under extra.scoping-prefix, without leading or trailing backslashes.
	 *
	 * @param   string $project_dir The root directory of the project.
	 *
	 * @throws  \RuntimeException Thrown when the manifest cannot be read or declares no scoping prefix.
	 *
	 * @return  string
	 */
	public static function scoping_prefix( string $project_dir ): string {
		$prefix = self::extra( self::read( $project_dir . '/composer.json' ) )['scoping-prefix'] ?? null;
		$prefix = \is_string( $prefix ) ? \trim( $prefix, '\\' ) : '';
		if ( '' === $prefix ) {
			throw new \RuntimeException( "'$project_dir/composer.json' declares no extra.scoping-prefix." );
		}

		return $prefix;
	}

	// endregion
}
