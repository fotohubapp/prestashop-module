<?php
/**
 * FOTOhub Analytics for PrestaShop
 *
 * Tracks AI usage: generations, credit spend, model usage,
 * per-product stats, and exportable reports.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FotoHubAnalytics
{
    /**
     * Install the analytics table
     *
     * @return bool True on success
     */
    public static function install(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fotohub_analytics` (
            `id_analytics` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT UNSIGNED NOT NULL DEFAULT 0,
            `action` VARCHAR(64) NOT NULL,
            `model` VARCHAR(64) NOT NULL DEFAULT \'\',
            `credits_used` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `status` VARCHAR(20) NOT NULL DEFAULT \'success\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `metadata` TEXT NULL,
            PRIMARY KEY (`id_analytics`),
            INDEX `idx_product` (`id_product`),
            INDEX `idx_action` (`action`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Uninstall the analytics table
     *
     * @return bool True on success
     */
    public static function uninstall(): bool
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fotohub_analytics`';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Log an API call to the analytics table
     *
     * @param int $idProduct Product ID (0 if not product-specific)
     * @param string $action Action performed (e.g. generate, remove_background, upscale)
     * @param string $model AI model used
     * @param float $creditsUsed Credits consumed
     * @param string $status Result status: success, error, skipped
     * @param array $metadata Additional metadata to store as JSON
     * @return bool True on success
     */
    public static function logApiCall(
        int $idProduct,
        string $action,
        string $model,
        float $creditsUsed,
        string $status = 'success',
        array $metadata = []
    ): bool {
        return Db::getInstance()->insert('fotohub_analytics', [
            'id_product' => $idProduct,
            'action' => pSQL($action),
            'model' => pSQL($model),
            'credits_used' => (float) $creditsUsed,
            'status' => pSQL($status),
            'created_at' => date('Y-m-d H:i:s'),
            'metadata' => !empty($metadata) ? pSQL(json_encode($metadata)) : null,
        ]);
    }

    /**
     * Get total number of generations in a time period
     *
     * @param int $days Number of days to look back (default: 30)
     * @return int Total generation count
     */
    public static function getTotalGenerations(int $days = 30): int
    {
        $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)';

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * Get total credits used in a time period
     *
     * @param int $days Number of days to look back (default: 30)
     * @return float Total credits consumed
     */
    public static function getTotalCreditsUsed(int $days = 30): float
    {
        $sql = 'SELECT COALESCE(SUM(`credits_used`), 0) FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)';

        return (float) Db::getInstance()->getValue($sql);
    }

    /**
     * Get cost breakdown grouped by action
     *
     * @param int $days Number of days to look back (default: 30)
     * @return array Array of ['action' => string, 'count' => int, 'credits' => float]
     */
    public static function getCostBreakdown(int $days = 30): array
    {
        $sql = 'SELECT `action`, COUNT(*) AS `count`, SUM(`credits_used`) AS `credits`
                FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                GROUP BY `action`
                ORDER BY `credits` DESC';

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return [];
        }

        return array_map(function ($row) {
            return [
                'action' => $row['action'],
                'count' => (int) $row['count'],
                'credits' => (float) $row['credits'],
            ];
        }, $results);
    }

    /**
     * Get all analytics entries for a specific product
     *
     * @param int $idProduct Product ID
     * @return array Array of analytics rows for the product
     */
    public static function getProductStats(int $idProduct): array
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `id_product` = ' . (int) $idProduct . '
                ORDER BY `created_at` DESC';

        $results = Db::getInstance()->executeS($sql);

        return !empty($results) ? $results : [];
    }

    /**
     * Get the most-generated products
     *
     * @param int $limit Maximum number of products to return (default: 10)
     * @param int $days Number of days to look back (default: 30)
     * @return array Array of ['id_product' => int, 'count' => int, 'credits' => float]
     */
    public static function getTopProducts(int $limit = 10, int $days = 30): array
    {
        $sql = 'SELECT `id_product`, COUNT(*) AS `count`, SUM(`credits_used`) AS `credits`
                FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `id_product` > 0
                AND `created_at` >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                GROUP BY `id_product`
                ORDER BY `count` DESC
                LIMIT ' . (int) $limit;

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return [];
        }

        return array_map(function ($row) {
            return [
                'id_product' => (int) $row['id_product'],
                'count' => (int) $row['count'],
                'credits' => (float) $row['credits'],
            ];
        }, $results);
    }

    /**
     * Get usage breakdown by AI model
     *
     * @param int $days Number of days to look back (default: 30)
     * @return array Array of ['model' => string, 'count' => int, 'credits' => float]
     */
    public static function getModelUsage(int $days = 30): array
    {
        $sql = 'SELECT `model`, COUNT(*) AS `count`, SUM(`credits_used`) AS `credits`
                FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                AND `model` != \'\'
                GROUP BY `model`
                ORDER BY `count` DESC';

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return [];
        }

        return array_map(function ($row) {
            return [
                'model' => $row['model'],
                'count' => (int) $row['count'],
                'credits' => (float) $row['credits'],
            ];
        }, $results);
    }

    /**
     * Get day-by-day usage for charting
     *
     * @param int $days Number of days to look back (default: 30)
     * @return array Array of ['date' => string, 'count' => int, 'credits' => float]
     */
    public static function getDailyUsage(int $days = 30): array
    {
        $sql = 'SELECT DATE(`created_at`) AS `date`, COUNT(*) AS `count`, SUM(`credits_used`) AS `credits`
                FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                GROUP BY DATE(`created_at`)
                ORDER BY `date` ASC';

        $results = Db::getInstance()->executeS($sql);

        if (empty($results)) {
            return [];
        }

        return array_map(function ($row) {
            return [
                'date' => $row['date'],
                'count' => (int) $row['count'],
                'credits' => (float) $row['credits'],
            ];
        }, $results);
    }

    /**
     * Export analytics data as CSV string
     *
     * @param int $days Number of days to export (default: 90)
     * @return string CSV content with header row and data
     */
    public static function exportCsv(int $days = 90): string
    {
        $sql = 'SELECT `id_analytics`, `id_product`, `action`, `model`, `credits_used`, `status`, `created_at`, `metadata`
                FROM `' . _DB_PREFIX_ . 'fotohub_analytics`
                WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
                ORDER BY `created_at` DESC';

        $results = Db::getInstance()->executeS($sql);

        $csv = "id_analytics,id_product,action,model,credits_used,status,created_at,metadata\n";

        if (!empty($results)) {
            foreach ($results as $row) {
                $csv .= implode(',', [
                    $row['id_analytics'],
                    $row['id_product'],
                    '"' . str_replace('"', '""', $row['action']) . '"',
                    '"' . str_replace('"', '""', $row['model']) . '"',
                    $row['credits_used'],
                    '"' . str_replace('"', '""', $row['status']) . '"',
                    '"' . $row['created_at'] . '"',
                    '"' . str_replace('"', '""', $row['metadata'] ?? '') . '"',
                ]) . "\n";
            }
        }

        return $csv;
    }

    /**
     * Get recent activity entries with product names
     *
     * @param int $limit Maximum number of entries to return (default: 20)
     * @return array Array of analytics entries with 'product_name' joined
     */
    public static function getRecentActivity(int $limit = 20): array
    {
        $sql = 'SELECT a.*, pl.`name` AS `product_name`
                FROM `' . _DB_PREFIX_ . 'fotohub_analytics` a
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON a.`id_product` = pl.`id_product`
                    AND pl.`id_lang` = ' . (int) Configuration::get('PS_LANG_DEFAULT') . '
                    AND pl.`id_shop` = ' . (int) Context::getContext()->shop->id . '
                ORDER BY a.`created_at` DESC
                LIMIT ' . (int) $limit;

        $results = Db::getInstance()->executeS($sql);

        return !empty($results) ? $results : [];
    }
}
