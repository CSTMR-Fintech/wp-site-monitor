<?php
/**
 * Plugin Name: WP Site Monitor
 * Plugin URI:  https://cstmr.com
 * Description: Monitors security, performance, updates and site health. Slack alerts and REST API included.
 * Version:     1.2.2
 * Author:      CSTMR
 * Author URI:  https://ctsmr.com
 * Text Domain: wp-site-monitor
 * Domain Path: /languages
 * Update URI:  https://github.com/CSTMR-Fintech/wp-site-monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WPSM_VERSION' ) ) {
    define( 'WPSM_VERSION', '1.2.2' );
}

define( 'WPSM_PLUGIN_FILE', __FILE__ );
define( 'WPSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPSM_DEFAULT_CHECK_INTERVAL', HOUR_IN_SECONDS );

// GitHub update checker — requires the vendor library to be present.
if ( file_exists( WPSM_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once WPSM_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    $wpsm_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/CSTMR-Fintech/wp-site-monitor/',
        WPSM_PLUGIN_FILE,
        'wp-site-monitor'
    );
    // Pull update from GitHub Releases (zip attached to the release).
    $wpsm_update_checker->getVcsApi()->enableReleaseAssets();
}

// Load settings first — other classes depend on it.
require_once WPSM_PLUGIN_DIR . 'includes/class-settings.php';
require_once WPSM_PLUGIN_DIR . 'includes/class-notifier.php';
require_once WPSM_PLUGIN_DIR . 'includes/class-monitor.php';
require_once WPSM_PLUGIN_DIR . 'includes/class-api.php';

/**
 * PHP-level shutdown handler — fires even when WordPress crashes.
 * Uses raw curl so the alert reaches Slack even if WP HTTP API is broken.
 */
register_shutdown_function( 'wpsm_php_shutdown_handler' );

function wpsm_php_shutdown_handler() {
    $error = error_get_last();

    $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

    if ( empty( $error ) || ! in_array( $error['type'], $fatal_types, true ) ) {
        return;
    }

    // Tell the watchdog mu-plugin this alert was already sent.
    if ( ! defined( 'WPSM_FATAL_HANDLED' ) ) {
        define( 'WPSM_FATAL_HANDLED', true );
    }

    if ( ! class_exists( 'WPSM_Settings' ) || ! class_exists( 'WPSM_Notifier' ) ) {
        return;
    }

    $message = sprintf(
        'Fatal PHP error: %s in %s on line %d',
        $error['message'],
        str_replace( ABSPATH, '', $error['file'] ),
        $error['line']
    );

    WPSM_Notifier::send_critical_via_curl( $message );
}

/**
 * Main plugin class.
 */
final class WPSiteMonitor {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->setup_hooks();
        }

        return self::$instance;
    }

    private function __construct() {}

    public function setup_hooks() {
        add_action( 'plugins_loaded', array( $this, 'init_classes' ) );
        register_activation_hook( WPSM_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WPSM_PLUGIN_FILE, array( $this, 'deactivate' ) );
        add_action( 'init', array( $this, 'bind_cron_actions' ) );
    }

    public function init_classes() {
        // Monitor and API run for everyone (cron + REST requests have no current user).
        WPSM_Monitor::instance();
        WPSM_API::instance();
        WPSM_Notifier::instance();

        // Settings UI only loads for admins in the WP admin area.
        if ( is_admin() && current_user_can( 'manage_options' ) ) {
            WPSM_Settings::instance();
        } elseif ( ! is_admin() ) {
            // On the front-end there's no current user yet at plugins_loaded,
            // so we defer the settings init to init where user is available.
            add_action( 'init', array( $this, 'maybe_init_settings' ), 1 );
        }
    }

    public function maybe_init_settings() {
        if ( current_user_can( 'manage_options' ) ) {
            WPSM_Settings::instance();
        }
    }

    public function activate() {
        WPSM_Settings::init_options();

        // Hourly: run health checks, send alerts if problems found.
        if ( ! wp_next_scheduled( 'wpsm_hourly_checks' ) ) {
            wp_schedule_event( time(), 'hourly', 'wpsm_hourly_checks' );
        }

        // Daily: send a health summary report to Slack.
        if ( ! wp_next_scheduled( 'wpsm_daily_health_report' ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'wpsm_daily_health_report' );
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'wpsm_hourly_checks' );
        wp_clear_scheduled_hook( 'wpsm_daily_health_report' );
    }

    public function bind_cron_actions() {
        add_action( 'wpsm_hourly_checks', array( WPSM_Monitor::instance(), 'run_scheduled_checks' ) );
        add_action( 'wpsm_daily_health_report', array( 'WPSM_Notifier', 'send_daily_health_report' ) );
    }
}

WPSiteMonitor::instance();
