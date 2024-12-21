<?php

class RateLimiter
{
    private $cache_group = 'service_queue_rate_limit';
    private $window_size; // seconds
    private $max_requests;
    private $cache_prefix = 'rate_limit_';

    public function __construct()
    {
        $this->window_size = defined('SERVICE_QUEUE_RATE_LIMIT_WINDOW')
            ? SERVICE_QUEUE_RATE_LIMIT_WINDOW
            : 300; // 5 minutes default

        $this->max_requests = defined('SERVICE_QUEUE_RATE_LIMIT_MAX_REQUESTS')
            ? SERVICE_QUEUE_RATE_LIMIT_MAX_REQUESTS
            : 100;

        wp_cache_add_global_groups([$this->cache_group]);
    }

    public function checkLimit($user_id, $increase = true)
    {
        if ($this->isExempt($user_id)) {
            return true;
        }

        $cache_key = $this->cache_prefix . $user_id;
        $current_window = $this->getCurrentWindow();

        $requests = wp_cache_get($cache_key, $this->cache_group);

        if (false === $requests) {
            $requests = [
                'window' => $current_window,
                'count' => 0
            ];
        }

        // Reset if we're in a new window
        if ($requests['window'] !== $current_window) {
            $requests = [
                'window' => $current_window,
                'count' => 0
            ];
        }

        if ($requests['count'] >= $this->max_requests) {
            $this->logExcess($user_id, $requests['count']);
            return false;
        }

        if ($increase) {
            $requests['count']++;
            wp_cache_set(
                $cache_key,
                $requests,
                $this->cache_group,
                $this->window_size
            );
        }

        return true;
    }

    public function getRemainingRequests($user_id)
    {
        if ($this->isExempt($user_id)) {
            return PHP_INT_MAX;
        }

        $cache_key = $this->cache_prefix . $user_id;
        $current_window = $this->getCurrentWindow();

        $requests = wp_cache_get($cache_key, $this->cache_group);

        if (false === $requests || $requests['window'] !== $current_window) {
            return $this->max_requests;
        }

        return max(0, $this->max_requests - $requests['count']);
    }

    public function resetLimit($user_id)
    {
        $cache_key = $this->cache_prefix . $user_id;
        wp_cache_delete($cache_key, $this->cache_group);
    }

    private function getCurrentWindow()
    {
        return floor(time() / $this->window_size);
    }

    private function isExempt($user_id)
    {
        static $exempt_status = [];

        if (!isset($exempt_status[$user_id])) {
            $user = get_user_by('id', $user_id);
            $exempt_status[$user_id] = user_can($user, 'administrator') ||
                user_can($user, 'manage_options');
        }

        return $exempt_status[$user_id];
    }

    private function logExcess($user_id, $request_count)
    {
        $user = get_user_by('id', $user_id);
        $username = $user ? $user->user_login : 'Unknown';

        error_log(sprintf(
            'Rate limit exceeded - User: %s (ID: %d), Requests: %d, Window: %s',
            $username,
            $user_id,
            $request_count,
            date('Y-m-d H:i:s')
        ));

        // Optional: Log to database or monitoring service
        do_action('service_queue_rate_limit_exceeded', [
            'user_id' => $user_id,
            'username' => $username,
            'request_count' => $request_count,
            'window_size' => $this->window_size,
            'max_requests' => $this->max_requests,
            'timestamp' => current_time('mysql')
        ]);
    }

    public function getStatus($user_id)
    {
        return [
            'remaining_requests' => $this->getRemainingRequests($user_id),
            'window_size' => $this->window_size,
            'max_requests' => $this->max_requests,
            'is_exempt' => $this->isExempt($user_id)
        ];
    }

    public function adjustLimits($window_size = null, $max_requests = null)
    {
        if ($window_size !== null) {
            $this->window_size = max(1, intval($window_size));
        }

        if ($max_requests !== null) {
            $this->max_requests = max(1, intval($max_requests));
        }
    }

    public function clearAllLimits()
    {
        $pattern = $this->cache_prefix . '*';
        wp_cache_delete_multiple($pattern, $this->cache_group);
    }
}
