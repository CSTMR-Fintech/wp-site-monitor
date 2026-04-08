<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSM_API {

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
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        $namespace = 'wp-site-monitor/v1';

        // GET /wp-json/wp-site-monitor/v1/status — full site status snapshot.
        register_rest_route( $namespace, '/status', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_site_status' ),
            'permission_callback' => array( $this, 'verify_api_key' ),
        ) );

        // GET /wp-json/wp-site-monitor/v1/inventory — plugins, themes, core versions.
        register_rest_route( $namespace, '/inventory', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_site_inventory' ),
            'permission_callback' => array( $this, 'verify_api_key' ),
        ) );

        // GET /wp-json/wp-site-monitor/v1/alerts — recent alerts, filterable.
        register_rest_route( $namespace, '/alerts', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_alerts' ),
            'permission_callback' => array( $this, 'verify_api_key' ),
            'args'                => array(
                'level' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'enum'              => array( 'critical', 'warning', 'info' ),
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'limit' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 25,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        // POST /wp-json/wp-site-monitor/v1/webhook — receive external events.
        register_rest_route( $namespace, '/webhook', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'verify_api_key' ),
        ) );
    }

    public function verify_api_key( WP_REST_Request $request ) {
        $provided = $request->get_header( 'x-wpsm-api-key' ) ?: $request->get_param( 'api_key' );
        $stored   = WPSM_Settings::get_api_key();

        if ( empty( $provided ) || empty( $stored ) ) {
            return new WP_Error( 'wpsm_api_key_missing', 'API key required. Pass it in the x-wpsm-api-key header or api_key query param.', array( 'status' => 401 ) );
        }

        if ( hash_equals( $stored, sanitize_text_field( $provided ) ) ) {
            return true;
        }

        return new WP_Error( 'wpsm_invalid_api_key', 'Invalid API key.', array( 'status' => 403 ) );
    }

    /**
     * GET /status
     * Returns a real-time snapshot of site health.
     */
    public function get_site_status( WP_REST_Request $request ) {
        include_once ABSPATH . 'wp-admin/includes/update.php';

        $core_transient    = get_site_transient( 'update_core' );
        $plugins_transient = get_site_transient( 'update_plugins' );
        $themes_transient  = get_site_transient( 'update_themes' );

        $core_update_available    = ! empty( $core_transient->updates ) ? $core_transient->updates[0]->version : null;
        $plugin_updates_available = ! empty( $plugins_transient->response ) ? count( $plugins_transient->response ) : 0;
        $theme_updates_available  = ! empty( $themes_transient->response ) ? count( $themes_transient->response ) : 0;

        global $wpdb;
        $db_bytes = (float) $wpdb->get_var( "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()" );

        $recent_alerts = WPSM_Notifier::get_recent_alerts( 10 );
        $alert_summary = array( 'critical' => 0, 'warning' => 0, 'info' => 0 );
        foreach ( $recent_alerts as $a ) {
            if ( isset( $alert_summary[ $a['level'] ] ) ) {
                $alert_summary[ $a['level'] ]++;
            }
        }

        $status = array(
            'site' => array(
                'name'    => get_bloginfo( 'name' ),
                'url'     => home_url(),
                'checked' => current_time( 'c' ),
            ),
            'wordpress' => array(
                'version'          => get_bloginfo( 'version' ),
                'update_available' => $core_update_available,
            ),
            'plugins' => array(
                'active_count'    => count( get_option( 'active_plugins', array() ) ),
                'updates_pending' => $plugin_updates_available,
            ),
            'themes' => array(
                'active'          => wp_get_theme()->get( 'Name' ),
                'updates_pending' => $theme_updates_available,
            ),
            'database' => array(
                'size_bytes' => (int) $db_bytes,
                'size_human' => size_format( $db_bytes, 2 ),
            ),
            'alerts_summary' => $alert_summary,
            'recent_alerts'  => $recent_alerts,
        );

        return rest_ensure_response( $status );
    }

    /**
     * GET /inventory
     * Returns detailed list of installed plugins, themes, and core version.
     * Used by Cloud Run to check for vulnerabilities.
     */
    public function get_site_inventory( WP_REST_Request $request ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $plugins        = array();

        foreach ( $all_plugins as $file => $data ) {
            $slug = dirname( $file );
            if ( '.' === $slug ) {
                $slug = basename( $file, '.php' );
            }

            $plugins[] = array(
                'slug'    => $slug,
                'name'    => $data['Name'] ?? 'Unknown',
                'version' => $data['Version'] ?? '0.0.0',
                'active'  => in_array( $file, $active_plugins, true ),
            );
        }

        return rest_ensure_response( array(
            'site'  => array(
                'name' => get_bloginfo( 'name' ),
                'url'  => home_url(),
            ),
            'core'  => get_bloginfo( 'version' ),
            'plugins' => $plugins,
            'theme' => array(
                'slug'    => get_stylesheet(),
                'name'    => wp_get_theme()->get( 'Name' ) ?? 'Unknown',
                'version' => wp_get_theme()->get( 'Version' ) ?? '0.0.0',
            ),
        ) );
    }

    /**
     * GET /alerts
     * Returns recent alerts, optionally filtered by level.
     */
    public function get_alerts( WP_REST_Request $request ) {
        $level  = $request->get_param( 'level' );
        $limit  = $request->get_param( 'limit' ) ?: 25;
        $alerts = WPSM_Notifier::get_recent_alerts( 100 );

        if ( $level ) {
            $alerts = array_values( array_filter( $alerts, function( $a ) use ( $level ) {
                return isset( $a['level'] ) && $a['level'] === $level;
            } ) );
        }

        $alerts = array_slice( $alerts, 0, $limit );

        return rest_ensure_response( array(
            'count'  => count( $alerts ),
            'alerts' => $alerts,
        ) );
    }

    /**
     * POST /webhook
     * Receive external monitoring events and store them as alerts.
     */
    public function handle_webhook( WP_REST_Request $request ) {
        $body = $request->get_json_params();

        if ( empty( $body ) || ! is_array( $body ) ) {
            return new WP_Error( 'wpsm_empty_payload', 'Empty or invalid JSON payload.', array( 'status' => 400 ) );
        }

        $level   = isset( $body['level'] ) && in_array( $body['level'], array( 'critical', 'warning', 'info' ), true ) ? $body['level'] : 'info';
        $message = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : 'External webhook event received.';

        WPSM_Notifier::send_alert( $level, $message, $body );

        return rest_ensure_response( array( 'success' => true ) );
    }
}
