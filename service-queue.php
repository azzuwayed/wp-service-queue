<?php

/**
 * Plugin Name: Service Queue
 * Description: Modern service queue system with Vue.js integration
 * Version: 3.0
 * Author: Your Name
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit('Direct access not permitted');
}

define('SERVICE_QUEUE_VERSION', '3.0');
define('SERVICE_QUEUE_MIN_PHP_VERSION', '7.4');
define('SERVICE_QUEUE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SERVICE_QUEUE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Global concurrent processing limits for different load levels
define('SERVICE_QUEUE_GLOBAL_HIGH_LOAD', 5);    // When system load > 70%
define('SERVICE_QUEUE_GLOBAL_MEDIUM_LOAD', 10); // When system load 30-70%
define('SERVICE_QUEUE_GLOBAL_LOW_LOAD', 20);    // When system load < 30%

// Premium user concurrent request limits
define('SERVICE_QUEUE_PREMIUM_HIGH_LOAD', 3);   // When system load > 70%
define('SERVICE_QUEUE_PREMIUM_MEDIUM_LOAD', 5); // When system load 30-70%
define('SERVICE_QUEUE_PREMIUM_LOW_LOAD', 8);    // When system load < 30%

// Free user concurrent request limits
define('SERVICE_QUEUE_FREE_HIGH_LOAD', 1);      // When system load > 70%
define('SERVICE_QUEUE_FREE_MEDIUM_LOAD', 2);    // When system load 30-70%
define('SERVICE_QUEUE_FREE_LOW_LOAD', 4);       // When system load < 30%

// Ensure Action Scheduler is loaded
require_once SERVICE_QUEUE_PLUGIN_DIR . 'vendor/autoload.php';

require_once SERVICE_QUEUE_PLUGIN_DIR . 'includes/class-resource-manager.php';

// Initialize Action Scheduler properly
function init_action_scheduler()
{
    if (class_exists('ActionScheduler_Versions') && !function_exists('as_unschedule_all_actions')) {
        ActionScheduler_Versions::initialize_latest_version();
    }
}
add_action('init', 'init_action_scheduler', 1);

require_once SERVICE_QUEUE_PLUGIN_DIR . 'includes/class-service-queue.php';

// Initialize plugin
add_action('plugins_loaded', function () {
    ServiceQueue::getInstance();
});

// Activation hook
register_activation_hook(__FILE__, function () {
    $instance = ServiceQueue::getInstance();

    // Drop the table if it exists
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");

    // Create the table with new structure

    $instance->createTable();
});
