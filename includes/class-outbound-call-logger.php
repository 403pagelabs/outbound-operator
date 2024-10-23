<?php

class Outbound_Call_Logger {
    private static $instance = null;
    private $table_name;
    private $blocked_hosts_option = 'outbound_call_logger_blocked_hosts';
    private $blocked_paths_option = 'outbound_call_logger_blocked_paths';

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'outbound_calls_log';
    }

    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new Outbound_Call_Logger();
        }
        return self::$instance;
    }

    public function init() {
        $this->create_table();
        add_filter('pre_http_request', array($this, 'process_outbound_call'), 10, 3);
    }

    private function create_table() {
        global $wpdb;
    
        $charset_collate = $wpdb->get_charset_collate();
    
        $sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url varchar(2083) NOT NULL,
            rewrite_url varchar(2083) DEFAULT NULL,
            time datetime DEFAULT CURRENT_TIMESTAMP,
            hook varchar(255),
            is_manual tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";
    
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function process_outbound_call($preempt, $args, $url) {
        if ($preempt) {
            return $preempt; // If already handled, don't interfere
        }

        // Emergency check to prevent infinite loops
        static $recursion_guard = false;
        if ($recursion_guard) {
            return false;
        }
        $recursion_guard = true;

        $stripped_url = $this->strip_query_string($url);
        $should_log = !get_option('outbound_operator_stop_listening', false);

        error_log("Outbound Operator: Processing call to " . $url);

        // Check for blocks
        $blocked_hosts = get_option($this->blocked_hosts_option, array());
        $blocked_paths = get_option($this->blocked_paths_option, array());

        $parsed_url = parse_url($stripped_url);
        if ($parsed_url === false || !isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
            error_log("Outbound Operator: Invalid URL encountered: " . $url);
            $recursion_guard = false;
            return false; // Let WordPress handle this as normal
        }

        $host = $parsed_url['scheme'] . '://' . $parsed_url['host'];

        if (in_array($host, $blocked_hosts) || in_array($stripped_url, $blocked_paths)) {
            error_log("Outbound Operator: Call blocked: " . $url);
            $recursion_guard = false;
            return new WP_Error('http_request_blocked', 'Outbound call blocked by Outbound Operator');
        }

        // Check for rewrite
        global $wpdb;
        $rewrite_url = $wpdb->get_var($wpdb->prepare(
            "SELECT rewrite_url FROM $this->table_name WHERE url = %s AND rewrite_url IS NOT NULL",
            $stripped_url
        ));

        if ($rewrite_url) {
            error_log("Outbound Operator: Rewriting URL: " . $url . " to " . $rewrite_url);
            $args['url'] = str_replace($stripped_url, $rewrite_url, $url);
            $url = $args['url']; // Update URL for logging
        }

        // Log the call if logging is enabled
        if ($should_log) {
            $this->log_call($stripped_url);
        }

        // Perform the HTTP request with potentially rewritten URL
        $response = wp_remote_request($url, $args);

        error_log("Outbound Operator: Request completed for URL: " . $url);

        $recursion_guard = false;
        return $response;
    }

    private function log_call($url) {
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'url' => $url,
                'time' => current_time('mysql'),
                'hook' => current_filter()
            ),
            array('%s', '%s', '%s')
        );
    }

    private function strip_query_string($url) {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
            error_log("Outbound Operator: Invalid URL encountered in strip_query_string: " . $url);
            return $url; // Return original URL if parsing fails
        }
        $stripped_url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['path']) ? $parts['path'] : '');
        return $stripped_url;
    }

}