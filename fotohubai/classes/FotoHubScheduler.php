<?php
/**
 * FOTOhub Scheduler for PrestaShop
 *
 * Cron-based job scheduling for batch processing products overnight.
 * Manages a persistent queue of AI operations (generate, remove_background,
 * upscale, generate_video, copywrite, pipeline) and processes them in
 * configurable batch sizes via PrestaShop's cron system.
 *
 * All methods are static — the class operates directly against the database.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FotoHubScheduler
{
    /** @var string Resolved table name (with DB prefix) */
    private static string $tableName = '';

    /** @var array Allowed actions for scheduled jobs */
    private static array $allowedActions = [
        'generate',
        'remove_background',
        'upscale',
        'generate_video',
        'copywrite',
        'pipeline',
    ];

    /**
     * Get the fully-qualified table name
     *
     * @return string Table name with database prefix
     */
    private static function getTableName(): string
    {
        if (empty(self::$tableName)) {
            self::$tableName = _DB_PREFIX_ . 'fotohub_schedule';
        }

        return self::$tableName;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Installation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create the schedule table
     *
     * Creates the `fotohub_schedule` table with all required columns and indexes.
     *
     * @return bool True on success
     */
    public static function install(): bool
    {
        $table = self::getTableName();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id_schedule` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `id_product` INT UNSIGNED NOT NULL,
            `action` VARCHAR(64) NOT NULL,
            `options` TEXT NULL,
            `status` ENUM('pending', 'processing', 'completed', 'failed', 'retry') NOT NULL DEFAULT 'pending',
            `priority` INT NOT NULL DEFAULT 0,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
            `error_message` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at` DATETIME NULL,
            `completed_at` DATETIME NULL,
            `batch_id` VARCHAR(64) NULL,
            INDEX `idx_status_priority` (`status`, `priority`),
            INDEX `idx_batch_id` (`batch_id`)
        ) ENGINE=" . _MYSQL_ENGINE_ . " DEFAULT CHARSET=utf8mb4;";

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * Drop the schedule table
     *
     * @return bool True on success
     */
    public static function uninstall(): bool
    {
        $table = self::getTableName();

        return (bool) Db::getInstance()->execute("DROP TABLE IF EXISTS `{$table}`");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Queue Management
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Add a single job to the schedule queue
     *
     * @param int $idProduct Product ID to process
     * @param string $action Action to perform (generate, remove_background, upscale, generate_video, copywrite, pipeline)
     * @param array $options JSON-serializable options for the action
     * @param int $priority Priority level (higher = processed first)
     * @param string|null $batchId Optional batch identifier to group jobs
     * @return int The ID of the newly created schedule entry
     * @throws PrestaShopException If action is invalid or insert fails
     */
    public static function enqueue(int $idProduct, string $action, array $options = [], int $priority = 0, ?string $batchId = null): int
    {
        if (!in_array($action, self::$allowedActions, true)) {
            throw new PrestaShopException(
                'FOTOhub Scheduler: Invalid action "' . pSQL($action) . '". Allowed: ' . implode(', ', self::$allowedActions)
            );
        }

        $db = Db::getInstance();
        $table = self::getTableName();

        $data = [
            'id_product' => (int) $idProduct,
            'action' => pSQL($action),
            'options' => pSQL(json_encode($options)),
            'priority' => (int) $priority,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        if ($batchId !== null) {
            $data['batch_id'] = pSQL($batchId);
        }

        $success = $db->insert(
            'fotohub_schedule',
            $data
        );

        if (!$success) {
            throw new PrestaShopException('FOTOhub Scheduler: Failed to enqueue job');
        }

        return (int) $db->Insert_ID();
    }

    /**
     * Enqueue multiple products as a batch
     *
     * Creates a unique batch_id and enqueues all given product IDs with the same
     * action and options under that batch.
     *
     * @param array $productIds Array of product IDs
     * @param string $action Action to perform
     * @param array $options JSON-serializable options for the action
     * @param int $priority Priority level (higher = processed first)
     * @return string The generated batch_id
     * @throws PrestaShopException
     */
    public static function enqueueBatch(array $productIds, string $action, array $options = [], int $priority = 0): string
    {
        $batchId = uniqid('batch_', true);

        foreach ($productIds as $idProduct) {
            self::enqueue((int) $idProduct, $action, $options, $priority, $batchId);
        }

        return $batchId;
    }

    /**
     * Get next pending jobs from the queue
     *
     * Returns jobs ordered by priority (descending) then creation date (ascending).
     *
     * @param int $limit Maximum number of jobs to retrieve
     * @return array Array of job rows
     */
    public static function getNextPending(int $limit = 10): array
    {
        $table = self::getTableName();
        $limit = max(1, (int) $limit);

        $sql = "SELECT * FROM `{$table}`
                WHERE `status` = 'pending'
                ORDER BY `priority` DESC, `created_at` ASC
                LIMIT {$limit}";

        $results = Db::getInstance()->executeS($sql);

        return is_array($results) ? $results : [];
    }

    /**
     * Mark a job as currently processing
     *
     * Sets status to 'processing', records start time, increments attempt counter.
     *
     * @param int $idSchedule Schedule entry ID
     * @return bool True on success
     */
    public static function markProcessing(int $idSchedule): bool
    {
        $table = self::getTableName();

        $sql = "UPDATE `{$table}` SET
                    `status` = 'processing',
                    `started_at` = NOW(),
                    `attempts` = `attempts` + 1
                WHERE `id_schedule` = " . (int) $idSchedule;

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * Mark a job as completed successfully
     *
     * @param int $idSchedule Schedule entry ID
     * @return bool True on success
     */
    public static function markCompleted(int $idSchedule): bool
    {
        $table = self::getTableName();

        $sql = "UPDATE `{$table}` SET
                    `status` = 'completed',
                    `completed_at` = NOW()
                WHERE `id_schedule` = " . (int) $idSchedule;

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * Mark a job as failed
     *
     * If the job has not yet reached max_attempts, it will be set to 'retry'
     * status instead of 'failed', allowing automatic retry on next cron run.
     *
     * @param int $idSchedule Schedule entry ID
     * @param string $errorMessage Reason for failure
     * @return bool True on success
     */
    public static function markFailed(int $idSchedule, string $errorMessage): bool
    {
        $table = self::getTableName();
        $db = Db::getInstance();

        // Fetch current attempts and max_attempts
        $row = $db->getRow(
            "SELECT `attempts`, `max_attempts` FROM `{$table}` WHERE `id_schedule` = " . (int) $idSchedule
        );

        if (!$row) {
            return false;
        }

        $status = ((int) $row['attempts'] < (int) $row['max_attempts']) ? 'retry' : 'failed';

        $sql = "UPDATE `{$table}` SET
                    `status` = '" . pSQL($status) . "',
                    `error_message` = '" . pSQL($errorMessage) . "'
                WHERE `id_schedule` = " . (int) $idSchedule;

        return (bool) $db->execute($sql);
    }

    /**
     * Reset all retry jobs back to pending
     *
     * @return int Number of jobs reset to pending
     */
    public static function retryFailed(): int
    {
        $table = self::getTableName();
        $db = Db::getInstance();

        $sql = "UPDATE `{$table}` SET
                    `status` = 'pending',
                    `error_message` = NULL
                WHERE `status` = 'retry'";

        $db->execute($sql);

        return (int) $db->Affected_Rows();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Processing (Cron Handler)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Process the next batch of scheduled jobs
     *
     * This is the main method called by PrestaShop's cron system. It:
     * 1. Retries any items in 'retry' status
     * 2. Fetches the next $batchSize pending items
     * 3. Executes each one using FotoHubApiClient + FotoHubBulkProcessor
     * 4. Records success/failure and logs via PrestaShopLogger
     *
     * @param int $batchSize Number of jobs to process per cron run
     * @return array Summary with keys: processed, success, failed
     */
    public static function processCron(int $batchSize = 5): array
    {
        $summary = ['processed' => 0, 'success' => 0, 'failed' => 0];

        // Retry any previously-failed items that are eligible
        self::retryFailed();

        // Fetch next batch
        $jobs = self::getNextPending($batchSize);

        if (empty($jobs)) {
            return $summary;
        }

        // Instantiate API client
        $module = Module::getInstanceByName('fotohubai');

        if (!$module || !is_callable([$module, 'getDecryptedApiKey'])) {
            PrestaShopLogger::addLog(
                'FOTOhub Scheduler: Module not available or API key method missing',
                3
            );
            return $summary;
        }

        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            PrestaShopLogger::addLog(
                'FOTOhub Scheduler: API key is not configured',
                3
            );
            return $summary;
        }

        $client = new FotoHubApiClient($apiKey);
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $processor = new FotoHubBulkProcessor($client, $idLang);

        foreach ($jobs as $job) {
            $idSchedule = (int) $job['id_schedule'];
            $summary['processed']++;

            self::markProcessing($idSchedule);

            try {
                $options = !empty($job['options']) ? json_decode($job['options'], true) : [];

                if (!is_array($options)) {
                    $options = [];
                }

                $results = $processor->processBatch(
                    [(int) $job['id_product']],
                    $job['action'],
                    $options
                );

                $result = $results[0] ?? null;

                if ($result && $result['status'] === 'success') {
                    self::markCompleted($idSchedule);
                    $summary['success']++;

                    PrestaShopLogger::addLog(
                        'FOTOhub Scheduler: Completed job #' . $idSchedule . ' (' . $job['action'] . ') for product #' . $job['id_product'],
                        1,
                        null,
                        'Product',
                        (int) $job['id_product']
                    );
                } else {
                    $errorMsg = $result['message'] ?? 'Unknown processing error';
                    self::markFailed($idSchedule, $errorMsg);
                    $summary['failed']++;

                    PrestaShopLogger::addLog(
                        'FOTOhub Scheduler: Failed job #' . $idSchedule . ': ' . $errorMsg,
                        3,
                        null,
                        'Product',
                        (int) $job['id_product']
                    );
                }
            } catch (Exception $e) {
                self::markFailed($idSchedule, $e->getMessage());
                $summary['failed']++;

                PrestaShopLogger::addLog(
                    'FOTOhub Scheduler: Exception in job #' . $idSchedule . ': ' . $e->getMessage(),
                    3,
                    null,
                    'Product',
                    (int) $job['id_product']
                );
            }
        }

        return $summary;
    }

    /**
     * Process a single scheduled job by its ID
     *
     * @param int $idSchedule Schedule entry ID
     * @return bool True if the job completed successfully
     */
    public static function processOne(int $idSchedule): bool
    {
        $table = self::getTableName();
        $db = Db::getInstance();

        $job = $db->getRow(
            "SELECT * FROM `{$table}` WHERE `id_schedule` = " . (int) $idSchedule
        );

        if (!$job) {
            return false;
        }

        if ($job['status'] !== 'pending' && $job['status'] !== 'retry') {
            return false;
        }

        self::markProcessing($idSchedule);

        try {
            $module = Module::getInstanceByName('fotohubai');

            if (!$module || !is_callable([$module, 'getDecryptedApiKey'])) {
                self::markFailed($idSchedule, 'Module not available');
                return false;
            }

            $apiKey = $module->getDecryptedApiKey();

            if (empty($apiKey)) {
                self::markFailed($idSchedule, 'API key not configured');
                return false;
            }

            $client = new FotoHubApiClient($apiKey);
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
            $processor = new FotoHubBulkProcessor($client, $idLang);

            $options = !empty($job['options']) ? json_decode($job['options'], true) : [];

            if (!is_array($options)) {
                $options = [];
            }

            $results = $processor->processBatch(
                [(int) $job['id_product']],
                $job['action'],
                $options
            );

            $result = $results[0] ?? null;

            if ($result && $result['status'] === 'success') {
                self::markCompleted($idSchedule);

                PrestaShopLogger::addLog(
                    'FOTOhub Scheduler: Completed job #' . $idSchedule . ' (' . $job['action'] . ') for product #' . $job['id_product'],
                    1,
                    null,
                    'Product',
                    (int) $job['id_product']
                );

                return true;
            }

            $errorMsg = $result['message'] ?? 'Unknown processing error';
            self::markFailed($idSchedule, $errorMsg);
            return false;
        } catch (Exception $e) {
            self::markFailed($idSchedule, $e->getMessage());

            PrestaShopLogger::addLog(
                'FOTOhub Scheduler: Exception in job #' . $idSchedule . ': ' . $e->getMessage(),
                3,
                null,
                'Product',
                (int) $job['id_product']
            );

            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Status & Reporting
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get the status breakdown for a specific batch
     *
     * @param string $batchId Batch identifier
     * @return array Associative array with keys: total, pending, processing, completed, failed
     */
    public static function getBatchStatus(string $batchId): array
    {
        $table = self::getTableName();
        $db = Db::getInstance();

        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN `status` = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN `status` = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN `status` IN ('failed', 'retry') THEN 1 ELSE 0 END) as failed
                FROM `{$table}`
                WHERE `batch_id` = '" . pSQL($batchId) . "'";

        $row = $db->getRow($sql);

        if (!$row) {
            return ['total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        }

        return [
            'total' => (int) $row['total'],
            'pending' => (int) $row['pending'],
            'processing' => (int) $row['processing'],
            'completed' => (int) $row['completed'],
            'failed' => (int) $row['failed'],
        ];
    }

    /**
     * Get overall queue statistics
     *
     * @return array Stats with keys: total_pending, processing, completed_today, failed_today
     */
    public static function getQueueStats(): array
    {
        $table = self::getTableName();
        $db = Db::getInstance();
        $today = date('Y-m-d');

        $sql = "SELECT
                    SUM(CASE WHEN `status` = 'pending' THEN 1 ELSE 0 END) as total_pending,
                    SUM(CASE WHEN `status` = 'processing' THEN 1 ELSE 0 END) as processing,
                    SUM(CASE WHEN `status` = 'completed' AND DATE(`completed_at`) = '{$today}' THEN 1 ELSE 0 END) as completed_today,
                    SUM(CASE WHEN `status` IN ('failed', 'retry') AND DATE(`created_at`) = '{$today}' THEN 1 ELSE 0 END) as failed_today
                FROM `{$table}`";

        $row = $db->getRow($sql);

        if (!$row) {
            return ['total_pending' => 0, 'processing' => 0, 'completed_today' => 0, 'failed_today' => 0];
        }

        return [
            'total_pending' => (int) $row['total_pending'],
            'processing' => (int) $row['processing'],
            'completed_today' => (int) $row['completed_today'],
            'failed_today' => (int) $row['failed_today'],
        ];
    }

    /**
     * Get count of pending jobs
     *
     * @return int Number of pending jobs in the queue
     */
    public static function getPendingCount(): int
    {
        $table = self::getTableName();

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `status` = 'pending'";

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Delete completed jobs older than a specified number of days
     *
     * @param int $olderThanDays Number of days after which completed jobs are purged
     * @return int Number of rows deleted
     */
    public static function clearCompleted(int $olderThanDays = 7): int
    {
        $table = self::getTableName();
        $db = Db::getInstance();
        $days = max(1, (int) $olderThanDays);

        $sql = "DELETE FROM `{$table}`
                WHERE `status` = 'completed'
                AND `completed_at` < DATE_SUB(NOW(), INTERVAL {$days} DAY)";

        $db->execute($sql);

        return (int) $db->Affected_Rows();
    }

    /**
     * Cancel all pending jobs in a batch
     *
     * Sets all pending items in the specified batch to 'failed' with a cancellation message.
     *
     * @param string $batchId Batch identifier
     * @return int Number of jobs cancelled
     */
    public static function cancelBatch(string $batchId): int
    {
        $table = self::getTableName();
        $db = Db::getInstance();

        $sql = "UPDATE `{$table}` SET
                    `status` = 'failed',
                    `error_message` = 'Cancelled by user'
                WHERE `batch_id` = '" . pSQL($batchId) . "'
                AND `status` = 'pending'";

        $db->execute($sql);

        return (int) $db->Affected_Rows();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cron Registration Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Register the scheduler as a cron task in PrestaShop's cronjobs module
     *
     * Adds an hourly cron entry so the scheduler processes jobs automatically.
     * Requires the official PrestaShop cronjobs module to be installed.
     *
     * @return bool True on success, false if cronjobs module not available
     */
    public static function registerCronTask(): bool
    {
        $cronTable = _DB_PREFIX_ . 'cronjobs';
        $db = Db::getInstance();

        // Check if cronjobs module table exists
        $tableExists = $db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name = '" . pSQL($cronTable) . "'"
        );

        if (!(int) $tableExists) {
            return false;
        }

        $idModule = (int) Module::getModuleIdByName('fotohubai');

        if (!$idModule) {
            return false;
        }

        // Build the cron URL for the module
        $shopUrl = rtrim(
            Configuration::get('PS_SSL_ENABLED')
                ? Tools::getShopDomainSsl(true)
                : Tools::getShopDomain(true),
            '/'
        );
        $cronUrl = $shopUrl . '/module/fotohubai/cron';

        // Check if already registered
        $exists = $db->getValue(
            "SELECT COUNT(*) FROM `{$cronTable}`
             WHERE `id_module` = {$idModule}
             AND `task` = '" . pSQL($cronUrl) . "'"
        );

        if ((int) $exists > 0) {
            return true; // Already registered
        }

        return (bool) $db->insert('cronjobs', [
            'id_module' => $idModule,
            'description' => pSQL('FOTOhub AI - Process scheduled generations'),
            'task' => pSQL($cronUrl),
            'hour' => -1, // Every hour
            'day' => -1,
            'month' => -1,
            'day_of_week' => -1,
            'active' => 1,
            'id_shop' => (int) Context::getContext()->shop->id,
            'id_shop_group' => (int) Context::getContext()->shop->id_shop_group,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Unregister the cron task from PrestaShop's cronjobs module
     *
     * @return bool True on success
     */
    public static function unregisterCronTask(): bool
    {
        $cronTable = _DB_PREFIX_ . 'cronjobs';
        $db = Db::getInstance();

        // Check if cronjobs module table exists
        $tableExists = $db->getValue(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name = '" . pSQL($cronTable) . "'"
        );

        if (!(int) $tableExists) {
            return true; // Nothing to unregister
        }

        $idModule = (int) Module::getModuleIdByName('fotohubai');

        if (!$idModule) {
            return true;
        }

        $sql = "DELETE FROM `{$cronTable}` WHERE `id_module` = {$idModule}";

        return (bool) $db->execute($sql);
    }
}
