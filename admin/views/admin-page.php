<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

global $wpdb;

$total_items = $wpdb->get_var("SELECT COUNT(DISTINCT url) FROM $this->table_name");
$total_pages = ceil($total_items / $per_page);

// Get sorting parameters
$orderby = isset($_GET['orderby']) ? $_GET['orderby'] : 'last_call';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Prepare the ORDER BY clause
$order_clause = $this->prepare_order_clause($orderby, $order);

$items = $wpdb->get_results($wpdb->prepare(
    "SELECT url, MAX(time) as last_call, 
            COUNT(CASE WHEN time > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as request_count_24h
    FROM $this->table_name t
    GROUP BY url
    {$order_clause}
    LIMIT %d OFFSET %d",
    $per_page, $offset
));

?>
<div class="wrap">
<h1 class="wp-heading-inline">Outbound Operator</h1>
    <?php
    do_action('admin_notices');
    $this->display_admin_notices();
    ?>
    <hr class="wp-header-end">

    <div class="top-controls">
        <div class="listening-toggle-container">
            <label class="switch">
                <input type="checkbox" id="listening-toggle" <?php checked($is_listening); ?>>
                <span class="slider round"></span>
            </label>
            <span id="listening-status" class="<?php echo $is_listening ? 'listening' : ''; ?>">
                <?php echo $is_listening ? 'Listening' : 'Not listening'; ?>
            </span>
        </div>
        <div class="search-container">
            <input type="text" id="outbound-search" placeholder="Search URLs...">
        </div>
        <div class="top-buttons-container">
            <button id="export-settings" class="button">Export Settings</button>
            <input type="file" id="import-file" accept=".json" style="display: none;">
            <button id="import-settings" class="button">Import Settings</button>
            <button id="delete-all" class="button button-link-delete">Delete All Records</button>
            <a href="<?php echo esc_url(add_query_arg('page', 'outbound-operator', admin_url('tools.php'))); ?>" class="button refresh-button dashicons-before dashicons-update-alt" title="Refresh"></a>
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes" form="outbound-operator-form" disabled>
        </div>
    </div>

    <?php if (empty($results)): ?>
        <div class="notice notice-info">
            <p>No outbound calls logged yet.</p>
        </div>
    <?php else: ?>
        <form id="outbound-operator-form" method="post">
            <?php wp_nonce_field('outbound_operator_save_settings', 'outbound_operator_nonce'); ?>
            <table class="wp-list-table widefat fixed striped" id="outbound-calls-table">
            <thead>
                <tr>
                    <?php
                    $columns = array(
                        'security' => '<span class="security-header-icon" title="Security (HTTPS/HTTP)">&#128274;</span>',
                        'domain' => 'Domain',
                        'path' => 'Path',
                        'last_call' => 'Last Call',
                        'request_count_24h' => 'Requests (24h)',
                        'block_host' => 'Block Host',
                        'block_path' => 'Block Path',
                        'rewrite_url' => 'Rewrite URL',
                        'is_manual' => 'Manual Entry',
                        'delete' => ''
                    );
                    foreach ($columns as $column_key => $column_display_name) :
                    ?>
                        <th scope="col" class="manage-column column-<?php echo $column_key; ?>">
                            <span><?php echo $column_display_name; ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <?php
                        $domain = esc_html($row->domain);
                        $path = esc_html($row->path);
                        $full_url = esc_url($row->url);
                        $rewrite_url = esc_url($row->rewrite_url);
                        ?>
                        <tr>
                            <td class="column-security">
                                <?php
                                $is_secure = strpos($row->url, 'https://') === 0;
                                $icon_color = $is_secure ? '#00a000' : '#ca4a1f';
                                $icon_title = $is_secure ? 'Secure (HTTPS)' : 'Not Secure (HTTP)';
                                ?>
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
                            <td class="column-domain"><?php echo $domain; ?></td>
                            <td class="column-path">
                                <?php echo $path; ?>
                                <span class="copy-url" data-url="<?php echo esc_attr($full_url); ?>" title="Copy full URL">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                </span>
                            </td>
                            <td class="column-last_call">
                                <?php 
                                if ($row->is_manual && $row->has_outbound_call == 0) {
                                    echo 'None';
                                } else {
                                    echo esc_html($row->last_call);
                                }
                                ?>
                            </td>
                            <td class="column-request_count_24h">
                                <?php 
                                if ($row->is_manual && $row->has_outbound_call == 0) {
                                    echo '0';
                                } else {
                                    echo esc_html($row->request_count_24h);
                                }
                                ?>
                            </td>
                            <td class="column-block_host">
                                <label class="switch">
                                    <input type="checkbox" name="blocked_hosts[]" value="<?php echo $domain; ?>" <?php checked(in_array($domain, $blocked_hosts)); ?>>
                                    <span class="slider round"></span>
                                </label>
                            </td>
                            <td class="column-block_path">
                                <label class="switch">
                                    <input type="checkbox" name="blocked_paths[]" value="<?php echo $full_url; ?>" <?php checked(in_array($full_url, $blocked_paths)); ?>>
                                    <span class="slider round"></span>
                                </label>
                            </td>
                            <td class="column-rewrite_url">
                            <input type="text" name="rewrite_urls[<?php echo esc_attr($full_url); ?>]" 
                                value="<?php echo esc_attr($row->rewrite_url); ?>" 
                                class="rewrite-url">
                            </td>
                            <td class="column-is_manual"><?php echo $row->is_manual ? '' : ''; ?>
                                <?php if ($row->is_manual): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="green" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                <?php else: ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3498db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="custom-entry-form">
                <h3>Add Custom Domain/Path</h3>
                <input type="text" id="custom-entry" name="custom_entry" placeholder="Enter domain or full URL" />
                <button type="button" id="add-custom-entry" class="button">Add Entry</button>
            </div>
        </form>
    <?php endif; ?>
    <div class="cleanup-settings-section">
        <h2 class="title">Log Cleanup Settings</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="retention_days">Retention Period</label>
                </th>
                <td>
                    <input 
                        type="number" 
                        id="retention_days" 
                        name="outbound_operator_retention_days" 
                        value="<?php echo esc_attr(get_option('outbound_operator_retention_days', OUTBOUND_OPERATOR_DEFAULT_RETENTION_DAYS)); ?>" 
                        class="small-text"
                        min="1"
                    > days
                    <p class="description">Automatically delete records older than this many days (except manual entries and URLs with configured settings)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="max_records">Maximum Records</label>
                </th>
                <td>
                    <input 
                        type="number" 
                        id="max_records" 
                        name="outbound_operator_max_records" 
                        value="<?php echo esc_attr(get_option('outbound_operator_max_records', OUTBOUND_OPERATOR_DEFAULT_MAX_RECORDS)); ?>" 
                        class="small-text"
                        min="1"
                    >
                    <p class="description">Keep only this many most recent records (except manual entries and URLs with configured settings)</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Manual Cleanup</th>
                <td>
                    <button type="button" id="run-cleanup" class="button">Run Cleanup Now</button>
                    <p class="description">Run the cleanup process immediately using the settings above.</p>
                </td>
            </tr>
        </table>
    </div>
</div>
