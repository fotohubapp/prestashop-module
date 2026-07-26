<?php
/**
 * FOTOhub AI Drafts Review Controller
 *
 * Draft-first write-back review UI: lists pending AI results (images and
 * text) with before/after comparison; the merchant approves per item, for a
 * selected set, or all at once. Only approval writes to the live product.
 *
 * Every mutating action requires the admin token (CSRF) and the controller's
 * edit permission.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubDraft.php';
require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubWriteback.php';
require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubBulkProcessor.php';

class AdminFotohubDraftsController extends ModuleAdminController
{
    /** Drafts shown per page */
    public const PAGE_SIZE = 50;

    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = $this->l('FOTOhub AI — Drafts Review');
    }

    /**
     * Initialize page content
     */
    public function initContent(): void
    {
        parent::initContent();

        $this->handleActions();

        $statusFilter = (string) Tools::getValue('draft_status', FotoHubDraft::STATUS_PENDING);
        $validStatuses = array_merge(FotoHubDraft::STATUSES, ['all']);

        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = FotoHubDraft::STATUS_PENDING;
        }

        $page = max(1, (int) Tools::getValue('draft_page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $drafts = FotoHubDraft::getList(
            $statusFilter === 'all' ? null : $statusFilter,
            self::PAGE_SIZE,
            $offset
        );

        // Enrich with "before" data for the comparison view
        foreach ($drafts as &$draft) {
            $draft['before'] = $this->getBeforeState(
                (int) $draft['id_product'],
                (string) $draft['type'],
                (int) ($draft['id_product_attribute'] ?? 0)
            );
            $draft['diff'] = $draft['type'] === FotoHubDraft::TYPE_TEXT
                ? $this->buildTextDiff($draft['before'], $draft['payload'])
                : [];
            $draft['product_url'] = $this->context->link->getAdminLink('AdminProducts', true, [
                'id_product' => (int) $draft['id_product'],
                'updateproduct' => 1,
            ]);
        }
        unset($draft);

        $this->context->smarty->assign([
            'fotohub_drafts' => $drafts,
            'fotohub_draft_status' => $statusFilter,
            'fotohub_draft_page' => $page,
            'fotohub_draft_page_size' => self::PAGE_SIZE,
            'fotohub_draft_has_next' => count($drafts) === self::PAGE_SIZE,
            'fotohub_counts' => [
                'pending' => FotoHubDraft::countByStatus(FotoHubDraft::STATUS_PENDING),
                'approved' => FotoHubDraft::countByStatus(FotoHubDraft::STATUS_APPROVED),
                'rejected' => FotoHubDraft::countByStatus(FotoHubDraft::STATUS_REJECTED),
                'failed' => FotoHubDraft::countByStatus(FotoHubDraft::STATUS_FAILED),
            ],
            'fotohub_pending_count' => FotoHubDraft::countByStatus(FotoHubDraft::STATUS_PENDING),
            'fotohub_drafts_url' => $this->context->link->getAdminLink('AdminFotohubDrafts'),
            'fotohub_token' => $this->token,
            'fotohub_can_edit' => $this->canEdit(),
        ]);

        $this->setTemplate('drafts.tpl');
    }

    /**
     * Dispatch mutating actions after verifying CSRF token and permission
     */
    private function handleActions(): void
    {
        $isMutating = Tools::isSubmit('approveDraft')
            || Tools::isSubmit('rejectDraft')
            || Tools::isSubmit('approveAllDrafts')
            || Tools::isSubmit('bulkApproveDrafts')
            || Tools::isSubmit('bulkRejectDrafts');

        if (!$isMutating) {
            return;
        }

        // CSRF: the admin token must match this controller's token
        if (!$this->verifyToken()) {
            $this->errors[] = $this->l('Invalid security token. Please reload the page and try again.');
            return;
        }

        if (!$this->canEdit()) {
            $this->errors[] = $this->l('You do not have permission to approve or reject drafts.');
            return;
        }

        if (Tools::isSubmit('approveDraft')) {
            $this->processApprove((int) Tools::getValue('id_draft'));
        } elseif (Tools::isSubmit('rejectDraft')) {
            $this->processReject((int) Tools::getValue('id_draft'));
        } elseif (Tools::isSubmit('approveAllDrafts')) {
            $this->processApproveAll();
        } elseif (Tools::isSubmit('bulkApproveDrafts')) {
            $this->processBulk(true);
        } elseif (Tools::isSubmit('bulkRejectDrafts')) {
            $this->processBulk(false);
        }
    }

    /**
     * Verify the admin CSRF token for the current request
     */
    private function verifyToken(): bool
    {
        $token = Tools::getValue('token');

        if (!is_string($token) || $token === '') {
            return false;
        }

        return hash_equals((string) $this->token, $token);
    }

    /**
     * Does the employee hold the edit permission on this controller?
     */
    private function canEdit(): bool
    {
        return (bool) Access::isGranted(
            'ROLE_MOD_TAB_' . strtoupper($this->controller_name) . '_UPDATE',
            $this->context->employee->id_profile
        );
    }

    /**
     * Collect and validate the selected draft IDs from a bulk submit
     *
     * @return int[] Validated draft IDs
     */
    private function getSelectedDraftIds(): array
    {
        $raw = Tools::getValue('draft_box');

        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = (int) $value;

            if ($id > 0 && Validate::isUnsignedId($id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Current live state of the product for before/after comparison
     */
    private function getBeforeState(int $idProduct, string $type, int $idProductAttribute = 0): array
    {
        $idLang = (int) $this->context->language->id;

        if ($type === FotoHubDraft::TYPE_IMAGE) {
            return ['image_url' => $this->getLiveImageUrl($idProduct, $idProductAttribute, $idLang)];
        }

        $product = new Product($idProduct, false, $idLang);

        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        return [
            'title' => (string) $product->name,
            'short_description' => (string) $product->description_short,
            'description' => (string) $product->description,
            'meta_title' => (string) $product->meta_title,
            'meta_description' => (string) $product->meta_description,
        ];
    }

    /**
     * URL of the image the draft would replace/extend: the combination image
     * when the draft targets a variant, otherwise the product cover.
     */
    private function getLiveImageUrl(int $idProduct, int $idProductAttribute, int $idLang): string
    {
        $idImage = 0;

        if ($idProductAttribute > 0) {
            $idImage = (int) Db::getInstance()->getValue(
                'SELECT `id_image` FROM `' . _DB_PREFIX_ . 'product_attribute_image`
                 WHERE `id_product_attribute` = ' . (int) $idProductAttribute . ' LIMIT 1'
            );
        }

        if ($idImage <= 0) {
            $images = Image::getImages($idLang, $idProduct);

            if (empty($images)) {
                return '';
            }

            $idImage = (int) $images[0]['id_image'];
        }

        try {
            $imageUrl = $this->context->link->getImageLink(
                Product::getProductName($idProduct),
                $idImage,
                ImageType::getFormattedName('home')
            );

            if (!empty($imageUrl) && strpos($imageUrl, 'http') !== 0) {
                $imageUrl = 'https://' . ltrim($imageUrl, '/');
            }

            return (string) $imageUrl;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Build a per-field before/after diff for text drafts so the merchant sees
     * exactly which fields the draft would change.
     *
     * @return array [{field, label, before, after, changed}]
     */
    private function buildTextDiff(array $before, array $payload): array
    {
        $labels = [
            'title' => $this->l('Title'),
            'short_description' => $this->l('Short description'),
            'description' => $this->l('Description'),
            'meta_title' => $this->l('Meta title'),
            'meta_description' => $this->l('Meta description'),
            'alt_text' => $this->l('Image alt text'),
            'faq' => $this->l('FAQ'),
            'json_ld' => $this->l('JSON-LD structured data'),
        ];

        $diff = [];

        foreach ($labels as $field => $label) {
            if (!isset($payload[$field]) || $payload[$field] === '' || !is_string($payload[$field])) {
                continue;
            }

            $beforeValue = isset($before[$field]) && is_string($before[$field]) ? $before[$field] : '';
            $afterValue = $payload[$field];

            $diff[] = [
                'field' => $field,
                'label' => $label,
                'before' => $beforeValue,
                'after' => $afterValue,
                'changed' => trim(strip_tags($beforeValue)) !== trim(strip_tags($afterValue)),
            ];
        }

        return $diff;
    }

    /**
     * Approve a single draft → writes to the live product
     */
    private function processApprove(int $idDraft): void
    {
        if ($idDraft <= 0) {
            $this->errors[] = $this->l('No draft selected.');
            return;
        }

        if (FotoHubDraft::approve($idDraft, (int) $this->context->language->id)) {
            $this->confirmations[] = $this->l('Draft approved and applied to the product.');
        } else {
            $this->errors[] = $this->l('Failed to apply the draft. Check the draft row for the error message.');
        }
    }

    /**
     * Reject a single draft (product untouched)
     */
    private function processReject(int $idDraft): void
    {
        if ($idDraft <= 0) {
            $this->errors[] = $this->l('No draft selected.');
            return;
        }

        if (FotoHubDraft::reject($idDraft)) {
            $this->confirmations[] = $this->l('Draft rejected. The product was not changed.');
        } else {
            $this->errors[] = $this->l('Failed to reject the draft.');
        }
    }

    /**
     * Bulk approve or reject the checked drafts
     */
    private function processBulk(bool $approve): void
    {
        $ids = $this->getSelectedDraftIds();

        if (empty($ids)) {
            $this->warnings[] = $this->l('Select at least one draft first.');
            return;
        }

        if ($approve) {
            $summary = FotoHubDraft::approveMany($ids, (int) $this->context->language->id);

            if ($summary['approved'] > 0) {
                $this->confirmations[] = sprintf(
                    $this->l('%d draft(s) approved and applied.'),
                    $summary['approved']
                );
            }

            if ($summary['failed'] > 0) {
                $this->errors[] = sprintf($this->l('%d draft(s) failed to apply.'), $summary['failed']);
            }

            return;
        }

        $rejected = FotoHubDraft::rejectMany($ids);

        $this->confirmations[] = sprintf(
            $this->l('%d draft(s) rejected. No products were changed.'),
            $rejected
        );
    }

    /**
     * Approve all pending drafts
     */
    private function processApproveAll(): void
    {
        $summary = FotoHubDraft::approveAll((int) $this->context->language->id);

        if ($summary['approved'] > 0) {
            $this->confirmations[] = sprintf(
                $this->l('%d draft(s) approved and applied.'),
                $summary['approved']
            );
        }

        if ($summary['failed'] > 0) {
            $this->errors[] = sprintf(
                $this->l('%d draft(s) failed to apply.'),
                $summary['failed']
            );
        }

        if ($summary['approved'] === 0 && $summary['failed'] === 0) {
            $this->warnings[] = $this->l('No pending drafts to approve.');
        }
    }

    /**
     * Set the admin template directory
     */
    public function setMedia($isNewTheme = false): bool
    {
        parent::setMedia($isNewTheme);

        $this->addCSS(_PS_MODULE_DIR_ . 'fotohubai/views/css/admin.css');

        return true;
    }

    /**
     * Override template directory to point to module views
     */
    public function setTemplate($template, $params = [], $locale = null): void
    {
        if (file_exists(_PS_MODULE_DIR_ . 'fotohubai/views/templates/admin/' . $template)) {
            $this->context->smarty->assign('module_template_dir', _PS_MODULE_DIR_ . 'fotohubai/views/templates/admin/');
            parent::setTemplate(
                _PS_MODULE_DIR_ . 'fotohubai/views/templates/admin/' . $template
            );
        } else {
            parent::setTemplate($template, $params, $locale);
        }
    }
}
