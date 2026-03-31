<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSM_Monitor {

    private static $instance = null;
    private $settings = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->setup_hooks();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->settings = WPSM_Settings::get_settings();
    }

    public function setup_hooks() {
        add_action( 'wp_login_failed',    array( $this, 'track_failed_login' ) );
        add_action( 'user_login',         array( $this, 'track_successful_login' ), 10, 2 );
        add_action( 'wp',                 array( $this, 'track_slow_page_load' ) );
        add_action( 'shutdown',           array( $this, 'detect_wp_errors' ) );
        add_action( 'template_redirect',  array( $this, 'track_404' ) );
        add_action( 'wpsm_hourly_checks', array( $this, 'run_scheduled_checks' ) );
    }

    // -------------------------------------------------------------------------
    // Scheduled checks (run hourly via cron)
    // -------------------------------------------------------------------------

    public function run_scheduled_checks() {
        $this->check_core_updates();
        $this->check_plugin_theme_updates();
        $this->check_database_usage();
        $this->check_memory_usage();
        $this->check_site_reachability();
        $this->check_cron_health();
        $this->check_woocommerce_orders();
    }

    public function check_core_updates() {
        include_once ABSPATH . 'wp-admin/includes/update.php';
        $core = get_site_transient( 'update_core' );

        if ( empty( $core->updates ) ) {
            return;
        }

        $latest = $core->updates[0]->version ?? 'unknown';
        WPSM_Notifier::send_alert(
            'warning',
            sprintf( 'WordPress update available: %s (current: %s)', $latest, get_bloginfo( 'version' ) )
        );
    }

    public function check_plugin_theme_updates() {
        include_once ABSPATH . 'wp-admin/includes/update.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins = get_site_transient( 'update_plugins' );
        if ( ! empty( $plugins->response ) ) {
            $names = array();
            foreach ( $plugins->response as $file => $data ) {
                $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
                $names[]     = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $file;
            }
            WPSM_Notifier::send_alert(
                'warning',
                sprintf( '%d plugin update(s) available: %s', count( $names ), implode( ', ', $names ) )
            );
        }

        $themes = get_site_transient( 'update_themes' );
        if ( ! empty( $themes->response ) ) {
            $theme_names = array_keys( $themes->response );
            WPSM_Notifier::send_alert(
                'warning',
                sprintf( '%d theme update(s) available: %s', count( $theme_names ), implode( ', ', $theme_names ) )
            );
        }
    }

    public function check_memory_usage() {
        $used  = memory_get_peak_usage( true );
        $limit = $this->parse_memory_limit( ini_get( 'memory_limit' ) );

        if ( $limit <= 0 ) {
            return;
        }

        $percent = (int) round( ( $used / $limit ) * 100 );

        if ( $percent >= 90 ) {
            WPSM_Notifier::send_alert(
                'critical',
                sprintf( 'PHP memory usage at %d%% — %s of %s used.', $percent, size_format( $used, 1 ), size_format( $limit, 1 ) )
            );
        } elseif ( $percent >= 75 ) {
            WPSM_Notifier::send_alert(
                'warning',
                sprintf( 'PHP memory usage at %d%% — %s of %s used.', $percent, size_format( $used, 1 ), size_format( $limit, 1 ) )
            );
        }
    }

    private function parse_memory_limit( $val ) {
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

    public function check_database_usage() {
        global $wpdb;
        // No user input — DATABASE() is a MySQL function, safe without prepare().
        $db_bytes  = (float) $wpdb->get_var( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()' );
        $threshold = 1024 * 1024 * 1024; // 1 GB

        if ( $db_bytes >= $threshold ) {
            WPSM_Notifier::send_alert(
                'warning',
                sprintf( 'Database size is %s GB — consider optimizing or upgrading storage.', round( $db_bytes / $threshold, 2 ) )
            );
        }
    }

    /**
     * Ping the site's home URL to detect front-end errors.
     * Timeout reduced to 5s — was 15s which could hang cron on managed hosts.
     */
    public function check_site_reachability() {
        $url      = home_url( '/' );
        $response = wp_remote_get( $url, array(
            'timeout'   => 5,
            'sslverify' => false,
        ) );

        if ( is_wp_error( $response ) ) {
            WPSM_Notifier::send_alert(
                'critical',
                sprintf( 'Site is unreachable: %s', $response->get_error_message() )
            );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 500 ) {
            WPSM_Notifier::send_alert( 'critical', sprintf( 'Site returned HTTP %d — server error detected.', $code ) );
        } elseif ( $code >= 400 ) {
            WPSM_Notifier::send_alert( 'warning', sprintf( 'Site returned HTTP %d — check your configuration.', $code ) );
        }
    }

    public function check_cron_health() {
        $crons = _get_cron_array();

        if ( empty( $crons ) ) {
            WPSM_Notifier::send_alert( 'warning', 'No scheduled cron jobs found — WP-Cron may be disabled.' );
            return;
        }

        $overdue = array();
        $now     = time();
        foreach ( $crons as $timestamp => $hooks ) {
            if ( $timestamp < ( $now - HOUR_IN_SECONDS ) ) {
                foreach ( array_keys( $hooks ) as $hook ) {
                    $overdue[] = $hook;
                }
            }
        }

        if ( ! empty( $overdue ) ) {
            WPSM_Notifier::send_alert(
                'warning',
                sprintf( '%d cron job(s) are overdue: %s', count( $overdue ), implode( ', ', array_slice( $overdue, 0, 5 ) ) )
            );
        }
    }

    public function check_woocommerce_orders() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $count = wc_get_order_count( 'processing' );
        if ( $count > 50 ) {
            WPSM_Notifier::send_alert(
                'info',
                sprintf( '%d WooCommerce orders are pending processing.', $count )
            );
        }
    }

    // -------------------------------------------------------------------------
    // Real-time hooks
    // -------------------------------------------------------------------------

    public function track_failed_login( $username ) {
        $ip = $this->get_remote_ip();
        WPSM_Notifier::log_event(
            'warning',
            sprintf( 'Failed login attempt for "%s" from %s', sanitize_user( $username ), $ip )
        );
    }

    public function track_successful_login( $user_login, $user ) {
        if ( user_can( $user, 'manage_options' ) ) {
            WPSM_Notifier::log_event(
                'info',
                sprintf( 'Admin "%s" logged in from %s', $user_login, $this->get_remote_ip() )
            );
        }
    }

    public function track_slow_page_load() {
        // Bail early in non-HTTP contexts (CLI, cron) where timer_stop may not be meaningful.
        if ( ! function_exists( 'timer_stop' ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }

        $seconds = (float) timer_stop( 0, 3 );
        if ( $seconds > 3 ) {
            $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
            WPSM_Notifier::log_event( 'warning', sprintf( 'Slow page load: %ss for %s', $seconds, $uri ) );
        }
    }

    /**
     * WP shutdown hook — catches PHP errors WordPress itself can handle.
     * PHP-level fatals (parse errors that kill WP entirely) are caught by
     * wpsm_php_shutdown_handler() in wp-site-monitor.php instead.
     */
    public function detect_wp_errors() {
        $error       = error_get_last();
        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

        if ( empty( $error ) || ! in_array( $error['type'], $fatal_types, true ) ) {
            return;
        }

        // Mark as handled so the watchdog mu-plugin doesn't double-alert.
        if ( ! defined( 'WPSM_FATAL_HANDLED' ) ) {
            define( 'WPSM_FATAL_HANDLED', true );
        }

        $message = sprintf(
            'Fatal PHP error: %s in %s on line %d',
            $error['message'],
            str_replace( ABSPATH, '', $error['file'] ),
            $error['line']
        );

        WPSM_Notifier::send_alert( 'critical', $message );
    }

    public function track_404() {
        if ( ! is_404() ) {
            return;
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        WPSM_Notifier::log_event( 'info', sprintf( '404 Not Found: %s', $uri ) );
    }

    private function get_remote_ip() {
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            return trim( $ips[0] );
        }

        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}
