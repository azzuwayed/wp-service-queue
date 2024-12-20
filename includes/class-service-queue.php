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
    }

    public function enqueueAssets()
    {
        // Only enqueue if shortcode is present
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'service_queue')) {
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
        ];
    }

    public function initializePlugin()
    {
        add_shortcode('service_queue', [$this, 'renderQueue']);
    }

    public function renderQueue()
    {
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
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('pending', 'in_progress', 'completed', 'error') DEFAULT 'pending',
                processing_time INT UNSIGNED NOT NULL,
                progress TINYINT UNSIGNED DEFAULT 0,
                retries TINYINT UNSIGNED DEFAULT 0,
                error_message TEXT,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_timestamp (timestamp),
                INDEX idx_status_timestamp (status, timestamp)
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

        try {
            $processing_time = wp_rand(15, 30);

            global $wpdb;
            $wpdb->query('START TRANSACTION');

            $result = $wpdb->insert($this->table_name, [
                'status' => 'pending',
                'processing_time' => $processing_time,
                'progress' => 0
            ]);

            if ($result === false) {
                throw new Exception($wpdb->last_error);
            }

            $service_id = $wpdb->insert_id;
            $wpdb->query('COMMIT');

            wp_schedule_single_event(time(), 'process_service_request', [$service_id]);

            wp_send_json_success([
                'message' => __('Service created successfully', 'service-queue'),
                'service_id' => $service_id
            ]);
        } catch (Exception $e) {
            global $wpdb;
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetServiceRequests()
    {
        $this->verifyNonce();

        try {
            global $wpdb;

            $cache_key = 'service_queue_requests';
            $results = wp_cache_get($cache_key);

            if (false === $results) {
                $results = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$this->table_name}
                     WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     ORDER BY timestamp DESC
                     LIMIT %d",
                    100
                ));

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

    public function processRequest($service_id)
    {
        global $wpdb;
        $lock_key = "service_lock_{$service_id}";

        $lock_result = $wpdb->get_row($wpdb->prepare(
            "SELECT GET_LOCK(%s, 10) as locked",
            $lock_key
        ));

        if (!$lock_result || !$lock_result->locked) {
            error_log("Failed to acquire lock for service {$service_id}");
            return;
        }

        try {
            $wpdb->query('START TRANSACTION');

            $service = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                 WHERE service_id = %d
                 AND status IN ('pending', 'in_progress')
                 FOR UPDATE",
                $service_id
            ));

            if (!$service) {
                throw new Exception('Service not found or already completed');
            }

            $steps = 20;
            $step_time = (int)ceil($service->processing_time / $steps);

            for ($i = 1; $i <= $steps; $i++) {
                $progress = ($i / $steps) * 100;
                $status = $i === $steps ? 'completed' : 'in_progress';

                $updated = $wpdb->update(
                    $this->table_name,
                    [
                        'status' => $status,
                        'progress' => $progress,
                        'last_updated' => current_time('mysql')
                    ],
                    ['service_id' => $service_id]
                );

                if ($updated === false) {
                    throw new Exception('Failed to update service progress');
                }

                if ($i < $steps) {
                    sleep($step_time);
                }
            }

            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('Service Processing Error: ' . $e->getMessage());

            $wpdb->update(
                $this->table_name,
                [
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'last_updated' => current_time('mysql')
                ],
                ['service_id' => $service_id]
            );
        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));
        }
    }

    private function verifyNonce()
    {
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
}