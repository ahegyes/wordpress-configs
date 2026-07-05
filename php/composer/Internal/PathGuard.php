<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Composer\Internal;

/**
 * Pure path-safety predicates shared by the dependency-scoping guards. Each normalises `\` to `/`
 * and answers one question about a path — absolute, traversing, or contained — without throwing, so
 * every caller keeps its own rejection and message. Consolidating the detection here means a future
 * path-safety fix lands in one place rather than in each guard that needs it.
 *
 * @internal
 */
final class PathGuard {

	/**
	 * Reports whether a path is absolute: rooted at `/` or carrying a Windows drive letter (`C:/`).
	 *
	 * @param string $path Path to inspect.
	 *
	 * @return bool
	 */
	public static function is_absolute( string $path ): bool {
		$normalised = \str_replace( '\\', '/', $path );

		return \str_starts_with( $normalised, '/' ) || 1 === \preg_match( '#^[A-Za-z]:/#', $normalised );
	}

	/**
	 * Reports whether a path contains a parent-directory (`..`) segment on either slash.
	 *
	 * @param string $path Path to inspect.
	 *
	 * @return bool
	 */
	public static function contains_traversal( string $path ): bool {
		foreach ( \explode( '/', \str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '..' === $segment ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether one already-resolved absolute path sits inside another. Operates on paths the
	 * caller has already passed through `realpath()`; containment is a byte-prefix test against the
	 * base plus a directory separator, so a sibling sharing the base's name (`/a/bc` under `/a/b`) is
	 * never treated as contained. `$allow_equal` decides whether the candidate equalling the base
	 * itself counts as within.
	 *
	 * @param string $real_candidate Resolved absolute path being tested.
	 * @param string $real_base      Resolved absolute base directory.
	 * @param bool   $allow_equal    Whether the candidate equalling the base counts as within.
	 *
	 * @return bool
	 */
	public static function is_within( string $real_candidate, string $real_base, bool $allow_equal ): bool {
		return ( $allow_equal && $real_candidate === $real_base ) || \str_starts_with( $real_candidate, $real_base . DIRECTORY_SEPARATOR );
	}
}
