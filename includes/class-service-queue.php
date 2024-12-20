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

        // Add Action Scheduler hook
        add_action('process_service_step', [$this, 'processServiceStep'], 10, 3);
    }

    public function enqueueAssets()
    {
        // Only enqueue if shortcode is present and user is logged in
        global $post;
        if (
            is_a($post, 'WP_Post') &&
            has_shortcode($post->post_content, 'service_queue') &&
            is_user_logged_in()
        ) {
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
            processing_time INT UNSIGNED NOT NULL,
            progress TINYINT UNSIGNED DEFAULT 0,
            retries TINYINT UNSIGNED DEFAULT 0,
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

        try {
            global $wpdb;
            $current_user_id = get_current_user_id();

            // Check user limit for all users
            $user_pending_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name}
            WHERE status IN ('pending', 'in_progress')
            AND user_id = %d",
                $current_user_id
            ));

            if ($user_pending_count >= SERVICE_QUEUE_MAX_USER_REQUESTS) {
                throw new Exception(__('You have reached the maximum number of concurrent requests.', 'service-queue'));
            }

            $processing_time = wp_rand(10, 30);
            $is_premium = $this->isPremiumUser($current_user_id);

            $wpdb->query('START TRANSACTION');

            // For premium users, always set as in_progress
            // For regular users, check existing services
            $status = $is_premium ? 'in_progress' : 'pending';
            if (!$is_premium) {
                $existing_services = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$this->table_name}
                WHERE status IN ('pending', 'in_progress')"
                );
                $status = ($existing_services === '0') ? 'in_progress' : 'pending';
            }

            $result = $wpdb->insert($this->table_name, [
                'status' => $status,
                'processing_time' => $processing_time,
                'progress' => 0,
                'user_id' => $current_user_id,
                'queue_position' => $status === 'in_progress' ? 0 : $existing_services + 1
            ]);

            if ($result === false) {
                throw new Exception($wpdb->last_error);
            }

            $service_id = $wpdb->insert_id;
            $wpdb->query('COMMIT');

            // Schedule processing immediately for premium users or if first in queue
            if ($status === 'in_progress') {
                as_schedule_single_action(
                    time(),
                    'process_service_step',
                    [
                        'service_id' => $service_id,
                        'step' => 1,
                        'total_steps' => 20
                    ],
                    'service-queue'
                );
            }

            wp_send_json_success([
                'message' => __('Service created successfully', 'service-queue'),
                'service_id' => $service_id,
                'is_premium' => $is_premium
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
            $current_user_id = get_current_user_id();
            $is_premium = $this->isPremiumUser($current_user_id);

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
    public function processServiceStep($service_id, $step, $total_steps)
    {
        global $wpdb;
        $lock_key = "service_lock_{$service_id}";

        $lock_result = $wpdb->get_row($wpdb->prepare(
            "SELECT GET_LOCK(%s, 10) as locked",
            $lock_key
        ));

        if (!$lock_result || !$lock_result->locked) {
            // Reschedule this step if we couldn't get a lock
            as_schedule_single_action(
                time() + 10,
                'process_service_step',
                [
                    'service_id' => $service_id,
                    'step' => $step,
                    'total_steps' => $total_steps
                ],
                'service-queue'
            );
            return;
        }

        try {
            $service = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE service_id = %d",
                $service_id
            ));

            if (!$service) {
                throw new Exception('Service not found');
            }

            // Calculate progress
            $progress = ($step / $total_steps) * 100;

            // Update status and progress
            $status = ($step === $total_steps) ? 'completed' : 'in_progress';

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

            // Schedule next step if not completed
            if ($status === 'completed') {
                // Update queue positions for remaining items
                $wpdb->query(
                    "UPDATE {$this->table_name}
                SET queue_position = queue_position - 1
                WHERE status = 'pending'
                AND queue_position > 1"
                );

                // Start processing next in queue
                $next_service = $wpdb->get_row(
                    "SELECT service_id
                FROM {$this->table_name}
                WHERE status = 'pending'
                AND queue_position = 1
                LIMIT 1"
                );

                if ($next_service) {
                    as_schedule_single_action(
                        time(),
                        'process_service_step',
                        [
                            'service_id' => $next_service->service_id,
                            'step' => 1,
                            'total_steps' => $total_steps
                        ],
                        'service-queue'
                    );
                }
            } elseif ($step < $total_steps) {
                as_schedule_single_action(
                    time() + ceil($service->processing_time / $total_steps),
                    'process_service_step',
                    [
                        'service_id' => $service_id,
                        'step' => $step + 1,
                        'total_steps' => $total_steps
                    ],
                    'service-queue'
                );
            }
        } catch (Exception $e) {
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

    private function isPremiumUser($user_id)
    {
        // Example implementation - modify based on your user group logic
        $user = get_user_by('id', $user_id);
        return user_can($user, 'premium_member') || user_can($user, 'administrator');
    }
}
