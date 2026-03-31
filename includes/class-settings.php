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
            'slack_webhook_url'   => '',
            'generic_webhook_url' => '',
            'alert_levels'        => array( 'critical', 'warning', 'info' ),
            'check_interval'      => WPSM_DEFAULT_CHECK_INTERVAL,
            'max_stored_alerts'   => 100,
            'api_key'             => wp_generate_password( 32, false, false ),
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

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>WP Site Monitor</h1>

            <?php if ( isset( $_GET['wpsm_ran'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    $ran = sanitize_key( $_GET['wpsm_ran'] );
                    if ( 'checks' === $ran )           echo 'Health checks executed. Any issues found were sent to Slack.';
                    elseif ( 'daily' === $ran )         echo 'Daily health report sent to Slack.';
                    elseif ( 'cleared' === $ran )       echo 'Alert log cleared.';
                    elseif ( 'regenerated' === $ran )   echo 'API key regenerated. Update any apps using the previous key.';
                    ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'wpsm_settings_group' );
                do_settings_sections( 'wpsm-settings' );
                submit_button( 'Save Settings' );
                ?>
            </form>

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
