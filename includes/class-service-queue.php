<?php

class ServiceQueue
{
    private static $instance = null;
    private $table_name;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'service_requests';
        $this->init();
    }

    private function init()
    {
        if (version_compare(PHP_VERSION, SERVICE_QUEUE_MIN_PHP_VERSION, '<')) {
            add_action('admin_notices', [$this, 'phpVersionNotice']);
            return;
        }

        add_action('init', [$this, 'initializePlugin']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action("wp_ajax_get_system_status", [$this, "handleGetSystemStatus"]);

        // AJAX handlers
        $ajax_actions = [
            'add_service_request',
            'get_service_requests',
            'reset_services',
            'recreate_table'
        ];

        foreach ($ajax_actions as $action) {
            add_action("wp_ajax_{$action}", [$this, "handle" . $this->toCamelCase($action)]);
            add_action("wp_ajax_nopriv_{$action}", [$this, "handle" . $this->toCamelCase($action)]);
        }

        add_action('process_service_request', [$this, 'processRequest']);

        // Add Action Scheduler hook
        add_action('process_service_step', [$this, 'processServiceStep'], 10, 3);

        add_filter('theme_page_templates', [$this, 'addServiceQueueTemplate']);
        add_filter('template_include', [$this, 'loadServiceQueueTemplate']);
    }

    public function addServiceQueueTemplate($templates)
    {
        $templates['page-service-queue.php'] = __('Service Queue App', 'service-queue');
        return $templates;
    }

    public function loadServiceQueueTemplate($template)
    {
        if (is_page_template('page-service-queue.php')) {
            $template = SERVICE_QUEUE_PLUGIN_DIR . 'templates/page-service-queue.php';
        }
        return $template;
    }

    public function enqueueAssets()
    {
        // Only enqueue if shortcode is present and user is logged in
        if (
            is_page_template('page-service-queue.php') ||
            (is_a($GLOBALS['post'], 'WP_Post') && has_shortcode($GLOBALS['post']->post_content, 'service_queue'))
        ) {
            if (!is_user_logged_in()) {
                return;
            }

            wp_enqueue_style(
                'service-queue-css',
                SERVICE_QUEUE_PLUGIN_URL . 'assets/dist/style.css',
                [],
                SERVICE_QUEUE_VERSION
            );

            wp_enqueue_script(
                'service-queue-js',
                SERVICE_QUEUE_PLUGIN_URL . 'assets/dist/app.js',
                [],
                SERVICE_QUEUE_VERSION,
                true
            );

            wp_localize_script('service-queue-js', 'serviceQueueAjax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('service_queue_nonce'),
                'translations' => $this->getTranslations()
            ]);
        }
    }

    private function getTranslations()
    {
        return [
            'confirmReset' => __('Are you sure you want to reset all services?', 'service-queue'),
            'confirmRecreate' => __('Are you sure you want to recreate the table?', 'service-queue'),
            'pending' => __('Pending', 'service-queue'),
            'inProgress' => __('In Progress', 'service-queue'),
            'completed' => __('Completed', 'service-queue'),
            'error' => __('Error', 'service-queue'),
            'loading' => __('Loading...', 'service-queue'),
            'systemStatus' => __('System Status', 'service-queue'),
            'loadLevel' => __('Load Level', 'service-queue'),
            'systemLoad' => __('System Load', 'service-queue'),
            'queueSize' => __('Queue Size', 'service-queue'),
            'globalLimit' => __('Global Limit', 'service-queue'),
            'premiumLimit' => __('Premium User Limit', 'service-queue'),
            'freeLimit' => __('Free User Limit', 'service-queue')
        ];
    }

    public function initializePlugin()
    {
        add_shortcode('service_queue', [$this, 'renderQueue']);
    }

    public function renderQueue()
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return sprintf(
                '<p>%s</p>',
                esc_html__('Please log in to access the service queue.', 'service-queue')
            );
        }

        ob_start();
        include SERVICE_QUEUE_PLUGIN_DIR . 'templates/queue.php';
        return ob_get_clean();
    }

    public function createTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        try {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            service_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            queue_position INT UNSIGNED DEFAULT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'in_progress', 'completed', 'error') DEFAULT 'pending',
            processing_time INT UNSIGNED NOT NULL DEFAULT 0,
            progress TINYINT UNSIGNED DEFAULT 0,
            retries TINYINT UNSIGNED DEFAULT 0,
            is_premium BOOLEAN DEFAULT 0,
            error_message TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_timestamp (timestamp),
            INDEX idx_user_status (user_id, status),
            INDEX idx_status_timestamp (status, timestamp),
            INDEX idx_queue_position (queue_position)
        ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            // Verify table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
            if (!$table_exists) {
                throw new Exception('Table creation failed');
            }
        } catch (Exception $e) {
            error_log('Error creating service_requests table: ' . $e->getMessage());
            throw $e;
        }
    }

    public function handleAddServiceRequest()
    {
        $this->verifyNonce();

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $current_user_id = get_current_user_id();
            if (!$current_user_id) {
                throw new Exception(__('You must be logged in to create a service request', 'service-queue'));
            }

            $resource_manager = ServiceQueueResourceManager::getInstance();

            if (!$resource_manager->canAcceptNewRequest($current_user_id)) {
                throw new Exception(__('System is currently at capacity. Please try again later.', 'service-queue'));
            }

            // Get current queue position
            $queue_position = $wpdb->get_var(
                "SELECT COUNT(*) + 1 FROM {$this->table_name}
             WHERE status IN ('pending', 'in_progress')"
            );

            // Insert new service request
            $result = $wpdb->insert(
                $this->table_name,
                [
                    'user_id' => $current_user_id,
                    'status' => 'pending',
                    'progress' => 0,
                    'queue_position' => $queue_position,
                    'timestamp' => current_time('mysql'),
                    'is_premium' => $resource_manager->isPremiumUser($current_user_id)
                ],
                ['%d', '%s', '%d', '%d', '%s', '%d']
            );

            if (!$result) {
                throw new Exception(__('Failed to create service request', 'service-queue'));
            }

            $service_id = $wpdb->insert_id;

            // Schedule the first processing attempt
            as_schedule_single_action(
                time(),
                'process_service_request',
                ['service_id' => $service_id],
                'service-queue'
            );

            $wpdb->query('COMMIT');

            $resource_manager->incrementUserRequests($current_user_id);

            wp_send_json_success([
                'message' => __('Service request created successfully', 'service-queue'),
                'service_id' => $service_id
            ]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }


    public function handleGetServiceRequests()
    {
        $this->verifyNonce();

        try {
            global $wpdb;
            $current_user_id = get_current_user_id();

            // Get the resource manager instance
            $resource_manager = ServiceQueueResourceManager::getInstance();
            $is_premium = $resource_manager->isPremiumUser($current_user_id);

            $cache_key = 'service_queue_requests_' . $current_user_id;
            $results = wp_cache_get($cache_key);

            if (false === $results) {
                $results = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$this->table_name}
                WHERE user_id = %d
                AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY timestamp DESC
                LIMIT %d",
                    $current_user_id,
                    100
                ));

                // Add premium status to results
                foreach ($results as $result) {
                    $result->is_premium = $is_premium;
                }

                wp_cache_set($cache_key, $results, '', 30);
            }

            wp_send_json_success($results);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleResetServices()
    {
        $this->verifyNonce();

        try {
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");

            if ($result === false) {
                throw new Exception($wpdb->last_error);
            }

            $wpdb->query('COMMIT');
            wp_cache_delete('service_queue_requests');

            wp_send_json_success(__('Services reset successfully', 'service-queue'));
        } catch (Exception $e) {
            global $wpdb;
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleRecreateTable()
    {
        $this->verifyNonce();

        try {
            global $wpdb;
            $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
            $this->createTable();
            wp_cache_delete('service_queue_requests');

            wp_send_json_success(__('Table recreated successfully', 'service-queue'));
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Process a single step of the service request
     */
    public function processServiceStep($service_id, $step = 1, $total_steps = 10)
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            // Get current service status
            $service = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE service_id = %d FOR UPDATE",
                $service_id
            ));

            if (!$service) {
                throw new Exception("Service not found: {$service_id}");
            }

            if ($service->status === 'completed' || $service->status === 'error') {
                $wpdb->query('COMMIT');
                return;
            }

            // Update status to in_progress if pending
            if ($service->status === 'pending') {
                $wpdb->update(
                    $this->table_name,
                    ['status' => 'in_progress'],
                    ['service_id' => $service_id],
                    ['%s'],
                    ['%d']
                );
            }

            // Calculate progress
            $progress = min(100, ($step / $total_steps) * 100);
            $status = $progress >= 100 ? 'completed' : 'in_progress';

            // Simulate work with random delay
            usleep(rand(100000, 500000)); // 0.1 to 0.5 seconds

            // Update progress
            $wpdb->update(
                $this->table_name,
                [
                    'progress' => $progress,
                    'status' => $status
                ],
                ['service_id' => $service_id],
                ['%d', '%s'],
                ['%d']
            );

            // Schedule next step if not complete
            if ($status !== 'completed') {
                as_schedule_single_action(
                    time() + rand(1, 5),
                    'process_service_request',
                    [
                        'service_id' => $service_id,
                        'step' => $step + 1,
                        'total_steps' => $total_steps
                    ],
                    'service-queue'
                );
            } else {
                // Service is completed, decrement user requests
                $resource_manager = ServiceQueueResourceManager::getInstance();
                $resource_manager->decrementUserRequests($service->user_id);

                // Update queue positions for remaining services
                $wpdb->query(
                    "UPDATE {$this->table_name}
                 SET queue_position = queue_position - 1
                 WHERE status = 'pending'
                 AND queue_position > {$service->queue_position}"
                );
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("Service Queue Error: " . $e->getMessage());

            // Update service status to error
            $wpdb->update(
                $this->table_name,
                [
                    'status' => 'error',
                    'error_message' => $e->getMessage()
                ],
                ['service_id' => $service_id],
                ['%s', '%s'],
                ['%d']
            );
        }
    }

    private function verifyNonce()
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error([
                'message' => __('You must be logged in to perform this action.', 'service-queue'),
                'code' => 401
            ]);
        }

        // Check nonce
        if (!check_ajax_referer('service_queue_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'service-queue'),
                'code' => 403
            ]);
        }
    }

    private function toCamelCase($string)
    {
        return str_replace('_', '', ucwords($string, '_'));
    }

    public function phpVersionNotice()
    {
        $message = sprintf(
            __('Service Queue requires PHP version %s or higher. You are running version %s.', 'service-queue'),
            SERVICE_QUEUE_MIN_PHP_VERSION,
            PHP_VERSION
        );
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }

    public function handleGetSystemStatus()
    {
        try {
            check_ajax_referer('service_queue_nonce', 'nonce');

            if (!is_user_logged_in()) {
                wp_send_json_error([
                    'message' => 'User not logged in',
                    'code' => 401
                ]);
            }

            $resource_manager = ServiceQueueResourceManager::getInstance();
            $status = $resource_manager->getSystemStatus();

            if (!$status) {
                wp_send_json_error([
                    'message' => 'Failed to get system status',
                    'code' => 500
                ]);
            }

            wp_send_json_success($status);
        } catch (Exception $e) {
            error_log('Service Queue System Status Error: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => 500
            ]);
        }
    }
}
