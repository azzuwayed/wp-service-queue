<?php

class ServiceQueueMetricsCollector
{
    private static $instance = null;
    private $table_name;
    private $cache_group = 'service_queue';
    private $error_handler;

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
        $this->error_handler = new ErrorHandler();
    }

    public function collectMetrics()
    {
        $cached_metrics = wp_cache_get('service_queue_metrics', $this->cache_group);
        if (false !== $cached_metrics) {
            return $cached_metrics;
        }

        try {
            $metrics = $this->gatherMetrics();
            wp_cache_set('service_queue_metrics', $metrics, $this->cache_group, 300);
            return $metrics;
        } catch (Exception $e) {
            $this->error_handler->logError('Metrics collection failed: ' . $e->getMessage());
            return $this->getDefaultMetrics();
        }
    }

    private function gatherMetrics()
    {
        global $wpdb;

        $results = $wpdb->get_results("
            SELECT
                COUNT(*) as total_services,
                AVG(processing_time) as avg_processing_time,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) / COUNT(*) * 100 as error_rate,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as queue_length,
                SUM(CASE WHEN JSON_EXTRACT(metadata, '$.is_premium') = true THEN 1 ELSE 0 END) as premium_count,
                MAX(queue_position) as max_queue_position,
                AVG(progress) as avg_progress,
                COUNT(DISTINCT user_id) as unique_users
            FROM {$this->table_name}
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");

        if (!$results) {
            return $this->getDefaultMetrics();
        }

        $metrics = (array)$results[0];

        // Add performance metrics
        $metrics['performance_score'] = $this->calculatePerformanceScore($metrics);

        // Add system health indicators
        $metrics['system_health'] = $this->checkSystemHealth();

        return $metrics;
    }

    private function calculatePerformanceScore($metrics)
    {
        $score = 100;

        // Reduce score based on error rate
        $score -= $metrics['error_rate'];

        // Reduce score based on queue length
        $score -= min(($metrics['queue_length'] / 100) * 10, 20);

        // Reduce score based on processing time
        if ($metrics['avg_processing_time'] > 30) {
            $score -= min(($metrics['avg_processing_time'] - 30) / 2, 20);
        }

        return max(0, $score);
    }

    private function checkSystemHealth()
    {
        global $wpdb;

        $health = [
            'database' => true,
            'cache' => true,
            'queue' => true
        ];

        // Check database connectivity
        if ($wpdb->last_error) {
            $health['database'] = false;
        }

        // Check cache connectivity
        $test_key = 'health_check_' . wp_generate_password(6, false);
        if (!wp_cache_set($test_key, true, $this->cache_group, 30)) {
            $health['cache'] = false;
        }

        // Check queue health
        if (ServiceQueueDatabaseManager::getInstance()->hasStuckServices()) {
            $health['queue'] = false;
        }

        return $health;
    }

    private function getDefaultMetrics()
    {
        return [
            'total_services' => 0,
            'avg_processing_time' => 0,
            'error_rate' => 0,
            'queue_length' => 0,
            'premium_count' => 0,
            'max_queue_position' => 0,
            'avg_progress' => 0,
            'unique_users' => 0,
            'performance_score' => 0,
            'system_health' => [
                'database' => false,
                'cache' => false,
                'queue' => false
            ]
        ];
    }
}
