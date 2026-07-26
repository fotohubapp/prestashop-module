<?php
/**
 * FOTOhub AI — Upgrade to 2.1.0
 *
 * - Creates the fotohub_draft table (draft-first write-back)
 * - Adds the Drafts Review admin tab
 * - Adds commerce-bridge configuration keys
 * - Migrates the API key encryption heuristic to an explicit flag
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_1_0($module): bool
{
    $success = true;

    // 1. Draft table
    require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubDraft.php';

    if (!FotoHubDraft::install()) {
        $success = false;
    }

    // 1b. Bring an existing fotohub_draft table (from a 2.1.0 pre-release) up
    // to the final schema: variant association, bridge item dedup, date_add.
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'fotohub_draft';

    $columns = [];
    $existing = $db->executeS('SHOW COLUMNS FROM `' . bqSQL($table) . '`');

    if (is_array($existing)) {
        foreach ($existing as $column) {
            $columns[] = $column['Field'];
        }
    }

    $additions = [
        'id_product_attribute' => 'ADD COLUMN `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id_product`',
        'bridge_item_id' => 'ADD COLUMN `bridge_item_id` VARCHAR(64) NULL AFTER `status`',
    ];

    foreach ($additions as $column => $clause) {
        if (!in_array($column, $columns, true)) {
            if (!$db->execute('ALTER TABLE `' . bqSQL($table) . '` ' . $clause)) {
                $success = false;
            }
        }
    }

    // created_at → date_add rename (only when the old column is still present)
    if (in_array('created_at', $columns, true) && !in_array('date_add', $columns, true)) {
        if (!$db->execute(
            'ALTER TABLE `' . bqSQL($table) . '` CHANGE `created_at` `date_add` DATETIME NOT NULL'
        )) {
            $success = false;
        }
    } elseif (!in_array('date_add', $columns, true)) {
        if (!$db->execute(
            'ALTER TABLE `' . bqSQL($table) . '` ADD COLUMN `date_add` DATETIME NOT NULL'
        )) {
            $success = false;
        }
    }

    // Dedup index for (bridge_item_id, type) — ignore failure, a legacy table
    // could already hold duplicates and the module works without the index.
    $indexes = $db->executeS(
        'SHOW INDEX FROM `' . bqSQL($table) . '` WHERE `Key_name` = \'idx_bridge_item\''
    );

    if (empty($indexes)) {
        $db->execute(
            'ALTER TABLE `' . bqSQL($table) . '` ADD UNIQUE KEY `idx_bridge_item` (`bridge_item_id`, `type`)'
        );
    }

    // 2. Drafts Review admin tab
    if (!(int) Tab::getIdFromClassName('AdminFotohubDrafts')) {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminFotohubDrafts';
        $tab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Drafts Review';
        }

        $tab->id_parent = (int) Tab::getIdFromClassName('AdminFotoHubConfig');
        $tab->module = 'fotohubai';

        if (!$tab->add()) {
            $success = false;
        }
    }

    // 3. New configuration keys (bridge + presets + jobs)
    $newKeys = [
        'FOTOHUBAI_BRIDGE_CONNECTION_ID' => '',
        'FOTOHUBAI_BRIDGE_CALLBACK_SECRET' => '',
        'FOTOHUBAI_BRIDGE_JOBS' => '',
        'FOTOHUBAI_DEFAULT_PRESET' => '',
        'FOTOHUBAI_PRESET_CACHE' => '',
    ];

    foreach ($newKeys as $key => $value) {
        if (Configuration::get($key) === false && !Configuration::updateValue($key, $value)) {
            $success = false;
        }
    }

    // 4. Encryption flag migration.
    // Old versions guessed "encrypted" from strlen > 64. Replay that heuristic
    // exactly once here to seed the explicit flag; from now on storeApiKey()
    // records the truth at save time.
    if (Configuration::get('FOTOHUBAI_KEY_ENCRYPTED') === false) {
        $stored = (string) Configuration::get('FOTOHUBAI_API_KEY');
        $looksEncrypted = 0;

        if (!empty($stored) && strlen($stored) > 64 && base64_decode($stored, true) !== false) {
            $looksEncrypted = 1;
        }

        if (!Configuration::updateValue('FOTOHUBAI_KEY_ENCRYPTED', $looksEncrypted)) {
            $success = false;
        }
    }

    // 5. Retire the fake default video model if it was still configured
    if (Configuration::get('FOTOHUBAI_DEFAULT_VIDEO_MODEL') === 'veo-2') {
        Configuration::updateValue('FOTOHUBAI_DEFAULT_VIDEO_MODEL', 'veo-3.1-fast-generate-001');
    }

    return $success;
}
