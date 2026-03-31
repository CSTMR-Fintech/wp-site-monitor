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

// --- Remove watchdog mu-plugin ---
$watchdog = trailingslashit( WPMU_PLUGIN_DIR ) . 'wpsm-watchdog.php';
if ( file_exists( $watchdog ) ) {
    unlink( $watchdog );
}

// --- Remove lock file ---
$lock = trailingslashit( WP_CONTENT_DIR ) . '.wpsm-watchdog.lock';
if ( file_exists( $lock ) ) {
    unlink( $lock );
}
