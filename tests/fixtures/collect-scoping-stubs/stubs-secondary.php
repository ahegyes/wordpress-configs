<?php

// Minimal secondary-catalog stubs for unit-testing CollectScopingStubs explicit-file
// resolution. Stand-in for woocommerce-stubs' woocommerce-packages-stubs.php (the
// Action Scheduler as_* functions), which the package ships WITHOUT listing in its
// autoload.files — so only the explicit-file declaration form can reach it.

class ActionScheduler_Store {}

function as_schedule_single_action() {}
function as_next_scheduled_action() {}
