<?php
/**
 * Plugin Name: Outbound Operator
 * Plugin URI: https://github.com/403pagelabs/outbound-operator
 * Description: Log, block, rewrite outbound calls from wp-admin.
 * Version: 0.9.4
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: 403Page
 * Author URI: https://403page.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: outbound-operator
 * Domain Path: /languages
 * Update URI: https://github.com/403pagelabs/outbound-operator

 * Outbound Operator is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 * 
 * Outbound Operator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with Outbound Operator. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('OUTBOUND_OPERATOR_VERSION', '0.9.4');
define('OUTBOUND_OPERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OUTBOUND_OPERATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once plugin_dir_path(__FILE__) . 'includes/class-github-updater.php';

// Define cleanup constants
define('OUTBOUND_OPERATOR_DEFAULT_RETENTION_DAYS', 14);
define('OUTBOUND_OPERATOR_DEFAULT_MAX_RECORDS', 99);

// Add registration of cleanup hook
register_activation_hook(__FILE__, 'outbound_operator_activate');
register_deactivation_hook(__FILE__, 'outbound_operator_deactivate');

function outbound_operator_activate() {
    if (!wp_next_scheduled('outbound_operator_cleanup_event')) {
        wp_schedule_event(time(), 'daily', 'outbound_operator_cleanup_event');
    }
    
    // Set default options if they don't exist
    add_option('outbound_operator_retention_days', OUTBOUND_OPERATOR_DEFAULT_RETENTION_DAYS);
    add_option('outbound_operator_max_records', OUTBOUND_OPERATOR_DEFAULT_MAX_RECORDS);
}

function outbound_operator_deactivate() {
    wp_clear_scheduled_hook('outbound_operator_cleanup_event');
}

// Add cleanup function
function outbound_operator_cleanup_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'outbound_calls_log';
    
    // Get settings
    $retention_days = get_option('outbound_operator_retention_days', OUTBOUND_OPERATOR_DEFAULT_RETENTION_DAYS);
    $max_records = get_option('outbound_operator_max_records', OUTBOUND_OPERATOR_DEFAULT_MAX_RECORDS);
    
    // Get blocked hosts and paths
    $blocked_hosts = get_option('outbound_call_logger_blocked_hosts', array());
    $blocked_paths = get_option('outbound_call_logger_blocked_paths', array());
    
    // First, identify URLs with settings (blocks or rewrites)
    $urls_with_settings = array();
    
    // Add URLs from blocked hosts
    foreach ($blocked_hosts as $host) {
        $urls_with_settings[] = $wpdb->prepare('url LIKE %s', $host . '%');
    }
    
    // Add blocked paths directly
    foreach ($blocked_paths as $path) {
        $urls_with_settings[] = $wpdb->prepare('url = %s', $path);
    }
    
    // Add URLs with rewrites
    $urls_with_rewrites = $wpdb->get_col("
        SELECT DISTINCT url 
        FROM $table_name 
        WHERE rewrite_url IS NOT NULL 
        AND rewrite_url != ''
    ");
    
    foreach ($urls_with_rewrites as $url) {
        $urls_with_settings[] = $wpdb->prepare('url = %s', $url);
    }
    
    // Build the WHERE clause for URLs with settings
    $urls_with_settings_clause = !empty($urls_with_settings) 
        ? 'OR (' . implode(' OR ', $urls_with_settings) . ')'
        : '';
    
    // Keep only the most recent record for each URL that has settings
    if (!empty($urls_with_settings_clause)) {
        $wpdb->query("
            DELETE t1 FROM $table_name t1
            LEFT JOIN (
                SELECT url, MAX(time) as max_time
                FROM $table_name
                WHERE " . substr($urls_with_settings_clause, 3) . "
                GROUP BY url
            ) t2 ON t1.url = t2.url AND t1.time = t2.max_time
            WHERE (" . substr($urls_with_settings_clause, 3) . ")
            AND t2.max_time IS NULL
        ");
    }
    
    // Delete old records (except manual entries and those with settings)
    $wpdb->query($wpdb->prepare("
        DELETE FROM $table_name 
        WHERE time < DATE_SUB(NOW(), INTERVAL %d DAY)
        AND is_manual = 0
        AND NOT (url IN (
            SELECT url FROM (
                SELECT DISTINCT url
                FROM $table_name
                WHERE is_manual = 1
                OR rewrite_url IS NOT NULL
                OR rewrite_url != ''
                " . ($urls_with_settings_clause ? $urls_with_settings_clause : '') . "
            ) AS urls_to_keep
        ))",
        $retention_days
    ));
    
    // If still over max records, delete oldest records while preserving:
    // - Manual entries
    // - URLs with settings (keeping only most recent)
    // - Most recent records up to max_records
    $total_records = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM $table_name 
        WHERE is_manual = 0
        AND NOT (" . substr($urls_with_settings_clause, 3) . ")
    ");
    
    if ($total_records > $max_records) {
        $records_to_keep = $max_records;
        
        $wpdb->query($wpdb->prepare("
            DELETE t1 FROM $table_name t1
            LEFT JOIN (
                SELECT url, MAX(time) as max_time
                FROM $table_name
                WHERE is_manual = 0
                AND NOT (" . substr($urls_with_settings_clause, 3) . ")
                GROUP BY url
                ORDER BY max_time DESC
                LIMIT %d
            ) t2 ON t1.url = t2.url AND t1.time = t2.max_time
            WHERE t1.is_manual = 0
            AND NOT (" . substr($urls_with_settings_clause, 3) . ")
            AND t2.max_time IS NULL",
            $records_to_keep
        ));
    }
    
    // Optimize table
    $wpdb->query("OPTIMIZE TABLE $table_name");
}

// Register the cleanup hook
add_action('outbound_operator_cleanup_event', 'outbound_operator_cleanup_logs');

// Include necessary files
require_once OUTBOUND_OPERATOR_PLUGIN_DIR . 'includes/class-outbound-call-logger.php';
require_once OUTBOUND_OPERATOR_PLUGIN_DIR . 'admin/class-outbound-operator-admin.php';

function get_protocol($url) {
    return parse_url($url, PHP_URL_SCHEME);
}

// Initialize the plugin
function outbound_operator_init() {
    $logger = Outbound_Call_Logger::get_instance();
    $admin = new Outbound_Operator_Admin();

    // Initialize logger only if we're listening
    if (get_option('outbound_operator_is_listening', true)) {
        add_action('plugins_loaded', array($logger, 'init'));
    }

    // Initialize admin
    if (is_admin()) {
        add_action('admin_menu', array($admin, 'add_admin_menu'));
        add_action('admin_init', array($admin, 'admin_init'));
        new Outbound_Operator_GitHub_Updater(__FILE__);
    }
}

outbound_operator_init();