<?php declare( strict_types=1 );

namespace DeepWebSolutions\Config\Tests\Unit;

use DeepWebSolutions\Config\Composer\Internal\PathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( PathGuard::class )]
final class PathGuardTest extends TestCase {

	#[Test]
	public function is_absolute_detects_rooted_and_windows_drive_paths(): void {
		self::assertTrue( PathGuard::is_absolute( '/etc/x' ), 'A leading slash is absolute.' );
		self::assertTrue( PathGuard::is_absolute( 'C:/x' ), 'A forward-slashed Windows drive is absolute.' );
		self::assertTrue( PathGuard::is_absolute( 'C:\\x' ), 'A backslashed Windows drive is absolute after normalisation.' );

		self::assertFalse( PathGuard::is_absolute( 'src/x' ), 'A bare relative path is not absolute.' );
		self::assertFalse( PathGuard::is_absolute( './x' ), 'A dot-relative path is not absolute.' );
		self::assertFalse( PathGuard::is_absolute( 'a/b' ), 'A nested relative path is not absolute.' );
	}

	#[Test]
	public function contains_traversal_detects_parent_segments_on_either_slash(): void {
		self::assertTrue( PathGuard::contains_traversal( '../x' ), 'A leading `..` segment traverses.' );
		self::assertTrue( PathGuard::contains_traversal( 'a/../b' ), 'A middle `..` segment traverses.' );
		self::assertTrue( PathGuard::contains_traversal( 'a\\..\\b' ), 'A backslashed `..` segment traverses after normalisation.' );

		self::assertFalse( PathGuard::contains_traversal( 'a/b/c' ), 'A plain nested path does not traverse.' );
		self::assertFalse( PathGuard::contains_traversal( '..foo/x' ), 'A `..`-prefixed name is not a `..` segment.' );
	}

	#[Test]
	public function is_within_requires_strict_descendant_unless_equal_is_allowed(): void {
		$base = '/a/b';

		// A genuine descendant is within whether or not equality is allowed.
		self::assertTrue( PathGuard::is_within( '/a/b/c', $base, false ), 'A descendant is within when equality is disallowed.' );
		self::assertTrue( PathGuard::is_within( '/a/b/c', $base, true ), 'A descendant is within when equality is allowed.' );

		// The base itself counts only when equality is allowed.
		self::assertTrue( PathGuard::is_within( $base, $base, true ), 'The base is within itself only when equality is allowed.' );
		self::assertFalse( PathGuard::is_within( $base, $base, false ), 'The base is not a strict descendant of itself.' );

		// A prefix-sharing sibling, an ancestor, and an unrelated path are never within, either way.
		foreach ( array( true, false ) as $allow_equal ) {
			self::assertFalse( PathGuard::is_within( '/a/bc', $base, $allow_equal ), 'A prefix-sharing sibling is not within.' );
			self::assertFalse( PathGuard::is_within( '/a', $base, $allow_equal ), 'An ancestor is not within.' );
			self::assertFalse( PathGuard::is_within( '/x/y', $base, $allow_equal ), 'An unrelated path is not within.' );
		}
	}
}
