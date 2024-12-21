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

// Core plugin constants
define('SERVICE_QUEUE_VERSION', '3.0');
define('SERVICE_QUEUE_MIN_PHP_VERSION', '7.4');
define('SERVICE_QUEUE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SERVICE_QUEUE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Performance and rate limiting
define('SERVICE_QUEUE_MAX_USER_REQUESTS', 3);          // Maximum concurrent requests per user
define('SERVICE_QUEUE_MAX_GLOBAL_PROCESSING', 10);     // Maximum concurrent processing jobs
define('SERVICE_QUEUE_BATCH_SIZE', 100);              // Number of items to process in each batch
define('SERVICE_QUEUE_POLLING_INTERVAL', 5000);       // Frontend polling interval in milliseconds
define('SERVICE_QUEUE_CLEANUP_INTERVAL', 86400);      // 24 hours in seconds
define('SERVICE_QUEUE_CHUNK_SIZE', 500);             // Database operation chunk size
define('SERVICE_QUEUE_STUCK_TIMEOUT', 1800);         // 30 minutes for stuck services
define('SERVICE_QUEUE_MAX_RETRIES', 3);              // Maximum retry attempts for failed services

// Rate limiter settings
define('SERVICE_QUEUE_RATE_LIMIT_WINDOW', 300);      // 5 minutes window for rate limiting
define('SERVICE_QUEUE_RATE_LIMIT_MAX_REQUESTS', 100); // Maximum requests per window

// Cache settings
define('SERVICE_QUEUE_CACHE_GROUP', 'service_queue');
define('SERVICE_QUEUE_CACHE_EXPIRATION', 300);       // 5 minutes cache expiration

// Database settings
define('SERVICE_QUEUE_DB_VERSION', '3.0');
define('SERVICE_QUEUE_TABLE_NAME', 'service_requests');

// Security settings
define('SERVICE_QUEUE_LOCK_TIMEOUT', 30);            // 30 seconds lock timeout

// Debug settings (only if WP_DEBUG is true)
if (defined('WP_DEBUG') && WP_DEBUG) {
    define('SERVICE_QUEUE_DEBUG', true);
    define('SERVICE_QUEUE_DEBUG_LOG', true);
} else {
    define('SERVICE_QUEUE_DEBUG', false);
    define('SERVICE_QUEUE_DEBUG_LOG', false);
}

// Ensure minimum PHP version
if (version_compare(PHP_VERSION, SERVICE_QUEUE_MIN_PHP_VERSION, '<')) {
    add_action('admin_notices', function () {
        $message = sprintf(
            __('Service Queue requires PHP version %s or higher. You are running version %s.', 'service-queue'),
            SERVICE_QUEUE_MIN_PHP_VERSION,
            PHP_VERSION
        );
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    });
    return;
}

// Define table name with prefix
global $wpdb;
if (!defined('SERVICE_QUEUE_TABLE_FULL_NAME')) {
    define('SERVICE_QUEUE_TABLE_FULL_NAME', $wpdb->prefix . SERVICE_QUEUE_TABLE_NAME);
}

// Load dependencies
require_once SERVICE_QUEUE_PLUGIN_DIR . 'vendor/autoload.php';
require_once SERVICE_QUEUE_PLUGIN_DIR . 'includes/class-service-queue.php';
require_once SERVICE_QUEUE_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once SERVICE_QUEUE_PLUGIN_DIR . 'includes/class-error-handler.php';

// Initialize Action Scheduler
function init_action_scheduler()
{
    if (class_exists('ActionScheduler_Versions') && !function_exists('as_unschedule_all_actions')) {
        ActionScheduler_Versions::initialize_latest_version();
    }
}
add_action('init', 'init_action_scheduler', 1);

// Initialize plugin
function init_service_queue()
{
    // Check PHP version first
    if (version_compare(PHP_VERSION, SERVICE_QUEUE_MIN_PHP_VERSION, '<')) {
        add_action('admin_notices', function () {
            $message = sprintf(
                __('Service Queue requires PHP version %s or higher. You are running version %s.', 'service-queue'),
                SERVICE_QUEUE_MIN_PHP_VERSION,
                PHP_VERSION
            );
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        });
        return;
    }

    // Initialize main plugin class
    $service_queue = ServiceQueue::getInstance();

    // Register shortcode immediately
    $service_queue->registerShortcode();

    // Schedule recurring tasks
    if (!wp_next_scheduled('service_queue_cleanup')) {
        wp_schedule_event(time(), 'daily', 'service_queue_cleanup');
    }
    if (!wp_next_scheduled('service_queue_manage_partitions')) {
        wp_schedule_event(time(), 'daily', 'service_queue_manage_partitions');
    }
    if (!wp_next_scheduled('process_service_batch')) {
        wp_schedule_event(time(), 'every_minute', 'process_service_batch');
    }
    if (!wp_next_scheduled('service_queue_cleanup_stuck')) {
        wp_schedule_event(time(), 'hourly', 'service_queue_cleanup_stuck');
    }
}
add_action('init', 'init_service_queue', 5);

// Register hooks for scheduled tasks
add_action('service_queue_cleanup', [ServiceQueue::getInstance(), 'cleanupOldRequests']);
add_action('service_queue_manage_partitions', [ServiceQueue::getInstance(), 'managePartitions']);
add_action('process_service_batch', [ServiceQueue::getInstance(), 'processNextBatch']);
add_action('service_queue_cleanup_stuck', [ServiceQueue::getInstance(), 'cleanupStuckServices']);

function service_queue_check_conflicts()
{
    if (!is_admin() && is_singular() && has_shortcode(get_post()->post_content, 'service_queue')) {
        global $wp_scripts;
        $vue_versions = [];

        foreach ($wp_scripts->registered as $handle => $script) {
            if (strpos($script->src, 'vue') !== false) {
                $vue_versions[] = $handle;
            }
        }

        if (count($vue_versions) > 1) {
            error_log('Service Queue: Multiple Vue.js versions detected: ' . implode(', ', $vue_versions));
        }
    }
}
add_action('wp_enqueue_scripts', 'service_queue_check_conflicts', 999);

// Enqueue assets
function service_queue_enqueue_scripts()
{
    if (!should_load_service_queue()) {
        return;
    }

    $manifest = load_asset_manifest();
    enqueue_core_styles($manifest);
    enqueue_core_scripts($manifest);
    localize_service_queue_data();
    add_error_handling_script();
}

function should_load_service_queue()
{
    return !is_admin() &&
        is_singular() &&
        has_shortcode(get_post()->post_content, 'service_queue');
}

function load_asset_manifest()
{
    $manifest_path = SERVICE_QUEUE_PLUGIN_DIR . 'assets/dist/manifest.json';
    return file_exists($manifest_path)
        ? json_decode(file_get_contents($manifest_path), true)
        : null;
}

function enqueue_core_styles($manifest)
{
    if (isset($manifest['assets/src/style.css']['file'])) {
        wp_register_style(
            'service-queue-css',
            SERVICE_QUEUE_PLUGIN_URL . 'assets/dist/' . $manifest['assets/src/style.css']['file'],
            [],
            SERVICE_QUEUE_VERSION
        );
        wp_enqueue_style('service-queue-css');
    }
}

function enqueue_core_scripts($manifest)
{
    // Enqueue Vue runtime
    wp_register_script(
        'vue',
        SERVICE_QUEUE_PLUGIN_URL . (
            $manifest
            ? 'assets/dist/' . ($manifest['vue.js']['file'] ?? 'vue.runtime.js')
            : 'node_modules/vue/dist/vue.runtime.global.js'
        ),
        [],
        '3.3.4',
        true
    );
    wp_enqueue_script('vue');

    // Enqueue main app script as module
    $main_js = $manifest
        ? 'assets/dist/' . ($manifest['assets/src/main.js']['file'] ?? 'app.js')
        : 'assets/dist/app.js';

    if (file_exists(SERVICE_QUEUE_PLUGIN_DIR . $main_js)) {
        wp_register_script(
            'service-queue-js',
            SERVICE_QUEUE_PLUGIN_URL . $main_js,
            ['vue', 'jquery', 'wp-api'],
            SERVICE_QUEUE_VERSION,
            [
                'in_footer' => true,
                'strategy' => 'defer',
                'type' => 'module'
            ]
        );
        // Add type="module" attribute
        wp_script_add_data('service-queue-js', 'type', 'module');
        wp_enqueue_script('service-queue-js');
    } else {
        error_log('Service Queue: Main JS file not found: ' . $main_js);
    }
}

function get_translations()
{
    return [
        'confirmReset' => __('Are you sure you want to reset all services?', 'service-queue'),
        'confirmRecreate' => __('Are you sure you want to recreate the table?', 'service-queue'),
        'pending' => __('Pending', 'service-queue'),
        'inProgress' => __('In Progress', 'service-queue'),
        'completed' => __('Completed', 'service-queue'),
        'error' => __('Error', 'service-queue'),
        'loading' => __('Loading...', 'service-queue'),
        'retryAttempt' => __('Retry attempt', 'service-queue'),
        'retrying' => __('Retrying...', 'service-queue'),
        'estimatedWait' => __('Estimated wait', 'service-queue'),
        'estimatedCompletion' => __('Estimated completion', 'service-queue'),
        'minutes' => __('minutes', 'service-queue'),
        'rateLimit' => __('Too many requests. Please try again later.', 'service-queue'),
        'networkError' => __('Network error. Please check your connection.', 'service-queue'),
        'unknownError' => __('An unknown error occurred.', 'service-queue'),
        'errorMessage' => __('Something went wrong. Please refresh the page.', 'service-queue'),
        'newService' => __('New Service', 'service-queue'),
        'reset' => __('Reset All', 'service-queue'),
        'recreate' => __('Recreate Table', 'service-queue'),
    ];
}

function get_user_data()
{
    return [
        'id' => get_current_user_id(),
        'can_manage' => current_user_can('manage_options'),
        'is_premium' => current_user_can('premium_member') || current_user_can('administrator')
    ];
}

function localize_service_queue_data()
{
    wp_localize_script(
        'service-queue-js',
        'serviceQueueAjax',
        [
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => esc_url_raw(rest_url()),
            'nonce' => wp_create_nonce('service_queue_nonce'),
            'pollingInterval' => SERVICE_QUEUE_POLLING_INTERVAL,
            'maxRetries' => SERVICE_QUEUE_MAX_RETRIES,
            'maxUserRequests' => SERVICE_QUEUE_MAX_USER_REQUESTS,
            'maxGlobalProcessing' => SERVICE_QUEUE_MAX_GLOBAL_PROCESSING,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'translations' => get_translations(),
            'user' => get_user_data(),
            'security' => [
                'rest_nonce' => wp_create_nonce('wp_rest')
            ]
        ]
    );
}

function add_error_handling_script()
{
    $error_message = esc_js(__('Failed to load Service Queue. Please refresh the page.', 'service-queue'));
    $error_script = "
        window.addEventListener('error', function(e) {
            if (e.filename && e.filename.includes('service-queue')) {
                console.error('Service Queue loading error:', e);
                if (document.getElementById('service-queue-app')) {
                    document.getElementById('service-queue-app').innerHTML =
                        '<div class=\"sq-error-message\">{$error_message}</div>';
                }
            }
        }, true);
    ";

    wp_add_inline_script('service-queue-js', $error_script, 'before');
}
add_action('wp_enqueue_scripts', 'service_queue_enqueue_scripts');

// Activation hook
register_activation_hook(__FILE__, function () {
    // Create/update database tables
    ServiceQueue::getInstance()->createTable();

    // Clear any existing schedules
    wp_clear_scheduled_hook('service_queue_cleanup');
    wp_clear_scheduled_hook('process_service_batch');
    wp_clear_scheduled_hook('service_queue_manage_partitions');
    wp_clear_scheduled_hook('service_queue_cleanup_stuck');

    // Schedule initial tasks
    wp_schedule_event(time(), 'daily', 'service_queue_cleanup');
    wp_schedule_event(time(), 'daily', 'service_queue_manage_partitions');
    wp_schedule_event(time(), 'every_minute', 'process_service_batch');
    wp_schedule_event(time(), 'hourly', 'service_queue_cleanup_stuck');

    // Flush rewrite rules
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Clear scheduled tasks
    wp_clear_scheduled_hook('service_queue_cleanup');
    wp_clear_scheduled_hook('process_service_batch');
    wp_clear_scheduled_hook('service_queue_manage_partitions');
    wp_clear_scheduled_hook('service_queue_cleanup_stuck');

    // Flush rewrite rules
    flush_rewrite_rules();
});

// Uninstall hook
function service_queue_uninstall()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'service_requests';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");

    // Clear all plugin options
    delete_option('service_queue_version');
    delete_option('service_queue_installed');
}

// Register uninstall hook
register_uninstall_hook(__FILE__, 'service_queue_uninstall');
