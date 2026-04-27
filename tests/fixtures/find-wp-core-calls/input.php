<?php

add_action( 'test', function() {} );
WP_User_Meta_Session_Tokens::get_instance( 0 );

function test(): ?WP_Filesystem_Base {
	global $wp_filesystem;
	wp_filesystem();

	return $wp_filesystem;
}

function test2(): WP_Filesystem_SSH2 {
	global $wp_filesystem;
	wp_filesystem();

	return $wp_filesystem;
}

function test3( WP_Filesystem_FTPext $param, ?WP_Filesystem_FTPext $param2, string $param3, $param4 ): WP_Filesystem_ftpsockets {
	global $wp_filesystem;
	WP_Filesystem();

	return $wp_filesystem;
}

class input extends WP_Filesystem_Direct {

}
