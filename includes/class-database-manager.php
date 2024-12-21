<?php

class ServiceQueueDatabaseManager
{
    private static $instance = null;
    private $current_version;
    private $table_name;
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
        $this->current_version = get_option('service_queue_db_version', '0');
        $this->error_handler = new ErrorHandler();
    }

    public function checkAndUpgrade()
    {
        if (version_compare($this->current_version, SERVICE_QUEUE_VERSION, '<')) {
            $this->performUpgrade();
        }
    }

    private function performUpgrade()
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            // Perform version-specific upgrades
            $upgrade_methods = $this->getUpgradeMethods();

            foreach ($upgrade_methods as $version => $method) {
                if (version_compare($this->current_version, $version, '<')) {
                    $this->$method();
                }
            }

            update_option('service_queue_db_version', SERVICE_QUEUE_VERSION);
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->error_handler->logError('Database upgrade failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getUpgradeMethods()
    {
        return [
            '2.0' => 'upgradeToV2',
            '2.5' => 'upgradeToV2_5',
            '3.0' => 'upgradeToV3'
        ];
    }

    private function upgradeToV3()
    {
        global $wpdb;

        // Add new columns
        $wpdb->query("ALTER TABLE {$this->table_name}
            ADD COLUMN IF NOT EXISTS metadata JSON AFTER error_message,
            ADD COLUMN IF NOT EXISTS lock_key VARCHAR(32) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS lock_expiry TIMESTAMP NULL,
            ADD INDEX idx_locks (lock_key, lock_expiry)");

        // Migrate existing data to JSON metadata
        $wpdb->query("
            UPDATE {$this->table_name}
            SET metadata = JSON_OBJECT(
                'created_at', timestamp,
                'is_premium', false,
                'version', '3.0'
            )
            WHERE metadata IS NULL
        ");
    }

    public function hasStuckServices()
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "
            SELECT COUNT(*)
            FROM {$this->table_name}
            WHERE status = 'in_progress'
            AND last_updated < DATE_SUB(NOW(), INTERVAL %d SECOND)",
            SERVICE_QUEUE_STUCK_TIMEOUT
        ));

        return $count > 0;
    }

    public function optimizeTables()
    {
        global $wpdb;

        try {
            // Analyze and optimize the table
            $wpdb->query("ANALYZE TABLE {$this->table_name}");
            $wpdb->query("OPTIMIZE TABLE {$this->table_name}");

            // Update table statistics
            $wpdb->query("ANALYZE TABLE {$this->table_name}");

            return true;
        } catch (Exception $e) {
            $this->error_handler->logError('Table optimization failed: ' . $e->getMessage());
            return false;
        }
    }
}
