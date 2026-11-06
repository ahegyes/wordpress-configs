<?php declare( strict_types=1 );

// Plugin Check reports an updater routine and unescaped output here unless it skips vendor-prefixed/.
add_filter( 'pre_set_site_transient_update_plugins', 'acme_updater_check' );

function acme_updater_check( $transient ) {
	echo $_GET['acme'];
	return $transient;
}
