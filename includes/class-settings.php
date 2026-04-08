<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPSM_Settings {

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
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_wpsm_trigger_check', array( $this, 'handle_trigger_check' ) );
        add_action( 'admin_post_wpsm_trigger_daily', array( $this, 'handle_trigger_daily' ) );
        add_action( 'admin_post_wpsm_clear_alerts', array( $this, 'handle_clear_alerts' ) );
        add_action( 'admin_post_wpsm_regenerate_api_key', array( $this, 'handle_regenerate_api_key' ) );
        add_action( 'admin_post_wpsm_install_watchdog', array( $this, 'handle_install_watchdog' ) );
        add_action( 'admin_post_wpsm_uninstall_watchdog', array( $this, 'handle_uninstall_watchdog' ) );
        add_action( 'admin_post_wpsm_vuln_test_connection', array( $this, 'handle_vuln_test_connection' ) );
        add_action( 'admin_post_wpsm_vuln_register', array( $this, 'handle_vuln_register' ) );

        // Hide plugin from the plugins list for non-admins.
        add_filter( 'all_plugins', array( $this, 'hide_from_non_admins' ) );
    }

    /**
     * Remove this plugin from the plugins list for any user without manage_options.
     * Admins still see it and can deactivate/update normally.
     */
    public function hide_from_non_admins( $plugins ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            unset( $plugins[ plugin_basename( WPSM_PLUGIN_FILE ) ] );
        }
        return $plugins;
    }

    public static function init_options() {
        $defaults = array(
            'slack_webhook_url'      => '',
            'generic_webhook_url'    => '',
            'alert_levels'           => array( 'critical', 'warning', 'info' ),
            'check_interval'         => WPSM_DEFAULT_CHECK_INTERVAL,
            'max_stored_alerts'      => 100,
            'api_key'                => wp_generate_password( 32, false, false ),
            'report_schedule_type'   => 'weekly_days',
            'report_days_of_week'    => array( 1, 2, 3, 4, 5 ),  // Mon-Fri by default
            'report_interval_days'   => 7,
            'report_time'            => '08:00',
            'report_timezone'        => 'site',  // 'site' = use WordPress timezone, or specific timezone string
            'vuln_scanning_enabled'  => false,
            'vuln_cloud_endpoint'    => '',
            'vuln_cloud_token'       => '',
        );

        add_option( 'wpsm_settings', $defaults );
    }

    public static function get_settings() {
        return get_option( 'wpsm_settings', array() );
    }

    public static function get_api_key() {
        $settings = self::get_settings();
        return isset( $settings['api_key'] ) ? sanitize_text_field( $settings['api_key'] ) : '';
    }

    public static function get_max_stored_alerts() {
        $settings = self::get_settings();
        return isset( $settings['max_stored_alerts'] ) ? absint( $settings['max_stored_alerts'] ) : 100;
    }


    public static function validate_timezone( $tz ) {
        if ( 'site' === $tz ) {
            return true;
        }
        return in_array( $tz, timezone_identifiers_list(), true );
    }

    /**
     * Register this site with Cloud Run vulnerability scanner.
     */
    public static function register_with_cloud_run( $settings ) {
        if ( empty( $settings['vuln_cloud_endpoint'] ) || empty( $settings['vuln_cloud_token'] ) ) {
            return;
        }

        $endpoint = trailingslashit( $settings['vuln_cloud_endpoint'] ) . 'register';

        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['vuln_cloud_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'site_url'  => home_url(),
                'site_name' => get_bloginfo( 'name' ),
                'api_key'   => self::get_api_key(),
            ) ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'WPSM Cloud Run registration error: ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 === $status ) {
            update_option( 'wpsm_vuln_registered', array(
                'registered_at' => current_time( 'mysql' ),
                'endpoint'      => $endpoint,
            ) );
            return true;
        }

        error_log( 'WPSM Cloud Run registration failed: HTTP ' . $status );
        return false;
    }

    /**
     * Unregister this site from Cloud Run vulnerability scanner.
     */
    public static function unregister_from_cloud_run( $settings ) {
        if ( empty( $settings['vuln_cloud_endpoint'] ) || empty( $settings['vuln_cloud_token'] ) ) {
            return;
        }

        $endpoint = trailingslashit( $settings['vuln_cloud_endpoint'] ) . 'register';

        $response = wp_remote_request( $endpoint, array(
            'method'  => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['vuln_cloud_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'site_url' => home_url(),
            ) ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'WPSM Cloud Run unregistration error: ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 === $status || 204 === $status ) {
            delete_option( 'wpsm_vuln_registered' );
            return true;
        }

        error_log( 'WPSM Cloud Run unregistration failed: HTTP ' . $status );
        return false;
    }

    public function register_settings_page() {
        add_options_page(
            'WP Site Monitor',
            'WP Site Monitor',
            'manage_options',
            'wpsm-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'wpsm_settings_group', 'wpsm_settings', array( $this, 'sanitize_settings' ) );

        // --- Notifications ---
        add_settings_section( 'wpsm_notifications_section', 'Notifications', '__return_false', 'wpsm-settings' );
        add_settings_field( 'slack_webhook_url', 'Slack Webhook URL', array( $this, 'render_secret_field' ), 'wpsm-settings', 'wpsm_notifications_section', array( 'label_for' => 'slack_webhook_url', 'desc' => 'Incoming Webhook URL from your Slack app.' ) );
        add_settings_field( 'generic_webhook_url', 'Generic Webhook URL', array( $this, 'render_secret_field' ), 'wpsm-settings', 'wpsm_notifications_section', array( 'label_for' => 'generic_webhook_url', 'desc' => 'Optional. Receives structured JSON payloads for external apps.' ) );
        add_settings_field( 'alert_levels', 'Real-time Alert Levels', array( $this, 'render_alert_levels' ), 'wpsm-settings', 'wpsm_notifications_section' );

        // --- General ---
        add_settings_section( 'wpsm_general_section', 'General', '__return_false', 'wpsm-settings' );
        add_settings_field( 'check_interval', 'Check Interval (seconds)', array( $this, 'render_number_field' ), 'wpsm-settings', 'wpsm_general_section', array( 'label_for' => 'check_interval', 'desc' => 'How often to run scheduled checks. Default: 3600 (1 hour).' ) );
        add_settings_field( 'max_stored_alerts', 'Max Stored Alerts', array( $this, 'render_max_alerts' ), 'wpsm-settings', 'wpsm_general_section' );
        add_settings_field( 'report_schedule', 'Health Report Schedule', array( $this, 'render_report_schedule' ), 'wpsm-settings', 'wpsm_general_section' );

        // --- Vulnerability Scanning ---
        add_settings_section( 'wpsm_vuln_section', 'Vulnerability Scanning (Wordfence)', '__return_false', 'wpsm-settings' );
        add_settings_field( 'vuln_scanning', 'Vulnerability Scanning', array( $this, 'render_vuln_scanning' ), 'wpsm-settings', 'wpsm_vuln_section' );

        // --- API ---
        add_settings_section( 'wpsm_api_section', 'REST API', '__return_false', 'wpsm-settings' );
        add_settings_field( 'api_key', 'API Key', array( $this, 'render_api_key' ), 'wpsm-settings', 'wpsm_api_section' );
    }

    public function sanitize_settings( $input ) {
        $sanitized = array();

        $sanitized['slack_webhook_url']   = isset( $input['slack_webhook_url'] ) ? esc_url_raw( $input['slack_webhook_url'] ) : '';
        $sanitized['generic_webhook_url'] = isset( $input['generic_webhook_url'] ) ? esc_url_raw( $input['generic_webhook_url'] ) : '';
        $sanitized['check_interval']      = isset( $input['check_interval'] ) ? max( 60, absint( $input['check_interval'] ) ) : WPSM_DEFAULT_CHECK_INTERVAL;
        $sanitized['api_key']             = isset( $input['api_key'] ) && ! empty( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : self::get_api_key();

        $allowed_max                      = array( 25, 50, 100, 200, 500 );
        $raw_max                          = isset( $input['max_stored_alerts'] ) ? absint( $input['max_stored_alerts'] ) : 100;
        $sanitized['max_stored_alerts']   = in_array( $raw_max, $allowed_max, true ) ? $raw_max : 100;

        $raw_levels                = isset( $input['alert_levels'] ) ? (array) $input['alert_levels'] : array();
        $sanitized['alert_levels'] = array_values( array_intersect( $raw_levels, array( 'critical', 'warning', 'info' ) ) );

        // Report schedule settings.
        $sanitized['report_schedule_type'] = isset( $input['report_schedule_type'] ) && in_array( $input['report_schedule_type'], array( 'weekly_days', 'interval' ), true ) ? $input['report_schedule_type'] : 'weekly_days';

        // Days of week: array of integers 1-7 (ISO: 1=Mon...7=Sun).
        $raw_days = isset( $input['report_days_of_week'] ) ? (array) $input['report_days_of_week'] : array();
        $sanitized['report_days_of_week'] = array_values(
            array_filter(
                array_map( 'intval', $raw_days ),
                function( $day ) {
                    return $day >= 1 && $day <= 7;
                }
            )
        );
        // Fallback to Mon-Fri if empty.
        if ( empty( $sanitized['report_days_of_week'] ) ) {
            $sanitized['report_days_of_week'] = array( 1, 2, 3, 4, 5 );
        }

        // Interval days.
        $raw_interval = isset( $input['report_interval_days'] ) ? absint( $input['report_interval_days'] ) : 7;
        $sanitized['report_interval_days'] = max( 1, $raw_interval );

        // Report time.
        $raw_time = isset( $input['report_time'] ) ? sanitize_text_field( $input['report_time'] ) : '08:00';
        $sanitized['report_time'] = preg_match( '/^\d{2}:\d{2}$/', $raw_time ) ? $raw_time : '08:00';

        // Report timezone.
        $raw_tz = isset( $input['report_timezone'] ) ? sanitize_text_field( $input['report_timezone'] ) : 'site';
        $sanitized['report_timezone'] = self::validate_timezone( $raw_tz ) ? $raw_tz : 'site';

        // Vulnerability scanning settings.
        $sanitized['vuln_scanning_enabled'] = ! empty( $input['vuln_scanning_enabled'] );
        $sanitized['vuln_cloud_endpoint']   = isset( $input['vuln_cloud_endpoint'] ) ? esc_url_raw( $input['vuln_cloud_endpoint'] ) : '';
        $sanitized['vuln_cloud_token']      = isset( $input['vuln_cloud_token'] ) ? sanitize_text_field( $input['vuln_cloud_token'] ) : '';

        // Handle registration/unregistration with Cloud Run.
        $old_settings       = self::get_settings();
        $old_vuln_enabled   = ! empty( $old_settings['vuln_scanning_enabled'] );
        $new_vuln_enabled   = $sanitized['vuln_scanning_enabled'];

        if ( ! $old_vuln_enabled && $new_vuln_enabled ) {
            // Enabling: register with Cloud Run
            self::register_with_cloud_run( $sanitized );
        } elseif ( $old_vuln_enabled && ! $new_vuln_enabled ) {
            // Disabling: unregister from Cloud Run
            self::unregister_from_cloud_run( $sanitized );
        }

        // Note: The hourly cron (wpsm_report_check) handles all scheduling logic at runtime.
        // No need to reschedule anything here.

        return $sanitized;
    }

    // -------------------------------------------------------------------------
    // Admin action handlers
    // -------------------------------------------------------------------------

    public function handle_trigger_check() {
        check_admin_referer( 'wpsm_trigger_check' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        WPSM_Monitor::instance()->run_scheduled_checks();
        wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => 'checks' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function handle_trigger_daily() {
        check_admin_referer( 'wpsm_trigger_daily' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        WPSM_Notifier::send_daily_health_report();
        wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => 'daily' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function handle_clear_alerts() {
        check_admin_referer( 'wpsm_clear_alerts' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        delete_option( 'wpsm_recent_alerts' );
        wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => 'cleared' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function handle_regenerate_api_key() {
        check_admin_referer( 'wpsm_regenerate_api_key' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        $settings            = self::get_settings();
        $settings['api_key'] = wp_generate_password( 32, false, false );
        update_option( 'wpsm_settings', $settings );
        wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => 'regenerated' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function handle_install_watchdog() {
        check_admin_referer( 'wpsm_install_watchdog' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $source = WPSM_PLUGIN_DIR . 'mu-plugin/wpsm-watchdog.php';
        $dest   = trailingslashit( WPMU_PLUGIN_DIR ) . 'wpsm-watchdog.php';

        if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
            wp_mkdir_p( WPMU_PLUGIN_DIR );
        }

        $result = copy( $source, $dest );
        $status = $result ? 'watchdog_installed' : 'watchdog_error';

        wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => $status ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function handle_uninstall_watchdog() {
        check_admin_referer( 'wpsm_uninstall_watchdog' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $dest = trailingslashit( WPMU_PLUGIN_DIR ) . 'wpsm-watchdog.php';
        if ( file_exists( $dest ) ) {
            unlink( $dest );
        }

        wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => 'watchdog_removed' ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function handle_vuln_test_connection() {
        check_admin_referer( 'wpsm_vuln_test_connection' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $settings = self::get_settings();
        $endpoint = isset( $settings['vuln_cloud_endpoint'] ) ? $settings['vuln_cloud_endpoint'] : '';
        $token = isset( $settings['vuln_cloud_token'] ) ? $settings['vuln_cloud_token'] : '';

        if ( ! $endpoint || ! $token ) {
            wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => 'vuln_test_failed' ), admin_url( 'options-general.php' ) ) );
            exit;
        }

        // Test /health endpoint
        $test_url = trailingslashit( $endpoint ) . 'health';
        $response = wp_remote_get( $test_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => 'vuln_test_failed' ), admin_url( 'options-general.php' ) ) );
            exit;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $result = ( 200 === $status ) ? 'vuln_test_success' : 'vuln_test_failed';

        wp_redirect( add_query_arg( array( 'page' => 'wpsm-settings', 'wpsm_ran' => $result ), admin_url( 'options-general.php' ) ) );
        exit;
    }

    private function get_watchdog_status() {
        $dest          = trailingslashit( WPMU_PLUGIN_DIR ) . 'wpsm-watchdog.php';
        $is_installed  = file_exists( $dest );
        $version_match = false;

        if ( $is_installed ) {
            $installed_data = get_file_data( $dest, array( 'Version' => 'Version' ) );
            $source_data    = get_file_data( WPSM_PLUGIN_DIR . 'mu-plugin/wpsm-watchdog.php', array( 'Version' => 'Version' ) );
            $version_match  = isset( $installed_data['Version'], $source_data['Version'] )
                              && $installed_data['Version'] === $source_data['Version'];
        }

        return array(
            'installed'     => $is_installed,
            'version_match' => $version_match,
        );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>WP Site Monitor</h1>

            <?php if ( isset( $_GET['wpsm_ran'] ) ) : ?>
                <?php
                $ran = sanitize_key( $_GET['wpsm_ran'] );
                $notice_class = 'notice-success';
                $notice_text = '';

                if ( 'checks' === $ran ) {
                    $notice_text = 'Health checks executed. Any issues found were sent to Slack.';
                } elseif ( 'daily' === $ran ) {
                    $notice_text = 'Daily health report sent to Slack.';
                } elseif ( 'cleared' === $ran ) {
                    $notice_text = 'Alert log cleared.';
                } elseif ( 'regenerated' === $ran ) {
                    $notice_text = 'API key regenerated. Update any apps using the previous key.';
                } elseif ( 'watchdog_installed' === $ran ) {
                    $notice_text = 'Watchdog installed successfully. Fatal errors will now trigger Slack alerts even if the main plugin crashes.';
                } elseif ( 'watchdog_removed' === $ran ) {
                    $notice_text = 'Watchdog removed.';
                } elseif ( 'watchdog_error' === $ran ) {
                    $notice_class = 'notice-error';
                    $notice_text = 'Could not copy the watchdog file. Check that WordPress has write permissions to the mu-plugins directory.';
                } elseif ( 'vuln_test_success' === $ran ) {
                    $notice_text = '✅ Cloud Run connection successful! Your endpoint is reachable.';
                } elseif ( 'vuln_test_failed' === $ran ) {
                    $notice_class = 'notice-error';
                    $notice_text = '❌ Cloud Run connection failed. Check your endpoint URL and bearer token.';
                }

                if ( $notice_text ) : ?>
                    <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible"><p>
                        <?php echo wp_kses_post( $notice_text ); ?>
                    </p></div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'wpsm_settings_group' );
                do_settings_sections( 'wpsm-settings' );
                submit_button( 'Save Settings' );
                ?>
            </form>

            <hr>
            <?php $this->render_watchdog_section(); ?>

            <hr>
            <h2>Manual Triggers</h2>
            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpsm_trigger_check' ), 'wpsm_trigger_check' ) ); ?>" class="button">
                    Run Health Checks Now
                </a>
                &nbsp;
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpsm_trigger_daily' ), 'wpsm_trigger_daily' ) ); ?>" class="button">
                    Send Daily Report to Slack
                </a>
            </p>

            <hr>
            <?php $this->render_recent_logs_section(); ?>

            <hr>
            <h2>REST API Endpoints</h2>
            <table class="widefat" style="max-width:800px">
                <thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_html( home_url( '/wp-json/wp-site-monitor/v1/status' ) ); ?></code></td>
                        <td>Full site status snapshot</td>
                    </tr>
                    <tr>
                        <td><code>GET</code></td>
                        <td><code><?php echo esc_html( home_url( '/wp-json/wp-site-monitor/v1/alerts?level=critical&limit=10' ) ); ?></code></td>
                        <td>Recent alerts (filter by <code>level</code>, <code>limit</code>)</td>
                    </tr>
                    <tr>
                        <td><code>POST</code></td>
                        <td><code><?php echo esc_html( home_url( '/wp-json/wp-site-monitor/v1/webhook' ) ); ?></code></td>
                        <td>Receive external events</td>
                    </tr>
                </tbody>
            </table>
            <p class="description">Authenticate with header <code>x-wpsm-api-key: YOUR_KEY</code> or query param <code>?api_key=YOUR_KEY</code>.</p>
        </div>
        <?php
    }

    public function render_text_field( $args ) {
        $settings = self::get_settings();
        $id       = $args['label_for'];
        $value    = isset( $settings[ $id ] ) ? esc_attr( $settings[ $id ] ) : '';
        $desc     = isset( $args['desc'] ) ? $args['desc'] : '';

        printf(
            '<input type="text" id="%1$s" name="wpsm_settings[%1$s]" value="%2$s" class="regular-text" />',
            esc_attr( $id ),
            $value
        );

        if ( $desc ) {
            echo '<p class="description">' . esc_html( $desc ) . '</p>';
        }
    }

    public function render_secret_field( $args ) {
        $settings = self::get_settings();
        $id       = $args['label_for'];
        $value    = isset( $settings[ $id ] ) ? $settings[ $id ] : '';
        $desc     = isset( $args['desc'] ) ? $args['desc'] : '';
        $uid      = 'wpsm-secret-' . esc_attr( $id );

        // Show last 10 chars as hint if a value is already set.
        $hint = $value ? '••••••••••••••••••••' . substr( $value, -10 ) : '';
        ?>
        <div style="display:flex;gap:8px;align-items:center;max-width:560px">
            <input
                type="password"
                id="<?php echo esc_attr( $uid ); ?>"
                name="wpsm_settings[<?php echo esc_attr( $id ); ?>]"
                value="<?php echo esc_attr( $value ); ?>"
                class="regular-text"
                style="font-family:monospace;flex:1"
                autocomplete="off"
            />
            <button type="button" class="button"
                onclick="(function(btn){
                    var inp = document.getElementById('<?php echo esc_js( $uid ); ?>');
                    if(inp.type==='password'){inp.type='text';btn.textContent='Hide';}
                    else{inp.type='password';btn.textContent='Show';}
                })(this)">Show</button>
        </div>
        <?php if ( $hint ) : ?>
            <p class="description" style="font-family:monospace;margin-top:4px"><?php echo esc_html( $hint ); ?></p>
        <?php endif; ?>
        <?php if ( $desc ) : ?>
            <p class="description"><?php echo esc_html( $desc ); ?></p>
        <?php endif; ?>
        <?php
    }

    public function render_number_field( $args ) {
        $settings = self::get_settings();
        $id       = $args['label_for'];
        $value    = isset( $settings[ $id ] ) ? absint( $settings[ $id ] ) : WPSM_DEFAULT_CHECK_INTERVAL;
        $desc     = isset( $args['desc'] ) ? $args['desc'] : '';

        printf(
            '<input type="number" id="%1$s" name="wpsm_settings[%1$s]" value="%2$s" class="small-text" min="60" />',
            esc_attr( $id ),
            esc_attr( $value )
        );

        if ( $desc ) {
            echo '<p class="description">' . esc_html( $desc ) . '</p>';
        }
    }

    public function render_alert_levels() {
        $settings = self::get_settings();
        $selected = isset( $settings['alert_levels'] ) ? (array) $settings['alert_levels'] : array( 'critical', 'warning', 'info' );

        $levels = array(
            'critical' => array( 'label' => 'Critical', 'dot' => '🔴' ),
            'warning'  => array( 'label' => 'Warning',  'dot' => '🟡' ),
            'info'     => array( 'label' => 'Info',     'dot' => '🔵' ),
        );

        foreach ( $levels as $key => $meta ) {
            printf(
                '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="wpsm_settings[alert_levels][]" value="%1$s" %2$s> %3$s %4$s</label>',
                esc_attr( $key ),
                checked( in_array( $key, $selected, true ), true, false ),
                esc_html( $meta['dot'] ),
                esc_html( $meta['label'] )
            );
        }

        echo '<p class="description">Controls real-time Slack notifications only. The daily report <strong>always</strong> includes update warnings regardless of this setting.</p>';
    }

    public function render_max_alerts() {
        $current = self::get_max_stored_alerts();
        $options = array( 25, 50, 100, 200, 500 );

        echo '<select name="wpsm_settings[max_stored_alerts]">';
        foreach ( $options as $val ) {
            printf(
                '<option value="%1$d" %2$s>%1$d events</option>',
                $val,
                selected( $current, $val, false )
            );
        }
        echo '</select>';
        echo '<p class="description">How many alert events to keep in the local log. Older entries are automatically pruned.</p>';
    }

    public function render_report_schedule() {
        $settings = self::get_settings();

        $schedule_type = isset( $settings['report_schedule_type'] ) ? $settings['report_schedule_type'] : 'weekly_days';
        $days_of_week  = isset( $settings['report_days_of_week'] ) ? (array) $settings['report_days_of_week'] : array( 1, 2, 3, 4, 5 );
        $interval_days = isset( $settings['report_interval_days'] ) ? absint( $settings['report_interval_days'] ) : 7;
        $report_time   = isset( $settings['report_time'] ) ? $settings['report_time'] : '08:00';
        $report_tz     = isset( $settings['report_timezone'] ) ? $settings['report_timezone'] : 'site';
        $site_tz       = wp_timezone_string();

        $days = array(
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        );
        ?>
        <fieldset style="border: 1px solid #ddd; padding: 12px; margin-bottom: 12px; background: #f9f9f9; max-width: 640px">
            <legend style="padding: 0 8px; font-weight: 600">Report Schedule Type</legend>

            <label style="display: block; margin-bottom: 12px">
                <input type="radio" name="wpsm_settings[report_schedule_type]" value="weekly_days" <?php checked( $schedule_type, 'weekly_days' ); ?> />
                <strong>Specific days of the week</strong>
            </label>

            <div id="wpsm-weekly-days-block" style="display: <?php echo 'weekly_days' === $schedule_type ? 'block' : 'none'; ?>; margin-left: 20px; margin-bottom: 12px">
                <p style="margin-top: 0"><strong>Select days:</strong></p>
                <?php foreach ( $days as $day_num => $day_name ) : ?>
                    <label style="display: inline-block; margin-right: 16px">
                        <input type="checkbox" name="wpsm_settings[report_days_of_week][]" value="<?php echo esc_attr( $day_num ); ?>" <?php checked( in_array( $day_num, $days_of_week, true ), true ); ?> />
                        <?php echo esc_html( $day_name ); ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <label style="display: block; margin-bottom: 12px">
                <input type="radio" name="wpsm_settings[report_schedule_type]" value="interval" <?php checked( $schedule_type, 'interval' ); ?> />
                <strong>Fixed interval</strong>
            </label>

            <div id="wpsm-interval-block" style="display: <?php echo 'interval' === $schedule_type ? 'block' : 'none'; ?>; margin-left: 20px; margin-bottom: 12px">
                <label>
                    Every
                    <input type="number" name="wpsm_settings[report_interval_days]" value="<?php echo esc_attr( $interval_days ); ?>" min="1" max="365" class="small-text" />
                    days
                </label>
                <p class="description" style="margin-top: 4px">Examples: 7 = weekly, 14 = bi-weekly, 30 = monthly</p>
            </div>

            <label style="display: block; margin-top: 12px">
                <strong>Send at:</strong>
                <input type="time" name="wpsm_settings[report_time]" value="<?php echo esc_attr( $report_time ); ?>" style="margin-right: 12px;" />
            </label>

            <label style="display: block; margin-top: 12px">
                <strong>Timezone:</strong>
                <select name="wpsm_settings[report_timezone]" style="max-width: 320px;">
                    <option value="site" <?php selected( $report_tz, 'site' ); ?>>
                        Site Default (<?php echo esc_html( $site_tz ); ?>)
                    </option>
                    <option value="America/New_York" <?php selected( $report_tz, 'America/New_York' ); ?>>America/New_York (EST/EDT)</option>
                    <option value="America/Chicago" <?php selected( $report_tz, 'America/Chicago' ); ?>>America/Chicago (CST/CDT)</option>
                    <option value="America/Denver" <?php selected( $report_tz, 'America/Denver' ); ?>>America/Denver (MST/MDT)</option>
                    <option value="America/Los_Angeles" <?php selected( $report_tz, 'America/Los_Angeles' ); ?>>America/Los_Angeles (PST/PDT)</option>
                    <option value="America/Argentina/Buenos_Aires" <?php selected( $report_tz, 'America/Argentina/Buenos_Aires' ); ?>>America/Argentina/Buenos_Aires (ART)</option>
                    <option value="Europe/London" <?php selected( $report_tz, 'Europe/London' ); ?>>Europe/London (GMT/BST)</option>
                    <option value="Europe/Paris" <?php selected( $report_tz, 'Europe/Paris' ); ?>>Europe/Paris (CET/CEST)</option>
                    <option value="Europe/Berlin" <?php selected( $report_tz, 'Europe/Berlin' ); ?>>Europe/Berlin (CET/CEST)</option>
                    <option value="Asia/Tokyo" <?php selected( $report_tz, 'Asia/Tokyo' ); ?>>Asia/Tokyo (JST)</option>
                    <option value="Asia/Shanghai" <?php selected( $report_tz, 'Asia/Shanghai' ); ?>>Asia/Shanghai (CST)</option>
                    <option value="Asia/Hong_Kong" <?php selected( $report_tz, 'Asia/Hong_Kong' ); ?>>Asia/Hong_Kong (HKT)</option>
                    <option value="Asia/Singapore" <?php selected( $report_tz, 'Asia/Singapore' ); ?>>Asia/Singapore (SGT)</option>
                    <option value="Australia/Sydney" <?php selected( $report_tz, 'Australia/Sydney' ); ?>>Australia/Sydney (AEDT/AEST)</option>
                    <option value="UTC" <?php selected( $report_tz, 'UTC' ); ?>>UTC</option>
                </select>
            </label>
            <p class="description" style="margin-top: 4px">Reports will be sent based on the selected timezone.</p>
        </fieldset>

        <script type="text/javascript">
            (function() {
                const radios = document.querySelectorAll('input[name="wpsm_settings[report_schedule_type]"]');
                const weeklyBlock = document.getElementById('wpsm-weekly-days-block');
                const intervalBlock = document.getElementById('wpsm-interval-block');

                function updateView() {
                    const selected = document.querySelector('input[name="wpsm_settings[report_schedule_type]"]:checked').value;
                    weeklyBlock.style.display = selected === 'weekly_days' ? 'block' : 'none';
                    intervalBlock.style.display = selected === 'interval' ? 'block' : 'none';
                }

                radios.forEach(radio => {
                    radio.addEventListener('change', updateView);
                });
            })();
        </script>
        <?php
    }

    public function render_vuln_scanning() {
        $settings  = self::get_settings();
        $enabled   = ! empty( $settings['vuln_scanning_enabled'] );
        $endpoint  = isset( $settings['vuln_cloud_endpoint'] ) ? $settings['vuln_cloud_endpoint'] : '';
        $token     = isset( $settings['vuln_cloud_token'] ) ? $settings['vuln_cloud_token'] : '';
        $registered = get_option( 'wpsm_vuln_registered' );
        $test_url = wp_nonce_url( admin_url( 'admin-post.php?action=wpsm_vuln_test_connection' ), 'wpsm_vuln_test_connection' );
        ?>
        <fieldset style="border: 1px solid #ddd; padding: 12px; margin-bottom: 12px; background: #f9f9f9; max-width: 800px">
            <legend style="padding: 0 8px; font-weight: 600">🔐 Vulnerability Scanning (Wordfence)</legend>

            <label style="display: block; margin-bottom: 12px">
                <input type="checkbox" name="wpsm_settings[vuln_scanning_enabled]" value="1" <?php checked( $enabled ); ?> />
                <strong>Enable vulnerability scanning</strong>
            </label>

            <div id="wpsm-vuln-config" style="display: <?php echo $enabled ? 'block' : 'none'; ?>; margin-left: 20px; padding: 12px; background: white; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 12px">

                <div style="margin-bottom: 12px">
                    <label style="display: block; margin-bottom: 4px">
                        <strong>Cloud Run Endpoint URL:</strong>
                    </label>
                    <input type="url" name="wpsm_settings[vuln_cloud_endpoint]" value="<?php echo esc_attr( $endpoint ); ?>" placeholder="https://your-service.run.app" class="regular-text" style="max-width: 500px; margin-bottom: 4px" />
                    <p class="description" style="margin: 4px 0">https://wpsm-cloud-run-xxxxx.run.app</p>
                </div>

                <div style="margin-bottom: 12px">
                    <label style="display: block; margin-bottom: 4px">
                        <strong>Cloud Run Bearer Token:</strong>
                    </label>
                    <div style="display: flex; gap: 8px; max-width: 500px">
                        <input type="password" id="wpsm-vuln-token" name="wpsm_settings[vuln_cloud_token]" value="<?php echo esc_attr( $token ); ?>" class="regular-text" style="font-family: monospace; flex: 1" autocomplete="off" />
                        <button type="button" class="button" onclick="(function(btn){var inp = document.getElementById('wpsm-vuln-token'); if(inp.type==='password'){inp.type='text';btn.textContent='Hide';}else{inp.type='password';btn.textContent='Show';}})(this)">Show</button>
                    </div>
                    <p class="description" style="margin: 4px 0">Same token you set in Cloud Run environment variables</p>
                </div>

                <div style="padding: 12px; background: #f5f5f5; border-radius: 4px; margin-bottom: 12px">
                    <strong style="display: block; margin-bottom: 8px">Status:</strong>
                    <?php if ( $enabled && $registered ) : ?>
                        <p style="margin: 0; color: #2e7d32"><strong>✅ Registered</strong></p>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #666">Auto-registered at: <?php echo esc_html( $registered['registered_at'] ?? 'Unknown' ); ?></p>
                    <?php elseif ( $enabled ) : ?>
                        <p style="margin: 0; color: #f57c00"><strong>⚠️ Not yet registered</strong></p>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #666">Fill in endpoint + token, then click "Test Connection" or save settings</p>
                    <?php else : ?>
                        <p style="margin: 0; color: #999"><strong>⭕ Disabled</strong></p>
                        <p style="margin: 4px 0 0 0; font-size: 12px; color: #666">Enable the checkbox above to start scanning</p>
                    <?php endif; ?>
                </div>

                <?php if ( $enabled && ( $endpoint || $token ) ) : ?>
                    <div style="margin-top: 12px">
                        <a href="<?php echo esc_url( $test_url ); ?>" class="button button-secondary" style="margin-right: 8px">
                            🔍 Test Connection
                        </a>
                        <span class="description">Verify Cloud Run endpoint is reachable with your token</span>
                    </div>
                <?php endif; ?>
            </div>

            <p class="description" style="margin: 12px 0 0 0; line-height: 1.6">
                <strong>How it works:</strong><br/>
                1. Fill in your Cloud Run endpoint URL and bearer token<br/>
                2. Click "Test Connection" to verify settings<br/>
                3. Save settings to auto-register this site<br/>
                4. Cloud Run will check your plugins daily against Wordfence and send Slack alerts for critical vulnerabilities (CVSS ≥ 8)
            </p>
        </fieldset>

        <script type="text/javascript">
            (function() {
                const checkbox = document.querySelector('input[name="wpsm_settings[vuln_scanning_enabled]"]');
                const configBlock = document.getElementById('wpsm-vuln-config');

                if ( checkbox && configBlock ) {
                    checkbox.addEventListener( 'change', function() {
                        configBlock.style.display = this.checked ? 'block' : 'none';
                    } );
                }
            })();
        </script>
        <?php
    }

    public function render_api_key() {
        $api_key = self::get_api_key();
        $regen_url = wp_nonce_url( admin_url( 'admin-post.php?action=wpsm_regenerate_api_key' ), 'wpsm_regenerate_api_key' );
        ?>
        <div style="display:flex;gap:8px;align-items:center;max-width:500px">
            <input type="text" id="wpsm-api-key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" readonly style="font-family:monospace;flex:1" />
            <button type="button" class="button"
                onclick="navigator.clipboard.writeText(document.getElementById('wpsm-api-key').value).then(function(){var b=this;b.textContent='Copied!';setTimeout(function(){b.textContent='Copy'},2000)}.bind(this))">
                Copy
            </button>
            <a href="<?php echo esc_url( $regen_url ); ?>"
               class="button"
               onclick="return confirm('Regenerate the API key? All apps using the current key will stop working until updated.')">
                Regenerate
            </a>
        </div>
        <p class="description">Send this key in the <code>x-wpsm-api-key</code> request header or <code>?api_key=</code> query param. Never expose it publicly.</p>
        <?php
    }

    // -------------------------------------------------------------------------
    // Watchdog section
    // -------------------------------------------------------------------------

    private function is_managed_host() {
        // WP Engine.
        if ( defined( 'WPE_APIKEY' ) || defined( 'WPE_PLUGIN_BASE' ) || isset( $_SERVER['IS_WPE'] ) ) {
            return 'wpengine';
        }
        // Kinsta.
        if ( defined( 'KINSTA_CACHE_ZONE' ) || isset( $_SERVER['KINSTA_CACHE_ZONE'] ) ) {
            return 'kinsta';
        }
        // Pressable.
        if ( defined( 'PRESSABLE' ) ) {
            return 'pressable';
        }
        // Pantheon.
        if ( isset( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
            return 'pantheon';
        }
        return false;
    }

    private function render_watchdog_section() {
        $status      = $this->get_watchdog_status();
        $install_url = wp_nonce_url( admin_url( 'admin-post.php?action=wpsm_install_watchdog' ), 'wpsm_install_watchdog' );
        $remove_url  = wp_nonce_url( admin_url( 'admin-post.php?action=wpsm_uninstall_watchdog' ), 'wpsm_uninstall_watchdog' );
        $mu_path     = trailingslashit( WPMU_PLUGIN_DIR ) . 'wpsm-watchdog.php';
        ?>
        <h2>Watchdog <span style="font-size:13px;font-weight:normal;color:#666">(Fatal Error Detection)</span></h2>

        <table class="widefat" style="max-width:640px">
            <tbody>
                <tr>
                    <td style="width:140px;font-weight:600">Status</td>
                    <td>
                        <?php if ( $status['installed'] && $status['version_match'] ) : ?>
                            ✅ <strong>Installed</strong> — fatal errors trigger Slack alerts even if the main plugin crashes.
                        <?php elseif ( $status['installed'] && ! $status['version_match'] ) : ?>
                            🟡 <strong>Installed but outdated</strong> — re-upload the file to sync with the current version.
                        <?php else : ?>
                            🔴 <strong>Not installed</strong> — parse/fatal errors in the main plugin will <em>not</em> reach Slack.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="font-weight:600">Destination</td>
                    <td><code><?php echo esc_html( $mu_path ); ?></code></td>
                </tr>
                <tr>
                    <td style="font-weight:600">Action</td>
                    <td>
                        <?php if ( $status['installed'] ) : ?>
                            <a href="<?php echo esc_url( $install_url ); ?>" class="button button-primary">
                                <?php echo $status['version_match'] ? 'Reinstall' : 'Update Watchdog'; ?>
                            </a>
                            &nbsp;
                            <a href="<?php echo esc_url( $remove_url ); ?>"
                               class="button"
                               onclick="return confirm('Remove the watchdog? Fatal errors will no longer reach Slack if the main plugin crashes.')">
                                Remove
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( $install_url ); ?>" class="button button-primary">
                                Install Watchdog
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="description" style="max-width:640px;margin-top:8px">
            The watchdog is a must-use plugin that loads before everything else. It catches fatal PHP errors
            that prevent the main plugin from loading — including syntax errors — and sends them directly to Slack via cURL.
        </p>
        <?php
    }

    // -------------------------------------------------------------------------
    // Recent logs section with filter
    // -------------------------------------------------------------------------

    private function render_recent_logs_section() {
        $all_logs = get_option( 'wpsm_recent_alerts', array() );
        $total    = count( $all_logs );

        // Read filter params (GET, sanitized).
        $filter_level = isset( $_GET['wpsm_level'] ) ? sanitize_key( $_GET['wpsm_level'] ) : '';
        $filter_limit = isset( $_GET['wpsm_limit'] ) ? absint( $_GET['wpsm_limit'] ) : 25;
        $filter_limit = in_array( $filter_limit, array( 25, 50, 100 ), true ) ? $filter_limit : 25;

        if ( $filter_level && in_array( $filter_level, array( 'critical', 'warning', 'info' ), true ) ) {
            $logs = array_values( array_filter( $all_logs, function( $a ) use ( $filter_level ) {
                return isset( $a['level'] ) && $a['level'] === $filter_level;
            } ) );
        } else {
            $filter_level = '';
            $logs = $all_logs;
        }

        $logs = array_slice( $logs, 0, $filter_limit );

        $page_url = add_query_arg( 'page', 'wpsm-settings', admin_url( 'options-general.php' ) );
        ?>
        <h2>Recent Alerts <span style="font-size:13px;font-weight:normal;color:#666">(<?php echo esc_html( $total ); ?> stored / max <?php echo esc_html( self::get_max_stored_alerts() ); ?>)</span></h2>

        <form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" style="margin-bottom:12px;display:flex;gap:8px;align-items:center">
            <input type="hidden" name="page" value="wpsm-settings">

            <select name="wpsm_level">
                <option value="">All levels</option>
                <?php foreach ( array( 'critical' => '🔴 Critical', 'warning' => '🟡 Warning', 'info' => '🔵 Info' ) as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_level, $val ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>

            <select name="wpsm_limit">
                <?php foreach ( array( 25, 50, 100 ) as $val ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_limit, $val ); ?>><?php echo esc_html( $val ); ?> rows</option>
                <?php endforeach; ?>
            </select>

            <?php submit_button( 'Filter', 'secondary small', '', false ); ?>
            &nbsp;<a href="<?php echo esc_url( $page_url ); ?>" class="button button-small">Reset</a>
        </form>

        <?php if ( empty( $logs ) ) : ?>
            <p>No alerts recorded yet.</p>
        <?php else : ?>
            <?php
            $dots = array( 'critical' => '🔴', 'warning' => '🟡', 'info' => '🔵' );
            echo '<table class="widefat fixed striped" style="max-width:900px">';
            echo '<thead><tr><th style="width:160px">Date</th><th style="width:100px">Level</th><th>Message</th></tr></thead><tbody>';
            foreach ( $logs as $log ) {
                $dot   = $dots[ $log['level'] ] ?? '⚪';
                $label = ucfirst( $log['level'] );
                echo '<tr>';
                echo '<td>' . esc_html( $log['timestamp'] ) . '</td>';
                echo '<td>' . esc_html( $dot . ' ' . $label ) . '</td>';
                echo '<td>' . esc_html( $log['message'] ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            ?>
            <p style="margin-top:8px">
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpsm_clear_alerts' ), 'wpsm_clear_alerts' ) ); ?>"
                   class="button button-small"
                   onclick="return confirm('Clear all stored alerts?')">
                    Clear All Alerts
                </a>
            </p>
        <?php endif; ?>
        <?php
    }
}
