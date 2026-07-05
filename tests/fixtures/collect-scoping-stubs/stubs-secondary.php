<?php

// Minimal secondary stubs file for unit-testing CollectScopingStubs explicit-file
// resolution. Stand-in for woocommerce-stubs' woocommerce-packages-stubs.php, which
// the package ships WITHOUT listing in its autoload.files — so only the explicit-file
// declaration form can reach it.

class WC_Packages_Container {}

function wc_get_container() {}
function wc_package_feature_is_enabled() {}
