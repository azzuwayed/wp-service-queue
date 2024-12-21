<?php

class ErrorHandler
{
    private $log_file;
    private $error_option = 'service_queue_errors';
    private $max_stored_errors = 1000;
    private $rotation_size = 5242880; // 5MB

    public function __construct()
    {
        $this->log_file = WP_CONTENT_DIR . '/service-queue-errors.log';
        $this->initializeErrorLog();
    }

    private function initializeErrorLog()
    {
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
            chmod($this->log_file, 0644);
        }

        // Rotate log if needed
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->rotation_size) {
            $this->rotateLog();
        }
    }

    public function logError($message, $context = [], $severity = 'error')
    {
        $timestamp = current_time('mysql');
        $user_id = get_current_user_id();

        $error_data = [
            'timestamp' => $timestamp,
            'message' => $message,
            'severity' => $severity,
            'user_id' => $user_id,
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $this->getClientIP(),
            'context' => $context
        ];

        // Log to file
        $log_message = sprintf(
            "[%s] %s: %s | User: %d | IP: %s | Context: %s\n",
            $timestamp,
            strtoupper($severity),
            $message,
            $user_id,
            $error_data['ip'],
            json_encode($context)
        );

        file_put_contents($this->log_file, $log_message, FILE_APPEND);

        // Store in database for admin viewing
        $this->storeError($error_data);

        // Notify admins if critical
        if ($severity === 'critical') {
            $this->notifyAdmins($error_data);
        }

        // Hook for external error tracking
        do_action('service_queue_error_logged', $error_data);
    }

    private function storeError($error_data)
    {
        $errors = get_option($this->error_option, []);

        array_unshift($errors, $error_data);

        // Limit stored errors
        if (count($errors) > $this->max_stored_errors) {
            array_pop($errors);
        }

        update_option($this->error_option, $errors);
    }

    private function notifyAdmins($error_data)
    {
        $admin_email = get_option('admin_email');
        $subject = sprintf(
            '[%s] Critical Error in Service Queue',
            get_bloginfo('name')
        );

        $message = sprintf(
            "A critical error occurred in the Service Queue plugin:\n\n" .
                "Time: %s\n" .
                "Message: %s\n" .
                "User ID: %d\n" .
                "IP: %s\n" .
                "URL: %s\n\n" .
                "Context:\n%s",
            $error_data['timestamp'],
            $error_data['message'],
            $error_data['user_id'],
            $error_data['ip'],
            $error_data['url'],
            json_encode($error_data['context'], JSON_PRETTY_PRINT)
        );

        wp_mail($admin_email, $subject, $message);
    }

    private function rotateLog()
    {
        $backup_name = $this->log_file . '.' . date('Y-m-d-H-i-s');
        rename($this->log_file, $backup_name);
        touch($this->log_file);
        chmod($this->log_file, 0644);

        // Keep only last 5 backups
        $backups = glob($this->log_file . '.*');
        if (count($backups) > 5) {
            array_map('unlink', array_slice($backups, 0, -5));
        }
    }

    public function getErrors($limit = 50, $offset = 0, $severity = null)
    {
        $errors = get_option($this->error_option, []);

        if ($severity) {
            $errors = array_filter($errors, function ($error) use ($severity) {
                return $error['severity'] === $severity;
            });
        }

        return array_slice($errors, $offset, $limit);
    }

    public function clearErrors()
    {
        delete_option($this->error_option);

        if (file_exists($this->log_file)) {
            unlink($this->log_file);
        }

        $this->initializeErrorLog();
    }

    private function getClientIP()
    {
        $ip_headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }

        return 'Unknown';
    }
}
