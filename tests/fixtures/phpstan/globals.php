<?php declare( strict_types=1 );

namespace Acme\Fixtures;

/**
 * Returns a post's title.
 *
 * @global \wpdb $wpdb The database connection.
 */
function read_title( int $post_id ): ?string {
	global $wpdb;

	$title = $wpdb->get_var( $wpdb->prepare( 'SELECT post_title FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );

	return \is_string( $title ) ? $title : null;
}

/**
 * Returns the number of registered post types.
 *
 * @global array<string, \WP_Post_Type> $wp_post_types The registered post types.
 */
function count_post_types(): int {
	global $wp_post_types;

	return \count( $wp_post_types );
}

function flush_connection(): void {
	global $wpdb;

	$wpdb->flush();
}

final class CurrentPost {
	/**
	 * Returns the current post's title, after flushing the connection.
	 *
	 * @global \wpdb         $wpdb The database connection.
	 * @global \WP_Post|null $post The current post.
	 */
	public static function get_title(): string {
		global $wpdb, $post;

		$wpdb->flush();

		return null === $post ? '' : $post->post_title;
	}
}
