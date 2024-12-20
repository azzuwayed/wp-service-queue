<?php

/**
 * Plugin Name: Service Queue
 * Description: A plugin to simulate a backend service queue with real-time updates.
 * Version: 1.0
 * Author: Your Name
 */

// Enqueue Scripts and Styles
function service_queue_enqueue_scripts()
{
    wp_enqueue_style('dashicons');
    wp_enqueue_script('service-queue-js', plugin_dir_url(__FILE__) . 'assets/script.js', array('jquery'), '1.0', true);
    wp_enqueue_style('service-queue-css', plugin_dir_url(__FILE__) . 'assets/style.css', array(), '1.0');

    // Localize AJAX URL
    wp_localize_script('service-queue-js', 'serviceQueue', array(
        'ajax_url' => admin_url('admin-ajax.php')
    ));
}
add_action('wp_enqueue_scripts', 'service_queue_enqueue_scripts');

// Create Custom Table to Store Service Requests
function service_queue_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    // Create table if it doesn't exist
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            service_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
            processing_time INT
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
register_activation_hook(__FILE__, 'service_queue_create_table');

// Register a Shortcode to display the button and table
function service_queue_display_button_and_table()
{
    ob_start();
?>
<div class="service-queue-container">
    <div class="service-queue-header">
        <h2>Service Queue Status</h2>
        <div class="service-queue-controls">
            <button id="service-btn" class="button button-primary">
                <span class="dashicons dashicons-plus"></span> New Service Request
            </button>
            <div class="queue-status">
                <span class="status-indicator"></span>
                <span id="pending-count">0</span> pending requests
                <span id="refresh-timestamp">Last updated: Just now</span>
            </div>
        </div>
    </div>

    <div class="service-queue-table-container">
        <table id="service-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Estimated Time</th>
                    <th>Progress</th>
                </tr>
            </thead>
            <tbody>
                <!-- Dynamically filled with AJAX -->
            </tbody>
        </table>
        <div id="no-requests-message" style="display: none;">
            <p>No service requests found.</p>
        </div>
    </div>
</div>
<?php
    return ob_get_clean();
}
add_shortcode('service_queue', 'service_queue_display_button_and_table');

// Add New Service Request
function service_queue_add_request()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    // Add new service request
    $wpdb->insert(
        $table_name,
        array(
            'status' => 'pending',
            'processing_time' => rand(15, 60)
        )
    );

    // Schedule service processing
    $service_id = $wpdb->insert_id;
    wp_schedule_single_event(time() + 5, 'process_service_request', array($service_id));

    wp_send_json_success();
}
add_action('wp_ajax_add_service_request', 'service_queue_add_request');

// Process Service Request
function service_queue_process_request($service_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    // Simulate processing time
    $processing_time = rand(15, 60);
    sleep($processing_time);

    // Update service status to completed
    $wpdb->update(
        $table_name,
        array('status' => 'completed'),
        array('service_id' => $service_id)
    );

    // Fetch remaining pending services
    $pending_services = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");

    // Send a notification to frontend to update UI
    echo json_encode(array('pending_count' => $pending_services));
    wp_die();
}
add_action('process_service_request', 'service_queue_process_request');

// Get All Service Requests
function service_queue_get_requests()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';
    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY timestamp DESC");

    wp_send_json_success($results);
}
add_action('wp_ajax_get_service_requests', 'service_queue_get_requests');