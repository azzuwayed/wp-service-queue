<?php
class ServiceQueueResourceManager
{
    private $redis = null;
    private static $instance = null;
    private $using_redis = false;
    private $load_level_grace_period = 300; // 5 minutes grace period

    const LOAD_LEVEL_LOW = 'low';
    const LOAD_LEVEL_MEDIUM = 'medium';
    const LOAD_LEVEL_HIGH = 'high';

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (class_exists('Redis') && defined('WP_REDIS_HOST') && defined('WP_REDIS_PORT')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect(WP_REDIS_HOST, WP_REDIS_PORT);
                $this->using_redis = true;
            } catch (Exception $e) {
                error_log('Redis connection failed: ' . $e->getMessage());
                $this->using_redis = false;
            }
        }
    }

    private function getCurrentLoadLevel()
    {
        $system_load = $this->getSystemLoad();
        $current_level = $this->getStoredLoadLevel();
        $last_change_time = $this->getLoadLevelLastChangeTime();

        // Calculate new level
        $new_level = $system_load > 0.7 ? self::LOAD_LEVEL_HIGH : ($system_load > 0.3 ? self::LOAD_LEVEL_MEDIUM :
            self::LOAD_LEVEL_LOW);

        // Check if grace period has passed
        if (
            $current_level && $last_change_time &&
            (time() - $last_change_time < $this->load_level_grace_period)
        ) {
            return $current_level; // Still within grace period
        }

        // If level changed, update storage
        if ($new_level !== $current_level) {
            $this->storeLoadLevel($new_level);
            $this->storeLoadLevelLastChangeTime(time());
        }

        return $new_level;
    }

    private function getResourceLimits($load_level)
    {
        switch ($load_level) {
            case self::LOAD_LEVEL_HIGH:
                return [
                    'global' => SERVICE_QUEUE_GLOBAL_HIGH_LOAD,
                    'premium' => SERVICE_QUEUE_PREMIUM_HIGH_LOAD,
                    'free' => SERVICE_QUEUE_FREE_HIGH_LOAD
                ];
            case self::LOAD_LEVEL_MEDIUM:
                return [
                    'global' => SERVICE_QUEUE_GLOBAL_MEDIUM_LOAD,
                    'premium' => SERVICE_QUEUE_PREMIUM_MEDIUM_LOAD,
                    'free' => SERVICE_QUEUE_FREE_MEDIUM_LOAD
                ];
            default: // LOAD_LEVEL_LOW
                return [
                    'global' => SERVICE_QUEUE_GLOBAL_LOW_LOAD,
                    'premium' => SERVICE_QUEUE_PREMIUM_LOW_LOAD,
                    'free' => SERVICE_QUEUE_FREE_LOW_LOAD
                ];
        }
    }

    private function getSystemLoad()
    {
        if (is_windows()) {
            // For Windows development environment, assume moderate load
            return 0.5; // 50% load
        }

        // For Unix-based systems
        $load = sys_getloadavg();
        return $load[0] / $this->getCPUCount();
    }

    private function getCPUCount()
    {
        if (is_windows()) {
            $cmd = "wmic cpu get NumberOfLogicalProcessors";
            @exec($cmd, $output);
            return isset($output[1]) ? (int)$output[1] : 1;
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo) {
            $count = substr_count($cpuinfo, 'processor');
            return $count > 0 ? $count : 1;
        }

        return 1; // fallback to single CPU
    }

    private function getCurrentQueueSize()
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}service_requests
             WHERE status IN ('pending', 'in_progress')"
        );
    }

    private function getUserRequestCount($user_id)
    {
        if ($this->using_redis) {
            $key = "user_requests:{$user_id}";
            $count = $this->redis->get($key);

            if ($count === false) {
                $count = $this->getRequestCountFromDB($user_id);
                $this->redis->set($key, $count, ['ex' => 60]);
            }
        } else {
            $count = $this->getRequestCountFromDB($user_id);
        }

        return (int) $count;
    }

    private function getRequestCountFromDB($user_id)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}service_requests
             WHERE user_id = %d AND status IN ('pending', 'in_progress')",
            $user_id
        ));
    }

    public function isPremiumUser($user_id)
    {
        if ($this->using_redis) {
            $key = "premium_user:{$user_id}";
            $is_premium = $this->redis->get($key);

            if ($is_premium === false) {
                $is_premium = $this->checkPremiumStatus($user_id);
                $this->redis->set($key, (int) $is_premium, ['ex' => 3600]);
            }
        } else {
            $is_premium = $this->checkPremiumStatus($user_id);
        }

        return (bool) $is_premium;
    }

    private function checkPremiumStatus($user_id)
    {
        $user = get_user_by('id', $user_id);
        return user_can($user, 'premium_member') || user_can($user, 'administrator');
    }

    public function getSystemStatus()
    {
        $load_level = $this->getCurrentLoadLevel();
        $system_load = $this->getSystemLoad();
        $limits = $this->getResourceLimits($load_level);
        $queue_size = $this->getCurrentQueueSize();

        return [
            'load_level' => $load_level,
            'system_load' => round($system_load * 100, 1),
            'current_limits' => $limits,
            'queue_size' => $queue_size,
            'last_change' => $this->getLoadLevelLastChangeTime()
        ];
    }

    public function canAcceptNewRequest($user_id)
    {
        $load_level = $this->getCurrentLoadLevel();
        $limits = $this->getResourceLimits($load_level);
        $is_premium = $this->isPremiumUser($user_id);

        // Check global limit
        if ($this->getCurrentQueueSize() >= $limits['global']) {
            return false;
        }

        // Get user's current requests
        $user_requests = $this->getUserRequestCount($user_id);
        $user_limit = $is_premium ? $limits['premium'] : $limits['free'];

        return $user_requests < $user_limit;
    }

    public function incrementUserRequests($user_id)
    {
        if ($this->using_redis) {
            $key = "user_requests:{$user_id}";
            $this->redis->incr($key);
        }
    }

    public function decrementUserRequests($user_id)
    {
        if ($this->using_redis) {
            $key = "user_requests:{$user_id}";
            $this->redis->decr($key);
        }
    }

    // Storage methods for load level
    private function getStoredLoadLevel()
    {
        if ($this->using_redis) {
            return $this->redis->get('service_queue_load_level');
        }
        return get_transient('service_queue_load_level');
    }

    private function storeLoadLevel($level)
    {
        if ($this->using_redis) {
            $this->redis->set('service_queue_load_level', $level);
        } else {
            set_transient('service_queue_load_level', $level, DAY_IN_SECONDS);
        }
    }

    private function getLoadLevelLastChangeTime()
    {
        if ($this->using_redis) {
            return $this->redis->get('service_queue_load_level_changed');
        }
        return get_transient('service_queue_load_level_changed');
    }

    private function storeLoadLevelLastChangeTime($time)
    {
        if ($this->using_redis) {
            $this->redis->set('service_queue_load_level_changed', $time);
        } else {
            set_transient('service_queue_load_level_changed', $time, DAY_IN_SECONDS);
        }
    }
}

function is_windows()
{
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}
