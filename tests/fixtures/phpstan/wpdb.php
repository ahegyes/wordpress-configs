<?php declare( strict_types=1 );

namespace Acme\Fixtures;

function read_title( int $post_id ): ?string {
	global $wpdb;

	$title = $wpdb->get_var( $wpdb->prepare( 'SELECT post_title FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );

	return \is_string( $title ) ? $title : null;
}

function flush_connection( ?\wpdb $wpdb ): void {
	$wpdb?->flush();
}

function describe_connection( mixed $wpdb ): string {
	return \is_string( $wpdb ) ? $wpdb : 'connection';
}
