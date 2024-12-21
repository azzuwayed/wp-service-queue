<?php

class ServiceQueue
{
    private static $instance = null;
    private $table_name;
    private $rate_limiter;
    private $error_handler;
    private $cache_group = 'service_queue';
    private $lock_timeout = 30; // seconds

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
        $this->rate_limiter = new RateLimiter();
        $this->error_handler = new ErrorHandler();

        wp_cache_add_global_groups([$this->cache_group]);
        $this->initializeHooks();
    }

    private function initializeHooks()
    {
        add_action('wp_ajax_add_service_request', [$this, 'handleAddServiceRequest']);
        add_action('wp_ajax_get_service_requests', [$this, 'handleGetServiceRequests']);
        add_action('wp_ajax_reset_services', [$this, 'handleResetServices']);
        add_action('wp_ajax_recreate_table', [$this, 'handleRecreateTable']);
    }

    public function registerShortcode()
    {
        // First check if shortcode exists
        $already_exists = shortcode_exists('service_queue');

        // Only attempt to add if it doesn't exist
        if (!$already_exists) {
            $result = add_shortcode('service_queue', [$this, 'renderQueue']);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                if ($result === false) {
                    error_log('Service Queue: Failed to register shortcode - add_shortcode() returned false');
                }
            }
            return $result;
        }

        // Return true if shortcode already exists since this isn't a failure
        return true;
    }

    public function createTable()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        try {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                service_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                queue_position INT UNSIGNED DEFAULT NULL,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                status ENUM('pending', 'in_progress', 'completed', 'error') DEFAULT 'pending',
                processing_time INT UNSIGNED NOT NULL,
                progress TINYINT UNSIGNED DEFAULT 0,
                retries TINYINT UNSIGNED DEFAULT 0,
                error_message TEXT,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                lock_key VARCHAR(32) DEFAULT NULL,
                lock_expiry TIMESTAMP NULL,
                metadata JSON,

                PRIMARY KEY (service_id, timestamp),
                INDEX idx_status_queue (status, queue_position),
                INDEX idx_status_timestamp (status, timestamp),
                INDEX idx_user_status (user_id, status),
                INDEX idx_cleanup (status, timestamp),
                INDEX idx_last_updated (last_updated),
                INDEX idx_processing (status, processing_time),
                INDEX idx_retries (status, retries),
                INDEX idx_user_status_timestamp (user_id, status, timestamp),
                INDEX idx_status_progress (status, progress),
                INDEX idx_locks (lock_key, lock_expiry)
            ) $charset_collate
            PARTITION BY RANGE (UNIX_TIMESTAMP(timestamp)) (
                PARTITION p0 VALUES LESS THAN (UNIX_TIMESTAMP('2025-01-01 00:00:00')),
                PARTITION p1 VALUES LESS THAN (UNIX_TIMESTAMP('2025-04-01 00:00:00')),
                PARTITION p2 VALUES LESS THAN (UNIX_TIMESTAMP('2025-07-01 00:00:00')),
                PARTITION p3 VALUES LESS THAN (UNIX_TIMESTAMP('2025-10-01 00:00:00')),
                PARTITION p_future VALUES LESS THAN MAXVALUE
            )";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            // Verify table creation
            $table_exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)
            );

            if (!$table_exists) {
                throw new Exception('Failed to create service_requests table');
            }
        } catch (Exception $e) {
            $this->error_handler->logError('Table creation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function acquireLock($service_id)
    {
        global $wpdb;

        $lock_key = wp_generate_password(32, false);
        $expiry = time() + $this->lock_timeout;

        $updated = $wpdb->update(
            $this->table_name,
            [
                'lock_key' => $lock_key,
                'lock_expiry' => date('Y-m-d H:i:s', $expiry)
            ],
            [
                'service_id' => $service_id,
                'lock_key' => null
            ]
        );

        return $updated ? $lock_key : false;
    }

    private function releaseLock($service_id, $lock_key)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            ['lock_key' => null, 'lock_expiry' => null],
            [
                'service_id' => $service_id,
                'lock_key' => $lock_key
            ]
        );
    }

    public function handleAddServiceRequest()
    {
        try {
            if (!$this->verifyRequest()) {
                return;
            }

            if (!$this->rate_limiter->checkLimit(get_current_user_id())) {
                throw new Exception(__('Rate limit exceeded', 'service-queue'));
            }

            global $wpdb;
            $current_user_id = get_current_user_id();
            $is_premium = $this->isPremiumUser($current_user_id);

            $wpdb->query('START TRANSACTION');

            // Check user limit with row lock
            $pending_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name}
                WHERE user_id = %d
                AND status IN ('pending', 'in_progress')
                FOR UPDATE",
                $current_user_id
            ));

            if ($pending_count >= SERVICE_QUEUE_MAX_USER_REQUESTS) {
                throw new Exception(__('Maximum concurrent requests limit reached', 'service-queue'));
            }

            // Calculate initial status and position
            $status = $is_premium ? 'in_progress' : 'pending';
            $queue_position = 0;

            if ($status === 'pending') {
                $queue_position = $wpdb->get_var(
                    "SELECT COALESCE(MAX(queue_position), 0) + 1
                    FROM {$this->table_name}
                    WHERE status = 'pending'"
                );
            }

            // Insert new service request
            $result = $wpdb->insert(
                $this->table_name,
                [
                    'user_id' => $current_user_id,
                    'status' => $status,
                    'processing_time' => wp_rand(10, 30),
                    'queue_position' => $queue_position,
                    'metadata' => json_encode([
                        'is_premium' => $is_premium,
                        'created_at' => current_time('mysql'),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ])
                ]
            );

            if ($result === false) {
                throw new Exception($wpdb->last_error);
            }

            $service_id = $wpdb->insert_id;

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

            $wpdb->query('COMMIT');

            wp_send_json_success([
                'message' => __('Service request created successfully', 'service-queue'),
                'service_id' => $service_id,
                'is_premium' => $is_premium
            ]);
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->error_handler->logError($e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function handleGetServiceRequests()
    {
        try {
            if (!$this->verifyRequest()) {
                return;
            }

            global $wpdb;
            $current_user_id = get_current_user_id();
            $is_premium = $this->isPremiumUser($current_user_id);

            // Generate cache key including user-specific factors
            $cache_key = "service_requests_{$current_user_id}_" . md5($_SERVER['HTTP_USER_AGENT'] . $is_premium);

            $results = wp_cache_get($cache_key, $this->cache_group);

            if (false === $results) {
                $results = $wpdb->get_results($wpdb->prepare(
                    "SELECT
                    service_id,
                    status,
                    progress,
                    queue_position,
                    timestamp,
                    processing_time,
                    retries,
                    error_message,
                    last_updated,
                    metadata
                FROM {$this->table_name}
                WHERE user_id = %d
                AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY FIELD(status, 'in_progress', 'pending', 'completed', 'error'), timestamp DESC
                LIMIT %d",
                    $current_user_id,
                    100
                ));

                foreach ($results as $result) {
                    $metadata = json_decode($result->metadata, true);
                    $result->is_premium = $metadata['is_premium'] ?? false;
                    unset($result->metadata); // Don't send internal metadata to client
                }

                wp_cache_set($cache_key, $results, $this->cache_group, 30);
            }

            wp_send_json_success($results);
        } catch (Exception $e) {
            $this->error_handler->logError($e->getMessage());
            wp_send_json_error(['message' => __('Failed to fetch services', 'service-queue')]);
        }
    }

    public function processNextBatch()
    {
        global $wpdb;

        try {
            // Get exclusive lock for batch processing
            $lock_key = "service_queue_batch_" . wp_generate_password(12, false);
            $lock_result = $wpdb->get_row($wpdb->prepare(
                "SELECT GET_LOCK(%s, 10) as locked",
                $lock_key
            ));

            if (!$lock_result || !$lock_result->locked) {
                return;
            }

            // Count current processing requests
            $current_processing = $wpdb->get_var(
                "SELECT COUNT(*)
            FROM {$this->table_name}
            WHERE status = 'in_progress'"
            );

            $slots_available = SERVICE_QUEUE_MAX_GLOBAL_PROCESSING - $current_processing;

            if ($slots_available <= 0) {
                return;
            }

            $batch_limit = min(SERVICE_QUEUE_BATCH_SIZE, $slots_available);

            $wpdb->query('START TRANSACTION');

            // Get batch of pending requests with priority for premium users
            $batch = $wpdb->get_results($wpdb->prepare(
                "SELECT s.service_id, s.metadata
            FROM {$this->table_name} s
            WHERE s.status = 'pending'
            AND s.lock_key IS NULL
            ORDER BY
                JSON_EXTRACT(s.metadata, '$.is_premium') DESC,
                s.queue_position ASC
            LIMIT %d
            FOR UPDATE",
                $batch_limit
            ));

            if (empty($batch)) {
                $wpdb->query('COMMIT');
                return;
            }

            foreach ($batch as $service) {
                $lock_key = $this->acquireLock($service->service_id);
                if ($lock_key) {
                    $wpdb->update(
                        $this->table_name,
                        [
                            'status' => 'in_progress',
                            'queue_position' => 0,
                            'last_updated' => current_time('mysql')
                        ],
                        ['service_id' => $service->service_id]
                    );

                    as_schedule_single_action(
                        time(),
                        'process_service_step',
                        [
                            'service_id' => $service->service_id,
                            'step' => 1,
                            'total_steps' => 20,
                            'lock_key' => $lock_key
                        ],
                        'service-queue'
                    );
                }
            }

            $wpdb->query('COMMIT');

            // Update queue positions in chunks
            $this->updateQueuePositionsInChunks();

            // Clear relevant caches
            $this->clearServiceCaches();
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->error_handler->logError('Batch processing error: ' . $e->getMessage());
        } finally {
            if (isset($lock_key)) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));
            }
        }
    }

    public function processServiceStep($service_id, $step, $total_steps, $lock_key)
    {
        global $wpdb;

        try {
            // Verify lock
            $service = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name}
            WHERE service_id = %d AND lock_key = %s",
                $service_id,
                $lock_key
            ));

            if (!$service) {
                throw new Exception('Invalid lock or service not found');
            }

            $progress = ($step / $total_steps) * 100;
            $status = ($step === $total_steps) ? 'completed' : 'in_progress';

            $result = $wpdb->update(
                $this->table_name,
                [
                    'status' => $status,
                    'progress' => $progress,
                    'last_updated' => current_time('mysql')
                ],
                [
                    'service_id' => $service_id,
                    'lock_key' => $lock_key
                ]
            );

            if ($result === false) {
                throw new Exception('Failed to update service progress');
            }

            if ($step < $total_steps) {
                as_schedule_single_action(
                    time() + ceil($service->processing_time / $total_steps),
                    'process_service_step',
                    [
                        'service_id' => $service_id,
                        'step' => $step + 1,
                        'total_steps' => $total_steps,
                        'lock_key' => $lock_key
                    ],
                    'service-queue'
                );
            } else {
                $this->releaseLock($service_id, $lock_key);
                $this->clearServiceCaches($service->user_id);
            }
        } catch (Exception $e) {
            $this->handleProcessingError($service_id, $lock_key, $e);
        }
    }

    private function handleProcessingError($service_id, $lock_key, Exception $e)
    {
        global $wpdb;

        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT retries FROM {$this->table_name} WHERE service_id = %d",
            $service_id
        ));

        if (!$service) {
            return;
        }

        $retries = $service->retries + 1;

        if ($retries <= SERVICE_QUEUE_MAX_RETRIES) {
            $wpdb->update(
                $this->table_name,
                ['retries' => $retries],
                ['service_id' => $service_id]
            );

            as_schedule_single_action(
                time() + 30,
                'process_service_step',
                [
                    'service_id' => $service_id,
                    'step' => 1,
                    'total_steps' => 20,
                    'lock_key' => $lock_key
                ],
                'service-queue'
            );
        } else {
            $wpdb->update(
                $this->table_name,
                [
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'last_updated' => current_time('mysql'),
                    'lock_key' => null
                ],
                ['service_id' => $service_id]
            );

            $this->clearServiceCaches();
        }

        $this->error_handler->logError(
            sprintf('Service %d processing error: %s', $service_id, $e->getMessage())
        );
    }

    public function cleanupOldRequests()
    {
        global $wpdb;

        try {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table_name}
            WHERE status IN ('completed', 'error')
            AND timestamp < DATE_SUB(NOW(), INTERVAL %d SECOND)",
                SERVICE_QUEUE_CLEANUP_INTERVAL
            ));

            $this->clearServiceCaches();
        } catch (Exception $e) {
            $this->error_handler->logError('Cleanup error: ' . $e->getMessage());
        }
    }

    public function cleanupStuckServices()
    {
        global $wpdb;

        try {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table_name}
            SET
                status = 'error',
                error_message = %s,
                lock_key = NULL,
                lock_expiry = NULL
            WHERE status = 'in_progress'
            AND last_updated < DATE_SUB(NOW(), INTERVAL %d SECOND)",
                __('Service processing timeout', 'service-queue'),
                SERVICE_QUEUE_STUCK_TIMEOUT
            ));

            $this->clearServiceCaches();
        } catch (Exception $e) {
            $this->error_handler->logError('Stuck services cleanup error: ' . $e->getMessage());
        }
    }

    private function updateQueuePositionsInChunks()
    {
        global $wpdb;

        try {
            $offset = 0;
            while (true) {
                $affected = $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table_name}
                SET queue_position =
                    (
                        SELECT position
                        FROM (
                            SELECT
                                service_id,
                                @row_number:=@row_number + 1 AS position
                            FROM {$this->table_name},
                                (SELECT @row_number:=0) AS r
                            WHERE status = 'pending'
                            ORDER BY
                                JSON_EXTRACT(metadata, '$.is_premium') DESC,
                                timestamp ASC
                        ) AS positions
                        WHERE positions.service_id = {$this->table_name}.service_id
                    )
                WHERE status = 'pending'
                LIMIT %d",
                    SERVICE_QUEUE_CHUNK_SIZE
                ));

                if ($affected < SERVICE_QUEUE_CHUNK_SIZE) {
                    break;
                }
            }
        } catch (Exception $e) {
            $this->error_handler->logError('Queue position update error: ' . $e->getMessage());
        }
    }

    private function clearServiceCaches($user_id = null)
    {
        $keys = [];
        if ($user_id) {
            $keys[] = "service_requests_{$user_id}_*";
        } else {
            // Add all known cache keys that need to be cleared
            $keys[] = 'service_requests_*';
        }

        foreach ($keys as $key) {
            wp_cache_delete($key, $this->cache_group);
        }
    }

    private function verifyRequest()
    {
        if (!is_user_logged_in()) {
            wp_send_json_error([
                'message' => __('You must be logged in to perform this action.', 'service-queue'),
                'code' => 401
            ]);
            return false;
        }

        if (!check_ajax_referer('service_queue_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'service-queue'),
                'code' => 403
            ]);
            return false;
        }

        return true;
    }

    private function isPremiumUser($user_id)
    {
        static $premium_status = [];

        if (!isset($premium_status[$user_id])) {
            $user = get_user_by('id', $user_id);
            $premium_status[$user_id] = user_can($user, 'premium_member') ||
                user_can($user, 'administrator');
        }

        return $premium_status[$user_id];
    }

    public function renderQueue()
    {
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

    private function initializePartitions()
    {
        global $wpdb;

        try {
            // Get existing partitions
            $partitions = $wpdb->get_results("
            SELECT PARTITION_NAME, PARTITION_DESCRIPTION
            FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$this->table_name}'
        ");

            // Add new partition for next quarter if it doesn't exist
            $next_quarter = date('Y-m-d', strtotime('+3 months'));
            $next_quarter_name = 'p_' . date('Y_q', strtotime($next_quarter));

            $partition_exists = false;
            foreach ($partitions as $partition) {
                if ($partition->PARTITION_NAME === $next_quarter_name) {
                    $partition_exists = true;
                    break;
                }
            }

            if (!$partition_exists) {
                $sql = "ALTER TABLE {$this->table_name} ADD PARTITION (
                PARTITION {$next_quarter_name} VALUES LESS THAN (TO_DAYS('{$next_quarter}'))
            )";
                $wpdb->query($sql);
            }
        } catch (Exception $e) {
            $this->error_handler->logError('Partition initialization error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function managePartitions()
    {
        global $wpdb;

        try {
            // Check if partition exists before trying to create it
            $existing_partition = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = %s
            AND PARTITION_NAME = %s",
                $this->table_name,
                'p_' . date('Y_q', strtotime('+3 months'))
            ));

            if ($existing_partition) {
                return; // Skip if partition already exists
            }

            // First check if p_future partition exists before trying to drop it
            $partition_exists = $wpdb->get_row($wpdb->prepare(
                "SELECT PARTITION_NAME
            FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = %s
            AND PARTITION_NAME = 'p_future'",
                $this->table_name
            ));

            if ($partition_exists) {
                $wpdb->query("ALTER TABLE {$this->table_name} DROP PARTITION p_future");
            }

            // Get the highest existing partition value
            $max_partition_value = $wpdb->get_var(
                "SELECT MAX(PARTITION_DESCRIPTION)
            FROM INFORMATION_SCHEMA.PARTITIONS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$this->table_name}'
            AND PARTITION_NAME != 'p_future'"
            );

            // Calculate next quarter start ensuring it's after the highest existing partition
            $base_date = max(
                time(),
                $max_partition_value ? intval($max_partition_value) : 0
            );
            $next_quarter_start = date('Y-m-d H:i:s', strtotime('+3 months', $base_date));
            $next_quarter_timestamp = strtotime($next_quarter_start);
            $partition_name = 'p_' . date('Y_q', $next_quarter_timestamp);

            // Add new partitions ensuring strictly increasing values
            $sql = "ALTER TABLE {$this->table_name} ADD PARTITION (
            PARTITION {$partition_name} VALUES LESS THAN (UNIX_TIMESTAMP('{$next_quarter_start}')),
            PARTITION p_future VALUES LESS THAN MAXVALUE
        )";

            $wpdb->query($sql);
        } catch (Exception $e) {
            $this->error_handler->logError('Partition management error: ' . $e->getMessage());
            // Add more detailed error logging
            $this->error_handler->logError('Partition management details: ' . json_encode([
                'current_time' => current_time('mysql'),
                'next_quarter_start' => $next_quarter_start ?? null,
                'max_partition_value' => $max_partition_value ?? null,
                'new_partition_name' => $partition_name ?? null
            ]));
        }
    }

    public function __clone()
    {
        // Prevent cloning of singleton
    }

    public function __wakeup()
    {
        // Prevent unserializing of singleton
    }
}
