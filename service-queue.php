<?php

/**
 * Plugin Name: Service Queue
 * Description: A plugin to simulate a backend service queue with real-time updates using WebSocket.
 * Version: 1.2
 * Author: Azzuwayed
 */

if (!defined('ABSPATH')) {
    die('Direct access not permitted');
}

// Define constants
define('SERVICE_QUEUE_WS_HOST', '127.0.0.1');
define('SERVICE_QUEUE_WS_PORT', 8080);

function service_queue_enqueue_scripts()
{
    wp_enqueue_style('dashicons');
    wp_enqueue_script('jquery');
    wp_enqueue_script(
        'service-queue-js',
        plugin_dir_url(__FILE__) . 'assets/script.js',
        array('jquery'),
        '1.2',
        true
    );
    wp_enqueue_style(
        'service-queue-css',
        plugin_dir_url(__FILE__) . 'assets/style.css',
        array(),
        '1.2'
    );

    wp_localize_script(
        'service-queue-js',
        'serviceQueueAjax',
        array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('service_queue_nonce')
        )
    );

    wp_localize_script(
        'service-queue-js',
        'serviceQueueWS',
        array(
            'enabled' => '1',
            'host' => SERVICE_QUEUE_WS_HOST,
            'port' => SERVICE_QUEUE_WS_PORT,
            'secure' => false
        )
    );
}
add_action('wp_enqueue_scripts', 'service_queue_enqueue_scripts');

function service_queue_create_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        service_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
        processing_time INT,
        progress INT DEFAULT 0
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'service_queue_create_table');

function service_queue_display_button_and_table()
{
    ob_start();
?>
    <div id="app" class="service-queue-app">
        <div class="sq-header">
            <div class="sq-stats">
                <div class="sq-stat-box">
                    <span class="sq-label">Pending</span>
                    <span class="sq-value" id="pending-count">0</span>
                </div>
                <div class="sq-stat-box">
                    <span class="sq-label">In Progress</span>
                    <span class="sq-value" id="progress-count">0</span>
                </div>
                <div class="sq-stat-box">
                    <span class="sq-label">Completed</span>
                    <span class="sq-value" id="completed-count">0</span>
                </div>
            </div>
            <div class="sq-actions">
                <button id="add-service" class="sq-button sq-primary">
                    <span class="sq-icon">+</span> New Service
                </button>
                <button id="reset-services" class="sq-button sq-warning">
                    <span class="sq-icon">↺</span> Reset All
                </button>
                <button id="recreate-table" class="sq-button sq-danger">
                    <span class="sq-icon">⟲</span> Recreate Table
                </button>
            </div>
        </div>
        <div class="sq-content">
            <div id="services-list" class="sq-services"></div>
        </div>
        <div id="toast" class="sq-toast"></div>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('service_queue', 'service_queue_display_button_and_table');

function service_queue_add_request()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    try {
        $processing_time = rand(15, 30);
        $result = $wpdb->insert(
            $table_name,
            array(
                'status' => 'pending',
                'processing_time' => $processing_time,
                'progress' => 0
            )
        );

        if ($result === false) {
            throw new Exception($wpdb->last_error);
        }

        $service_id = $wpdb->insert_id;
        wp_schedule_single_event(time(), 'process_service_request', array($service_id));

        wp_send_json_success(array(
            'message' => 'Service created successfully',
            'service_id' => $service_id,
            'status' => 'pending',
            'processing_time' => $processing_time,
            'progress' => 0,
            'timestamp' => current_time('mysql')
        ));
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_add_service_request', 'service_queue_add_request');
add_action('wp_ajax_nopriv_add_service_request', 'service_queue_add_request');

function service_queue_process_request($service_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    try {
        $wpdb->update(
            $table_name,
            array('status' => 'in_progress', 'progress' => 0),
            array('service_id' => $service_id)
        );

        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE service_id = %d",
            $service_id
        ));

        if (!$service) return;

        $steps = 10;
        $step_time = (int)ceil($service->processing_time / $steps);

        for ($i = 1; $i <= $steps; $i++) {
            sleep($step_time);
            $progress = ($i / $steps) * 100;

            $wpdb->update(
                $table_name,
                array('progress' => $progress),
                array('service_id' => $service_id)
            );
        }

        $wpdb->update(
            $table_name,
            array('status' => 'completed', 'progress' => 100),
            array('service_id' => $service_id)
        );
    } catch (Exception $e) {
        // Silent fail for background process
    }
}
add_action('process_service_request', 'service_queue_process_request');

function service_queue_get_requests()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    try {
        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY timestamp DESC");
        wp_send_json_success($results);
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_get_service_requests', 'service_queue_get_requests');
add_action('wp_ajax_nopriv_get_service_requests', 'service_queue_get_requests');

function service_queue_reset_all()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    try {
        $wpdb->query("TRUNCATE TABLE $table_name");
        wp_send_json_success('Table cleared successfully');
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_reset_services', 'service_queue_reset_all');
add_action('wp_ajax_nopriv_reset_services', 'service_queue_reset_all');

function service_queue_recreate_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';

    try {
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        service_queue_create_table();
        wp_send_json_success('Table recreated successfully');
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}
add_action('wp_ajax_recreate_table', 'service_queue_recreate_table');
add_action('wp_ajax_nopriv_recreate_table', 'service_queue_recreate_table');
