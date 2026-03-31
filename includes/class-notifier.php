<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSM_Notifier {

    public static function instance() {
        return new self();
    }

    /**
     * Send a monitored alert: stores it and notifies via Slack/webhook.
     *
     * @param string $level   'critical' | 'warning' | 'info'
     * @param string $message Human-readable message (no JSON dumps).
     * @param array  $context Optional structured data — NOT sent to Slack.
     */
    public static function send_alert( $level, $message, $context = array() ) {
        if ( ! in_array( $level, array( 'critical', 'warning', 'info' ), true ) ) {
            $level = 'info';
        }

        $payload = array(
            'timestamp' => current_time( 'mysql' ),
            'level'     => $level,
            'message'   => sanitize_text_field( $message ),
            'context'   => $context,
        );

        self::store_alert( $payload );

        if ( self::is_notification_enabled( $level ) ) {
            self::send_slack_notification( $payload );
            self::send_webhook_notification( $payload );
        }
    }

    /**
     * Log an event locally without sending notifications.
     */
    public static function log_event( $level, $message, $context = array() ) {
        $payload = array(
            'timestamp' => current_time( 'mysql' ),
            'level'     => $level,
            'message'   => sanitize_text_field( $message ),
            'context'   => $context,
        );

        self::store_alert( $payload );
    }

    /**
     * Store alert in WP options, capped at the configured max.
     */
    public static function store_alert( $payload ) {
        $max    = class_exists( 'WPSM_Settings' ) ? WPSM_Settings::get_max_stored_alerts() : 100;
        $alerts = get_option( 'wpsm_recent_alerts', array() );
        array_unshift( $alerts, $payload );
        $alerts = array_slice( $alerts, 0, $max );
        update_option( 'wpsm_recent_alerts', $alerts );
    }

    public static function get_recent_alerts( $limit = 50 ) {
        $alerts = get_option( 'wpsm_recent_alerts', array() );
        return array_slice( $alerts, 0, $limit );
    }

    /**
     * Send a daily health report to Slack.
     * Triggered by wpsm_daily_health_report cron.
     */
    public static function send_daily_health_report() {
        $settings = WPSM_Settings::get_settings();
        $webhook  = isset( $settings['slack_webhook_url'] ) ? esc_url_raw( $settings['slack_webhook_url'] ) : '';

        if ( empty( $webhook ) ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();

        // --- Gather health data ---

        // WordPress version / update status.
        $wp_version    = get_bloginfo( 'version' );
        $core_update   = get_site_transient( 'update_core' );
        $wp_update_msg = '✅ Up to date';
        if ( ! empty( $core_update->updates ) ) {
            $latest        = $core_update->updates[0]->version ?? '?';
            $wp_update_msg = "⚠️ Update available: {$latest}";
        }

        // Plugin updates — resolve human-readable names.
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugin_updates = get_site_transient( 'update_plugins' );
        $plugin_msg     = '✅ All up to date';
        if ( ! empty( $plugin_updates->response ) ) {
            $plugin_names = array();
            foreach ( $plugin_updates->response as $file => $data ) {
                $info           = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
                $plugin_names[] = ! empty( $info['Name'] ) ? $info['Name'] : $file;
            }
            $count      = count( $plugin_names );
            $names_list = implode( ', ', $plugin_names );
            $plugin_msg = "⚠️ {$count} update(s): {$names_list}";
        }

        // Theme updates.
        $theme_updates = get_site_transient( 'update_themes' );
        $theme_msg     = '✅ All up to date';
        if ( ! empty( $theme_updates->response ) ) {
            $theme_names = array_keys( $theme_updates->response );
            $count       = count( $theme_names );
            $theme_msg   = "⚠️ {$count} update(s): " . implode( ', ', $theme_names );
        }

        // SSL status.
        $ssl_msg = self::get_ssl_status_message();

        // Database size.
        global $wpdb;
        $db_bytes = (float) $wpdb->get_var( "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()" );
        $db_msg   = '✅ ' . self::format_bytes( $db_bytes );

        // Memory usage.
        $mem_used  = memory_get_peak_usage( true );
        $mem_limit = self::parse_memory_limit( ini_get( 'memory_limit' ) );
        $mem_pct   = $mem_limit > 0 ? (int) round( ( $mem_used / $mem_limit ) * 100 ) : 0;
        $mem_icon  = $mem_pct >= 90 ? '🔴' : ( $mem_pct >= 75 ? '🟡' : '✅' );
        $mem_msg   = "{$mem_icon} {$mem_pct}% — " . size_format( $mem_used, 1 ) . ' of ' . size_format( $mem_limit, 1 );

        // Recent alerts summary (last 24 hours).
        $alerts    = get_option( 'wpsm_recent_alerts', array() );
        $since     = strtotime( '-24 hours', current_time( 'timestamp' ) );
        $counts    = array( 'critical' => 0, 'warning' => 0, 'info' => 0 );
        foreach ( $alerts as $a ) {
            $ts = strtotime( $a['timestamp'] );
            if ( $ts >= $since && isset( $counts[ $a['level'] ] ) ) {
                $counts[ $a['level'] ]++;
            }
        }
        $alerts_prefix = $counts['critical'] > 0 ? '🔴' : ( $counts['warning'] > 0 ? '🟡' : '✅' );
        $alerts_msg    = "{$alerts_prefix} {$counts['critical']} critical, {$counts['warning']} warnings, {$counts['info']} info";

        $text = implode( "\n", array(
            "📊 *Daily Health Report* | <{$site_url}|{$site_name}>",
            "━━━━━━━━━━━━━━━━━━━━",
            "*WordPress {$wp_version}:* {$wp_update_msg}",
            "*Plugins:* {$plugin_msg}",
            "*Themes:* {$theme_msg}",
            "*SSL:* {$ssl_msg}",
            "*Database:* {$db_msg}",
            "*Memory:* {$mem_msg}",
            "*Last 24h alerts:* {$alerts_msg}",
        ) );

        $payload = array( 'text' => $text );

        wp_remote_post( $webhook, array(
            'headers'   => array( 'Content-Type' => 'application/json' ),
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 5,
            'sslverify' => true,
            'blocking'  => false,
        ) );
    }

    /**
     * Send a critical alert via raw curl — used when WP may be broken.
     * Called from the PHP-level shutdown handler.
     */
    public static function send_critical_via_curl( $message ) {
        if ( ! function_exists( 'curl_init' ) ) {
            return;
        }

        $settings = WPSM_Settings::get_settings();
        $webhook  = isset( $settings['slack_webhook_url'] ) ? $settings['slack_webhook_url'] : '';

        if ( empty( $webhook ) ) {
            return;
        }

        $site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : ( defined( 'DB_NAME' ) ? DB_NAME : 'WordPress' );
        $site_url  = function_exists( 'home_url' ) ? home_url() : '';

        $text = "🔴 *[CRITICAL]* | {$site_name}\n{$message}";
        if ( $site_url ) {
            $text .= "\n<{$site_url}|View site>";
        }

        // Also log to WP options if available.
        if ( function_exists( 'get_option' ) ) {
            self::store_alert( array(
                'timestamp' => current_time( 'mysql' ),
                'level'     => 'critical',
                'message'   => $message,
                'context'   => array(),
            ) );
        }

        $body = wp_json_encode( array( 'text' => $text ) );

        $ch = curl_init( $webhook );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 3 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
        curl_exec( $ch );
        curl_close( $ch );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function is_notification_enabled( $level ) {
        $settings = WPSM_Settings::get_settings();
        $levels   = isset( $settings['alert_levels'] ) ? (array) $settings['alert_levels'] : array( 'critical', 'warning', 'info' );
        return in_array( $level, $levels, true );
    }

    /**
     * Build a clean, emoji-colored Slack message.
     * Only includes the level dot, site name, and the human message — no JSON.
     */
    private static function build_slack_text( $data ) {
        $dots = array(
            'critical' => '🔴',
            'warning'  => '🟡',
            'info'     => '🔵',
        );

        $dot       = $dots[ $data['level'] ] ?? '⚪';
        $level     = strtoupper( $data['level'] );
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url();
        $time      = wp_date( 'g:i A', strtotime( $data['timestamp'] ) );

        return "{$dot} *[{$level}]* | <{$site_url}|{$site_name}>\n{$data['message']}\n_{$time}_";
    }

    private static function send_slack_notification( $data ) {
        $settings = WPSM_Settings::get_settings();
        $webhook  = isset( $settings['slack_webhook_url'] ) ? esc_url_raw( $settings['slack_webhook_url'] ) : '';

        if ( empty( $webhook ) ) {
            return;
        }

        $payload = array( 'text' => self::build_slack_text( $data ) );

        wp_remote_post( $webhook, array(
            'headers'   => array( 'Content-Type' => 'application/json' ),
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 5,
            'sslverify' => true,
            'blocking'  => false,
        ) );
    }

    private static function send_webhook_notification( $data ) {
        $settings = WPSM_Settings::get_settings();
        $url      = isset( $settings['generic_webhook_url'] ) ? esc_url_raw( $settings['generic_webhook_url'] ) : '';

        if ( empty( $url ) ) {
            return;
        }

        // Generic webhook gets structured JSON (useful for external apps).
        $payload = array(
            'site'      => get_bloginfo( 'name' ),
            'site_url'  => home_url(),
            'timestamp' => $data['timestamp'],
            'level'     => $data['level'],
            'message'   => $data['message'],
        );

        wp_remote_post( $url, array(
            'headers'   => array( 'Content-Type' => 'application/json' ),
            'body'      => wp_json_encode( $payload ),
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => true,
        ) );
    }

    private static function get_ssl_status_message() {
        $host = parse_url( home_url(), PHP_URL_HOST );
        if ( ! $host ) {
            return '⚠️ Unable to resolve host';
        }

        $context = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => true ) ) );
        $stream  = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context );

        if ( ! $stream ) {
            return '🔴 Could not connect';
        }

        $params = stream_context_get_params( $stream );
        fclose( $stream );

        if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
            return '⚠️ Certificate unreadable';
        }

        $cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
        if ( ! isset( $cert['validTo_time_t'] ) ) {
            return '⚠️ Expiry unreadable';
        }

        $days = (int) round( ( $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
        if ( $days < 14 ) {
            return "🔴 Expires in {$days} days";
        }
        if ( $days < 30 ) {
            return "🟡 Expires in {$days} days";
        }
        return "✅ Valid ({$days} days remaining)";
    }

    private static function parse_memory_limit( $val ) {
        $val  = trim( $val );
        $last = strtolower( $val[ strlen( $val ) - 1 ] );
        $num  = (int) $val;
        switch ( $last ) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    private static function format_bytes( $bytes ) {
        if ( $bytes >= 1073741824 ) {
            return round( $bytes / 1073741824, 2 ) . ' GB';
        }
        if ( $bytes >= 1048576 ) {
            return round( $bytes / 1048576, 2 ) . ' MB';
        }
        return round( $bytes / 1024, 2 ) . ' KB';
    }
}
