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

require_once SERVICE_QUEUE_PLUGIN_DIR . 'includes/class-service-queue.php';

// Initialize plugin
add_action('plugins_loaded', function () {
    ServiceQueue::getInstance();
});

// Activation hook
register_activation_hook(__FILE__, function () {
    $instance = ServiceQueue::getInstance();
    $instance->createTable();
});
