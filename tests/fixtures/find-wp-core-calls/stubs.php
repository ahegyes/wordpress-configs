<?php

// Minimal WordPress Core stubs for unit-testing FindWPCoreCalls.
// Mimics the shape of wordpress-stubs.php — only the entries referenced by
// fixture inputs need to exist here.

class WP_User_Meta_Session_Tokens {}
class WP_Filesystem_Base {}
class WP_Filesystem_SSH2 {}
class WP_Filesystem_FTPext {}
class WP_Filesystem_ftpsockets {}
class WP_Filesystem_Direct {}

function add_action() {}
function wp_filesystem() {}
function WP_Filesystem() {}
