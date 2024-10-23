<?php

class Outbound_Operator_Admin {
    private $table_name;
    private $blocked_hosts_option = 'outbound_call_logger_blocked_hosts';
    private $blocked_paths_option = 'outbound_call_logger_blocked_paths';
    private $custom_entries_option = 'outbound_call_logger_custom_entries';
    private $listening_option = 'outbound_operator_is_listening';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'outbound_calls_log';
        add_action('wp_ajax_add_custom_entry', array($this, 'ajax_add_custom_entry'));
        add_action('wp_ajax_delete_record', array($this, 'ajax_delete_record'));
        add_action('wp_ajax_load_all_records', array($this, 'ajax_load_all_records'));
        add_action('wp_ajax_search_records', array($this, 'ajax_search_records'));
    }

    public function admin_init() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'outbound_calls_log';
        $this->add_cleanup_settings_section();
    
        // Set default sorting to last_call DESC
        if (!isset($_GET['orderby'])) {
            $_GET['orderby'] = 'last_call';
            $_GET['order'] = 'DESC';
        }
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_export_settings', array($this, 'ajax_export_settings'));
        add_action('wp_ajax_import_settings', array($this, 'ajax_import_settings'));
        add_action('wp_ajax_save_blocked_calls', array($this, 'ajax_save_blocked_calls'));
        add_action('admin_notices', array($this, 'display_admin_notices'));
        add_action('wp_ajax_toggle_listening', array($this, 'ajax_toggle_listening'));
        add_action('wp_ajax_delete_all_records', array($this, 'ajax_delete_all_records'));
        add_action('wp_ajax_search_records', array($this, 'ajax_search_records'));
        add_action('wp_ajax_load_all_records', array($this, 'ajax_load_all_records'));
        add_action('wp_ajax_manual_cleanup', array($this, 'ajax_manual_cleanup'));
    }

    public function enqueue_admin_assets() {
        wp_enqueue_style('outbound-operator-admin-style', OUTBOUND_OPERATOR_PLUGIN_URL . 'admin/css/admin-style.css', array(), OUTBOUND_OPERATOR_VERSION);
        wp_enqueue_script('outbound-operator-admin', OUTBOUND_OPERATOR_PLUGIN_URL . 'admin/js/admin-script.js', array('jquery'), OUTBOUND_OPERATOR_VERSION, true);
        wp_localize_script('outbound-operator-admin', 'outboundCallLogger', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('outbound-operator-nonce')
        ));
    }

    private function add_cleanup_settings_section() {
        add_settings_section(
            'outbound_operator_cleanup_section',
            'Log Cleanup Settings',
            array($this, 'render_cleanup_section'),
            'outbound-operator'
        );
        
        register_setting('outbound-operator', 'outbound_operator_retention_days', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_positive_int'),
            'default' => OUTBOUND_OPERATOR_DEFAULT_RETENTION_DAYS
        ));
        
        register_setting('outbound-operator', 'outbound_operator_max_records', array(
            'type' => 'integer',
            'sanitize_callback' => array($this, 'sanitize_positive_int'),
            'default' => OUTBOUND_OPERATOR_DEFAULT_MAX_RECORDS
        ));
        
        add_settings_field(
            'outbound_operator_retention_days',
            'Retention Period (days)',
            array($this, 'render_retention_days_field'),
            'outbound-operator',
            'outbound_operator_cleanup_section'
        );
        
        add_settings_field(
            'outbound_operator_max_records',
            'Maximum Records',
            array($this, 'render_max_records_field'),
            'outbound-operator',
            'outbound_operator_cleanup_section'
        );
    }

    public function ajax_manual_cleanup() {
        check_ajax_referer('outbound-operator-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        outbound_operator_cleanup_logs();
        wp_send_json_success(array('message' => 'Cleanup completed successfully'));
    }
    
    public function sanitize_positive_int($value) {
        $value = absint($value);
        return max(1, $value);
    }
    
    public function render_cleanup_section() {
        echo '<p>Configure how long to keep log entries and the maximum number of records to maintain. Manual entries are not affected by these settings.</p>';
    }
    
    public function render_retention_days_field() {
        $value = get_option('outbound_operator_retention_days', OUTBOUND_OPERATOR_DEFAULT_RETENTION_DAYS);
        echo '<input type="number" min="1" name="outbound_operator_retention_days" value="' . esc_attr($value) . '">';
        echo '<p class="description">Automatically delete records older than this many days (except manual entries)</p>';
    }
    
    public function render_max_records_field() {
        $value = get_option('outbound_operator_max_records', OUTBOUND_OPERATOR_DEFAULT_MAX_RECORDS);
        echo '<input type="number" min="1" name="outbound_operator_max_records" value="' . esc_attr($value) . '">';
        echo '<p class="description">Keep only this many most recent records (except manual entries)</p>';
    }

    public function ajax_search_records() {
        check_ajax_referer('outbound-operator-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
    
        $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
        global $wpdb;
        $table_name = $this->table_name;
    
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT 
                url,
                SUBSTRING_INDEX(url, '/', 3) as domain,
                SUBSTRING(url, LOCATE('/', url, 9)) as path,
                MAX(time) as last_call,
                COUNT(CASE WHEN time > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as request_count_24h,
                MAX(rewrite_url) as rewrite_url,
                MAX(is_manual) as is_manual
            FROM $table_name
            WHERE url LIKE %s
            GROUP BY url
            ORDER BY last_call DESC
        ", '%' . $wpdb->esc_like($search_term) . '%'));
    
        ob_start();
        foreach ($results as $row) {
            $this->render_table_row($row);
        }
        $html = ob_get_clean();
    
        wp_send_json_success(array('html' => $html));
    }

    public function ajax_load_all_records() {
        check_ajax_referer('outbound-operator-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
    
        global $wpdb;
        $table_name = $this->table_name;
    
        $results = $wpdb->get_results("
            SELECT 
                url,
                SUBSTRING_INDEX(url, '/', 3) as domain,
                SUBSTRING(url, LOCATE('/', url, 9)) as path,
                MAX(time) as last_call,
                COUNT(CASE WHEN time > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as request_count_24h,
                MAX(rewrite_url) as rewrite_url,
                MAX(is_manual) as is_manual
            FROM $table_name
            GROUP BY url
            ORDER BY last_call DESC
        ");
    
        ob_start();
        foreach ($results as $row) {
            $this->render_table_row($row);
        }
        $html = ob_get_clean();
    
        wp_send_json_success(array('html' => $html));
    }

    public function add_admin_menu() {
        add_management_page(
            'Outbound Operator',
            'Outbound Operator',
            'manage_options',
            'outbound-operator',
            array($this, 'admin_page')
        );
    }

    private function parse_url_custom($url) {
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "https://" . $url;
        }
        $parts = parse_url($url);
        $host = isset($parts['scheme']) ? $parts['scheme'] . '://' : 'https://';
        $host .= $parts['host'];
        $path = isset($parts['path']) ? $parts['path'] : '/';
        return array($host, $path);
    }

    private function isValidUrl($url) {
        if (empty($url)) {
            return true; // Empty is allowed (removes rewrite)
        }
        
        // Add protocol if missing
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "http://" . $url;
        }
        
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function ajax_add_custom_entry() {
        check_ajax_referer('outbound-operator-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $entry = isset($_POST['entry']) ? esc_url_raw($_POST['entry']) : '';

        if (empty($entry)) {
            wp_send_json_error(array('message' => 'Invalid entry'));
        }

        global $wpdb;
        $table_name = $this->table_name;

        list($host, $path) = $this->parse_url_custom($entry);
        $full_url = $host . $path;

        // Check if the entry already exists
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE url = %s", $full_url));
        
        if ($exists) {
            wp_send_json_error(array('message' => 'Entry already exists'));
        } else {
            $result = $wpdb->insert(
                $table_name,
                array(
                    'url' => $full_url,
                    'time' => current_time('mysql'),
                    'hook' => 'custom_entry',
                    'is_manual' => 1
                ),
                array('%s', '%s', '%s', '%d')
            );

            if ($result) {
                wp_send_json_success(array('message' => 'Entry added successfully'));
            } else {
                wp_send_json_error(array('message' => 'Failed to add entry to database'));
            }
        }
    }

    public function admin_page() {
        global $wpdb;
        $table_name = $this->table_name;
    
        // Get sorting parameters
        $orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'last_call';
        $order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

        // Prepare the ORDER BY clause
        $order_clause = $this->prepare_order_clause($orderby, $order);

        $query = "
            SELECT 
                url,
                SUBSTRING_INDEX(url, '/', 3) as domain,
                SUBSTRING(url, LOCATE('/', url, 9)) as path,
                MAX(time) as last_call,
                COUNT(CASE WHEN time > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as request_count_24h,
                MAX(rewrite_url) as rewrite_url,
                MAX(is_manual) as is_manual,
                SUM(CASE WHEN is_manual = 0 THEN 1 ELSE 0 END) as has_outbound_call
            FROM $table_name
            GROUP BY url
            {$order_clause}
        ";

        $results = $wpdb->get_results($query);

         // If sorting by domain, we need to do a secondary sort in PHP
         if ($orderby === 'domain') {
            usort($results, function($a, $b) use ($order) {
                $domain_compare = strcasecmp($a->domain, $b->domain);
                if ($domain_compare === 0) {
                    return strcasecmp($a->path, $b->path);
                }
                return $order === 'ASC' ? $domain_compare : -$domain_compare;
            });
        }

        $blocked_hosts = get_option($this->blocked_hosts_option, array());
        $blocked_paths = get_option($this->blocked_paths_option, array());
    
        $is_listening = get_option($this->listening_option, true); 
        
        include OUTBOUND_OPERATOR_PLUGIN_DIR . 'admin/views/admin-page.php';
    }

    private function prepare_order_clause($orderby, $order) {
        $valid_columns = array('last_call', 'request_count_24h', 'domain');
        if (!in_array($orderby, $valid_columns)) {
            $orderby = 'last_call';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        if ($orderby === 'domain') {
            return "ORDER BY domain $order, path ASC";
        }

        return "ORDER BY $orderby $order";
    }
    public function ajax_toggle_listening() {
        check_ajax_referer('outbound-operator-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }

        $is_listening = isset($_POST['is_listening']) ? filter_var($_POST['is_listening'], FILTER_VALIDATE_BOOLEAN) : false;

        update_option($this->listening_option, $is_listening);

        wp_send_json_success(array(
            'message' => $is_listening ? 'Now listening to outbound calls' : 'Stopped listening to outbound calls',
            'is_listening' => $is_listening
        ));
    }

    public function ajax_export_settings() {
        check_ajax_referer('outbound-operator-nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
    
        global $wpdb;
        $table_name = $this->table_name;
    
        $results = $wpdb->get_results("
            SELECT 
                url,
                MAX(time) as last_call,
                COUNT(CASE WHEN time > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as request_count_24h,
                MAX(rewrite_url) as rewrite_url,
                MAX(is_manual) as is_manual,
                MAX(hook) as hook
            FROM $table_name
            GROUP BY url
        ");
    
        $blocked_hosts = get_option($this->blocked_hosts_option, array());
        $blocked_paths = get_option($this->blocked_paths_option, array());
    
        $settings = array(
            'blocked_hosts' => $blocked_hosts,
            'blocked_paths' => $blocked_paths,
            'entries' => $results
        );
    
        wp_send_json($settings);
    }

    public function ajax_import_settings() {
        check_ajax_referer('outbound-operator-nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
        }
    
        $settings = json_decode(stripslashes($_POST['settings']), true);
    
        if (isset($settings['blocked_hosts']) && is_array($settings['blocked_hosts']) &&
            isset($settings['blocked_paths']) && is_array($settings['blocked_paths']) &&
            isset($settings['entries']) && is_array($settings['entries'])) {
            
            update_option($this->blocked_hosts_option, $settings['blocked_hosts']);
            update_option($this->blocked_paths_option, $settings['blocked_paths']);
    
            global $wpdb;
            $table_name = $this->table_name;
    
            // Clear existing entries
            $wpdb->query("TRUNCATE TABLE $table_name");
    
            // Insert new entries
            foreach ($settings['entries'] as $entry) {
                $wpdb->insert(
                    $table_name,
                    array(
                        'url' => $entry['url'],
                        'time' => $entry['last_call'],
                        'rewrite_url' => $entry['rewrite_url'],
                        'is_manual' => $entry['is_manual'],
                        'hook' => $entry['hook']
                    ),
                    array('%s', '%s', '%s', '%d', '%s')
                );
            }
    
            set_transient('outbound_operator_admin_notice', array('type' => 'success', 'message' => 'Settings imported successfully.'), 45);
            wp_send_json_success('Settings imported successfully.');
        } else {
            wp_send_json_error('Invalid settings format.');
        }
    }

    public function ajax_save_blocked_calls() {
        check_ajax_referer('outbound-operator-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
    
        global $wpdb;
        $table_name = $this->table_name;
    
        $blocked_hosts = isset($_POST['blocked_hosts']) ? array_map('esc_url_raw', $_POST['blocked_hosts']) : array();
        $blocked_paths = isset($_POST['blocked_paths']) ? array_map('esc_url_raw', $_POST['blocked_paths']) : array();
        $rewrite_urls = isset($_POST['rewrite_urls']) ? $_POST['rewrite_urls'] : array();

        $retention_days = isset($_POST['retention_days']) ? absint($_POST['retention_days']) : OUTBOUND_OPERATOR_DEFAULT_RETENTION_DAYS;
        $max_records = isset($_POST['max_records']) ? absint($_POST['max_records']) : OUTBOUND_OPERATOR_DEFAULT_MAX_RECORDS;

        $retention_days = max(1, $retention_days);
        $max_records = max(1, $max_records);
    
        // Save blocked hosts and paths
        update_option($this->blocked_hosts_option, array_unique($blocked_hosts));
        update_option($this->blocked_paths_option, array_unique($blocked_paths));
        update_option('outbound_operator_retention_days', $retention_days);
        update_option('outbound_operator_max_records', $max_records);
    
        // Process rewrite URLs
        foreach ($rewrite_urls as $original_url => $rewrite_url) {
            $original_url = esc_url_raw($original_url);
            $rewrite_url = trim(esc_url_raw($rewrite_url));
    
            $existing_entry = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE url = %s",
                $original_url
            ));
    
            if ($existing_entry) {
                // Update existing entry
                $wpdb->update(
                    $table_name,
                    array('rewrite_url' => $rewrite_url ?: null),
                    array('url' => $original_url),
                    array('%s'),
                    array('%s')
                );
            } elseif (!empty($rewrite_url)) {
                // Insert new entry only if rewrite_url is not empty
                $wpdb->insert(
                    $table_name,
                    array(
                        'url' => $original_url,
                        'rewrite_url' => $rewrite_url,
                        'is_manual' => 1,
                        'time' => current_time('mysql')
                    ),
                    array('%s', '%s', '%d', '%s')
                );
            }
        }
    
        wp_send_json_success(array('message' => 'Settings saved successfully'));
    }

    public function ajax_delete_all_records() {
        check_ajax_referer('outbound-operator-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'outbound_calls_log';

        // Delete all records from the table
        $wpdb->query("TRUNCATE TABLE $table_name");

        // Delete the blocked hosts and paths options
        delete_option($this->blocked_hosts_option);
        delete_option($this->blocked_paths_option);

        // Check if the operations were successful
        if ($wpdb->last_error) {
            wp_send_json_error(array('message' => 'Failed to delete records from the database.'));
        } else {
            wp_send_json_success(array('message' => 'All records and blocked settings have been deleted successfully.'));
        }
    }
    
    public function display_admin_notices() {
        $notice = get_transient('outbound_operator_admin_notice');
        if ($notice) {
            $class = 'notice notice-' . $notice['type'] . ' is-dismissible';
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($notice['message']));
            delete_transient('outbound_operator_admin_notice');
        }
    }

    public function ajax_delete_record() {
        check_ajax_referer('outbound-operator-nonce', 'security');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($url)) {
            wp_send_json_error(array('message' => 'Invalid URL'));
        }

        global $wpdb;
        $table_name = $this->table_name;

        $result = $wpdb->delete(
            $table_name,
            array('url' => $url),
            array('%s')
        );

        if ($result !== false) {
            wp_send_json_success(array('message' => 'Record deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete record from database'));
        }
    }

    private function render_table_row($row) {
        $full_url = esc_url($row->url);
        $parsed_url = parse_url($full_url);
        $domain = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';
        $rewrite_url = esc_url($row->rewrite_url);
        $is_secure = $parsed_url['scheme'] === 'https';
        $icon_color = $is_secure ? '#00a000' : '#ca4a1f';
        $icon_title = $is_secure ? 'Secure (HTTPS)' : 'Not Secure (HTTP)';
        ?>
        <tr>
            <td class="column-security">
                <span class="security-icon" title="<?php echo $icon_title; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="<?php echo $icon_color; ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <?php if ($is_secure): ?>
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        <?php else: ?>
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            <line x1="12" y1="16" x2="12" y2="19"></line>
                        <?php endif; ?>
                    </svg>
                </span>
            </td>
            <td class="column-domain"><?php echo esc_html($domain); ?></td>
            <td class="column-path">
                <?php echo esc_html($path); ?>
                <span class="copy-url" data-url="<?php echo esc_attr($full_url); ?>" title="Copy full URL">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                    </svg>
                </span>
            </td>
            <td class="column-last_call"><?php echo esc_html($row->last_call); ?></td>
            <td class="column-request_count_24h"><?php echo esc_html($row->request_count_24h); ?></td>
            <td class="column-block_host">
                <label class="switch">
                    <input type="checkbox" name="blocked_hosts[]" value="<?php echo esc_attr($domain); ?>" <?php checked(in_array($domain, get_option($this->blocked_hosts_option, array()))); ?>>
                    <span class="slider round"></span>
                </label>
            </td>
            <td class="column-block_path">
                <label class="switch">
                    <input type="checkbox" name="blocked_paths[]" value="<?php echo esc_attr($full_url); ?>" <?php checked(in_array($full_url, get_option($this->blocked_paths_option, array()))); ?>>
                    <span class="slider round"></span>
                </label>
            </td>
            <td class="column-rewrite_url">
                <input type="text" name="rewrite_urls[<?php echo esc_attr($full_url); ?>]" 
                    value="<?php echo esc_attr($row->rewrite_url); ?>" 
                    class="rewrite-url">
            </td>
            <td class="column-is_manual">
                <?php if ($row->is_manual): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="green" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3498db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                <?php endif; ?>
            </td>
            <td class="column-delete">
                <svg class="delete-icon" data-url="<?php echo esc_attr($full_url); ?>" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="red" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    <line x1="10" y1="11" x2="10" y2="17"></line>
                    <line x1="14" y1="11" x2="14" y2="17"></line>
                </svg>
            </td>
        </tr>
        <?php
    }
}