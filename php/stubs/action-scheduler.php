<?php
/**
 * Action Scheduler public API stubs — a parse-only catalog for the scoping pipeline.
 *
 * Declared via this repo's own `extra.scoping-stubs` entry so the `as_*` function family is
 * always excluded from php-scoper prefixing in every consumer that installs this package.
 * Action Scheduler is a host-provided runtime dependency (bundled by WooCommerce or the
 * standalone plugin) and is never shipped scoped; a prefixed `as_*` call is an
 * undefined-function fatal at runtime. Keeping the catalog here means the exclusion survives
 * a consumer dropping `php-stubs/woocommerce-stubs` (whose secondary
 * `woocommerce-packages-stubs.php` file is the only other source of these symbols).
 *
 * Signatures mirror Action Scheduler's `functions.php` as published in
 * `php-stubs/woocommerce-stubs`. Never autoloaded or executed — parsed only by
 * CollectScopingStubs for symbol names.
 */

function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}

function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}

function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}

function as_schedule_cron_action( $timestamp, $schedule, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {}

function as_unschedule_action( $hook, $args = array(), $group = '' ) {}

function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {}

function as_next_scheduled_action( $hook, $args = null, $group = '' ) {}

function as_has_scheduled_action( $hook, $args = null, $group = '' ) {}

function as_get_scheduled_actions( $args = array(), $return_format = OBJECT ) {}

function as_get_datetime_object( $date_string = null, $timezone = 'UTC' ) {}

function as_supports( string $feature ): bool {}
