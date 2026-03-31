<?php
/**
 * Plugin Name: WP Site Monitor — Watchdog
 * Description: Must-use companion for WP Site Monitor. Catches fatal PHP errors before WordPress fully loads and sends Slack alerts.
 * Version:     1.4.1
 */

define( 'WPSM_WATCHDOG_COOLDOWN', 300 ); // 5 minutes between repeated alerts for the same error.

/**
 * Register the shutdown function IMMEDIATELY — before any ABSPATH check.
 * This ensures it's in memory even if WordPress crashes before fully loading.
 */
register_shutdown_function( 'wpsm_watchdog_shutdown' );

// Safe to exit early for direct access now that the handler is already registered.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wpsm_watchdog_shutdown() {
    $error = error_get_last();

    $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

    if ( empty( $error ) || ! in_array( $error['type'], $fatal_types, true ) ) {
        return;
    }

    // Avoid double-alerting if the main plugin already handled it.
    if ( defined( 'WPSM_FATAL_HANDLED' ) ) {
        return;
    }

    $abspath = defined( 'ABSPATH' ) ? ABSPATH : '';
    $file    = $abspath ? str_replace( $abspath, '', $error['file'] ) : $error['file'];

    $message    = sprintf( 'Fatal PHP error: %s in %s on line %d', $error['message'], $file, $error['line'] );
    $error_hash = md5( $error['message'] . $error['file'] . $error['line'] );

    // Only send if this exact error hasn't been alerted in the last 5 minutes.
    if ( ! wpsm_watchdog_should_send( $error_hash ) ) {
        return;
    }

    wpsm_watchdog_send_slack( $message );
}

/**
 * Cooldown via lock file — no DB needed, works even when WP is broken.
 * Returns true if the alert should be sent, false if still within cooldown.
 */
function wpsm_watchdog_should_send( $error_hash ) {
    $lock_file = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( __DIR__ ) ) . '/.wpsm-watchdog.lock';

    if ( file_exists( $lock_file ) ) {
        $data = json_decode( file_get_contents( $lock_file ), true );
        if (
            isset( $data['hash'], $data['time'] ) &&
            $data['hash'] === $error_hash &&
            ( time() - (int) $data['time'] ) < WPSM_WATCHDOG_COOLDOWN
        ) {
            return false;
        }
    }

    file_put_contents( $lock_file, json_encode( array(
        'hash' => $error_hash,
        'time' => time(),
    ) ), LOCK_EX );

    return true;
}

function wpsm_watchdog_send_slack( $message ) {
    if ( ! function_exists( 'curl_init' ) ) {
        return;
    }

    $webhook = wpsm_watchdog_get_webhook();
    if ( empty( $webhook ) ) {
        return;
    }

    $site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : wpsm_watchdog_get_site_name();
    $site_url  = function_exists( 'home_url' ) ? home_url() : wpsm_watchdog_get_site_url();

    $text = "🚨🔴 *[CRITICAL]* | {$site_name}\n{$message}";
    if ( $site_url ) {
        $text .= "\n<{$site_url}|View site>";
    }

    $body = json_encode( array( 'text' => $text ) );
    if ( ! $body ) {
        return;
    }

    $ch = curl_init( $webhook );
    curl_setopt( $ch, CURLOPT_POST, true );
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 3 ); // Max 3s to connect.
    curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );         // Max 5s total — avoid gateway timeouts.
    curl_exec( $ch );
    curl_close( $ch );
}

/**
 * Read Slack webhook from WP options or directly from DB if WP is not loaded.
 */
function wpsm_watchdog_get_webhook() {
    if ( function_exists( 'get_option' ) ) {
        $settings = get_option( 'wpsm_settings', array() );
        return isset( $settings['slack_webhook_url'] ) ? $settings['slack_webhook_url'] : '';
    }

    if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) ) {
        return '';
    }

    try {
        $dsn    = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8';
        $pdo    = new PDO( $dsn, DB_USER, DB_PASSWORD, array( PDO::ATTR_TIMEOUT => 3 ) );

        // WP uses $table_prefix variable, not a constant — read it from globals.
        $prefix = isset( $GLOBALS['table_prefix'] ) ? $GLOBALS['table_prefix'] : 'wp_';

        $stmt = $pdo->prepare( "SELECT option_value FROM {$prefix}options WHERE option_name = 'wpsm_settings' LIMIT 1" );
        $stmt->execute();
        $row = $stmt->fetch( PDO::FETCH_ASSOC );

        if ( $row ) {
            $settings = maybe_unserialize( $row['option_value'] );
            if ( is_array( $settings ) && ! empty( $settings['slack_webhook_url'] ) ) {
                return $settings['slack_webhook_url'];
            }
        }
    } catch ( Exception $e ) {
        // Silently fail — we're already in an error state.
    }

    return '';
}

function wpsm_watchdog_get_site_name() {
    return defined( 'DB_NAME' ) ? DB_NAME : 'WordPress Site';
}

function wpsm_watchdog_get_site_url() {
    if ( defined( 'WP_HOME' ) )    return WP_HOME;
    if ( defined( 'WP_SITEURL' ) ) return WP_SITEURL;
    return '';
}
