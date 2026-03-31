<?php
/**
 * Uninstall WP Site Monitor.
 * Runs automatically when the plugin is deleted from the WordPress admin.
 * Removes all stored options, the watchdog mu-plugin, and temp files.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit; // Must be called by WordPress uninstall process.
}

// --- Remove WP options ---
delete_option( 'wpsm_settings' );
delete_option( 'wpsm_recent_alerts' );

// --- Remove scheduled cron jobs ---
wp_clear_scheduled_hook( 'wpsm_hourly_checks' );
wp_clear_scheduled_hook( 'wpsm_daily_health_report' );
wp_clear_scheduled_hook( 'wpsm_daily_site_check' ); // legacy

// --- Remove watchdog mu-plugin ---
$watchdog = trailingslashit( WPMU_PLUGIN_DIR ) . 'wpsm-watchdog.php';
if ( file_exists( $watchdog ) ) {
    unlink( $watchdog );
}

// --- Remove lock files ---
$lock_watchdog = trailingslashit( WP_CONTENT_DIR ) . '.wpsm-watchdog.lock';
if ( file_exists( $lock_watchdog ) ) {
    unlink( $lock_watchdog );
}

$lock_shutdown = trailingslashit( WP_CONTENT_DIR ) . '.wpsm-shutdown.lock';
if ( file_exists( $lock_shutdown ) ) {
    unlink( $lock_shutdown );
}
