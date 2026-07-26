<?php
/**
 * FOTOhub Draft Store for PrestaShop
 *
 * Draft-first write-back: every AI result (image or text) lands in the
 * fotohub_draft table as a pending draft. Nothing touches the live product
 * until the merchant approves the draft in AdminFotohubDrafts. Approval
 * routes through FotoHubWriteback; rejection just archives the row.
 *
 * Schema (created by install() and upgrade/upgrade-2.1.0.php):
 *   id_draft, id_product, id_product_attribute, type, payload, status,
 *   bridge_item_id, job_id, kind, error_message, date_add, reviewed_at
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/FotoHubWriteback.php';

class FotoHubDraft
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    public const TYPE_IMAGE = 'image';
    public const TYPE_TEXT = 'text';

    /** Draft types recognised by approve() */
    public const TYPES = [self::TYPE_IMAGE, self::TYPE_TEXT];

    /** Statuses accepted by the list filter */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_FAILED,
    ];

    /**
     * SQL to create the draft table (used by install() and upgrade-2.1.0.php)
     */
    public static function getInstallSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'fotohub_draft` (
            `id_draft` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `type` VARCHAR(20) NOT NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT \'pending\',
            `bridge_item_id` VARCHAR(64) NULL,
            `job_id` VARCHAR(64) NULL,
            `kind` VARCHAR(40) NULL,
            `error_message` TEXT NULL,
            `date_add` DATETIME NOT NULL,
            `reviewed_at` DATETIME NULL,
            PRIMARY KEY (`id_draft`),
            UNIQUE KEY `idx_bridge_item` (`bridge_item_id`, `type`),
            KEY `idx_product` (`id_product`),
            KEY `idx_status` (`status`),
            KEY `idx_job` (`job_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';
    }

    /**
     * Create the draft table
     */
    public static function install(): bool
    {
        return (bool) Db::getInstance()->execute(self::getInstallSql());
    }

    /**
     * Drop the draft table
     */
    public static function uninstall(): bool
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'fotohub_draft`'
        );
    }

    /**
     * Store a new draft.
     *
     * Image payload: {image_urls: [...], source_image_url?: string}
     * Text payload:  {title?, short_description?, description?, meta_title?,
     *                 meta_description?, alt_text?, faq?, json_ld?, language?}
     *
     * A non-null $bridgeItemId makes the insert idempotent: webhook delivery
     * and cron polling can both report the same item without duplicating it.
     *
     * @param int $idProduct Product ID
     * @param string $type 'image' | 'text'
     * @param array $payload Result payload
     * @param string|null $jobId Bridge job ID this draft came from
     * @param string|null $kind Bridge job kind
     * @param int $idProductAttribute Combination ID (0 = whole product)
     * @param string|null $bridgeItemId Bridge item ID for deduplication
     * @return int Draft ID (0 when skipped as a duplicate)
     * @throws PrestaShopException
     */
    public static function add(
        int $idProduct,
        string $type,
        array $payload,
        ?string $jobId = null,
        ?string $kind = null,
        int $idProductAttribute = 0,
        ?string $bridgeItemId = null
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new PrestaShopException('FOTOhub Draft: Invalid type "' . $type . '"');
        }

        $db = Db::getInstance();

        // Deduplicate on (bridge_item_id, type) so a webhook + cron race cannot
        // create two drafts for the same bridge item.
        if ($bridgeItemId !== null && $bridgeItemId !== '') {
            $existing = (int) $db->getValue(
                'SELECT `id_draft` FROM `' . _DB_PREFIX_ . 'fotohub_draft`
                 WHERE `bridge_item_id` = \'' . pSQL($bridgeItemId) . '\'
                   AND `type` = \'' . pSQL($type) . '\''
            );

            if ($existing > 0) {
                return 0;
            }
        }

        $encoded = json_encode($payload);

        if ($encoded === false) {
            throw new PrestaShopException('FOTOhub Draft: Payload could not be encoded');
        }

        $data = [
            'id_product' => (int) $idProduct,
            'id_product_attribute' => (int) $idProductAttribute,
            'type' => pSQL($type),
            'payload' => pSQL($encoded, true),
            'status' => self::STATUS_PENDING,
            'date_add' => date('Y-m-d H:i:s'),
        ];

        if ($jobId !== null) {
            $data['job_id'] = pSQL($jobId);
        }

        if ($kind !== null) {
            $data['kind'] = pSQL($kind);
        }

        if ($bridgeItemId !== null && $bridgeItemId !== '') {
            $data['bridge_item_id'] = pSQL($bridgeItemId);
        }

        if (!$db->insert('fotohub_draft', $data)) {
            throw new PrestaShopException('FOTOhub Draft: Failed to store draft');
        }

        return (int) $db->Insert_ID();
    }

    /**
     * Get a single draft row (payload decoded)
     */
    public static function get(int $idDraft): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'fotohub_draft` WHERE `id_draft` = ' . (int) $idDraft
        );

        if (!$row) {
            return null;
        }

        $row['payload'] = json_decode($row['payload'], true) ?: [];

        return $row;
    }

    /**
     * List drafts, optionally filtered by status, with product names joined
     *
     * @param string|null $status Filter by status (null = all)
     * @param int $limit Page size
     * @param int $offset Offset
     * @return array Draft rows with decoded payloads and 'product_name'
     */
    public static function getList(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $idLang = (int) Context::getContext()->language->id;
        $idShop = (int) Context::getContext()->shop->id;

        $where = '';

        if ($status !== null) {
            if (!in_array($status, self::STATUSES, true)) {
                return [];
            }
            $where = ' WHERE d.`status` = \'' . pSQL($status) . '\'';
        }

        $sql = 'SELECT d.*, pl.`name` AS `product_name`
                FROM `' . _DB_PREFIX_ . 'fotohub_draft` d
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON d.`id_product` = pl.`id_product`
                    AND pl.`id_lang` = ' . $idLang . '
                    AND pl.`id_shop` = ' . $idShop
            . $where . '
                ORDER BY d.`date_add` DESC
                LIMIT ' . (int) max(1, $limit) . ' OFFSET ' . (int) max(0, $offset);

        $rows = Db::getInstance()->executeS($sql);

        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['payload'] = json_decode($row['payload'], true) ?: [];
            $row['combination_name'] = (int) $row['id_product_attribute'] > 0
                ? self::describeCombination((int) $row['id_product_attribute'], $idLang)
                : '';
        }
        unset($row);

        return $rows;
    }

    /**
     * Human-readable combination label, e.g. "Size: L, Color: Blue"
     */
    public static function describeCombination(int $idProductAttribute, int $idLang): string
    {
        if ($idProductAttribute <= 0) {
            return '';
        }

        $rows = Db::getInstance()->executeS(
            'SELECT agl.`name` AS `group_name`, al.`name` AS `attribute_name`
             FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
             INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . (int) $idLang . '
             INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                ON agl.`id_attribute_group` = a.`id_attribute_group` AND agl.`id_lang` = ' . (int) $idLang . '
             WHERE pac.`id_product_attribute` = ' . (int) $idProductAttribute
        );

        if (!is_array($rows) || empty($rows)) {
            return '';
        }

        $parts = [];

        foreach ($rows as $row) {
            $parts[] = $row['group_name'] . ': ' . $row['attribute_name'];
        }

        return implode(', ', $parts);
    }

    /**
     * Count drafts by status
     */
    public static function countByStatus(string $status = self::STATUS_PENDING): int
    {
        if (!in_array($status, self::STATUSES, true)) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'fotohub_draft`
             WHERE `status` = \'' . pSQL($status) . '\''
        );
    }

    /**
     * Approve a draft: write it to the live product via FotoHubWriteback.
     * This is the ONLY path from AI output to live catalog data.
     *
     * @param int $idDraft Draft ID
     * @param int $idLang Language for text write-back
     * @return bool True on success
     */
    public static function approve(int $idDraft, int $idLang): bool
    {
        $draft = self::get($idDraft);

        if (!$draft || $draft['status'] !== self::STATUS_PENDING) {
            return false;
        }

        $writer = new FotoHubWriteback($idLang);
        $idProduct = (int) $draft['id_product'];
        $idProductAttribute = (int) ($draft['id_product_attribute'] ?? 0);
        $success = false;
        $error = '';

        try {
            if ($draft['type'] === self::TYPE_IMAGE) {
                $urls = $draft['payload']['image_urls'] ?? [];

                if (empty($urls) && !empty($draft['payload']['image_url'])) {
                    $urls = [$draft['payload']['image_url']];
                }

                if (empty($urls)) {
                    $error = 'Draft contains no image URL';
                } else {
                    $success = true;

                    foreach ($urls as $url) {
                        if (!is_string($url) || !$writer->addImageToProduct($idProduct, $url, $idProductAttribute)) {
                            $success = false;
                            $error = 'One or more images could not be imported';
                        }
                    }
                }
            } elseif ($draft['type'] === self::TYPE_TEXT) {
                $success = $writer->applyTextFields($idProduct, $draft['payload'], $idProductAttribute);

                if (!$success) {
                    $error = 'Product could not be saved';
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            $success = false;
        }

        self::setStatus($idDraft, $success ? self::STATUS_APPROVED : self::STATUS_FAILED, $error);

        return $success;
    }

    /**
     * Reject a draft (no product change)
     */
    public static function reject(int $idDraft): bool
    {
        $draft = self::get($idDraft);

        if (!$draft || $draft['status'] !== self::STATUS_PENDING) {
            return false;
        }

        return self::setStatus($idDraft, self::STATUS_REJECTED);
    }

    /**
     * Approve a specific set of drafts (bulk approve from the review table)
     *
     * @param array $draftIds Draft IDs
     * @param int $idLang Language for text write-back
     * @return array {approved: int, failed: int}
     */
    public static function approveMany(array $draftIds, int $idLang): array
    {
        $summary = ['approved' => 0, 'failed' => 0];

        foreach ($draftIds as $idDraft) {
            if (self::approve((int) $idDraft, $idLang)) {
                $summary['approved']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * Reject a specific set of drafts
     *
     * @return int Number of drafts rejected
     */
    public static function rejectMany(array $draftIds): int
    {
        $rejected = 0;

        foreach ($draftIds as $idDraft) {
            if (self::reject((int) $idDraft)) {
                $rejected++;
            }
        }

        return $rejected;
    }

    /**
     * Approve all pending drafts
     *
     * @param int $idLang Language for text write-back
     * @return array {approved: int, failed: int}
     */
    public static function approveAll(int $idLang): array
    {
        $summary = ['approved' => 0, 'failed' => 0];

        // Re-query each page: approve() flips rows out of 'pending', so always
        // reading the first page drains the queue without skipping rows.
        while (true) {
            $pending = self::getList(self::STATUS_PENDING, 50, 0);

            if (empty($pending)) {
                break;
            }

            $before = $summary['approved'] + $summary['failed'];

            foreach ($pending as $draft) {
                if (self::approve((int) $draft['id_draft'], $idLang)) {
                    $summary['approved']++;
                } else {
                    $summary['failed']++;
                }
            }

            // Safety: if a page produced no state change, stop instead of looping
            if ($summary['approved'] + $summary['failed'] === $before) {
                break;
            }
        }

        return $summary;
    }

    /**
     * Update draft status
     */
    private static function setStatus(int $idDraft, string $status, string $error = ''): bool
    {
        $sql = 'UPDATE `' . _DB_PREFIX_ . 'fotohub_draft` SET
                    `status` = \'' . pSQL($status) . '\',
                    `reviewed_at` = NOW()'
            . ($error !== '' ? ', `error_message` = \'' . pSQL($error) . '\'' : '')
            . ' WHERE `id_draft` = ' . (int) $idDraft;

        return (bool) Db::getInstance()->execute($sql);
    }
}
