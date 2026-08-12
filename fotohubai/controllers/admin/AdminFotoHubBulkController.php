<?php
/**
 * FOTOhub AI Bulk Processing Controller
 *
 * Bridge-first bulk operations:
 * - Product picker with filters (missing description, few images, category, search)
 * - Preset gallery (bridge GET /presets, grouped, PL names)
 * - Cost preflight (bridge POST /estimate) before every submit
 * - Bulk job submit to bridge POST /jobs with real product_context
 * - Progress UI backed by GET /jobs/{id}; retry-failed and cancel buttons;
 *   job IDs persist in Configuration so progress survives page reload
 * - Local resumable pipeline kept as fallback for single-store sync ops
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubBridgeClient.php';
require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubBulkProcessor.php';
require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubDraft.php';

class AdminFotoHubBulkController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'product';
        $this->className = 'Product';
        $this->lang = true;
        $this->list_no_link = true;

        parent::__construct();

        $this->meta_title = $this->l('FOTOhub AI — Bulk Processing');

        // Define fields for the product list
        $this->fields_list = [
            'id_product' => [
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'image' => [
                'title' => $this->l('Image'),
                'align' => 'center',
                'image' => 'p',
                'orderby' => false,
                'filter' => false,
                'search' => false,
            ],
            'name' => [
                'title' => $this->l('Name'),
                'filter_key' => 'b!name',
            ],
            'reference' => [
                'title' => $this->l('Reference'),
            ],
            'category' => [
                'title' => $this->l('Category'),
                'filter_key' => 'cl!name',
            ],
            'image_count' => [
                'title' => $this->l('Images'),
                'align' => 'center',
                'orderby' => false,
                'filter' => false,
                'search' => false,
            ],
            'active' => [
                'title' => $this->l('Active'),
                'active' => 'status',
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-sm',
            ],
        ];

        // Bulk actions — the nine bridge kinds plus legacy sync ops
        $this->bulk_actions = [
            'generateImages' => [
                'text' => $this->l('Generate AI Images'),
                'icon' => 'icon-magic',
                'confirm' => $this->l('Generate AI product photos for selected products?'),
            ],
            'editImages' => [
                'text' => $this->l('AI Edit Images'),
                'icon' => 'icon-edit',
                'confirm' => $this->l('Run AI image edit on selected products?'),
            ],
            'removeBackgrounds' => [
                'text' => $this->l('Remove Backgrounds'),
                'icon' => 'icon-scissors',
                'confirm' => $this->l('Remove backgrounds from selected products\' images?'),
            ],
            'replaceBackgrounds' => [
                'text' => $this->l('Replace Backgrounds'),
                'icon' => 'icon-picture',
                'confirm' => $this->l('Replace backgrounds for selected products?'),
            ],
            'upscaleImages' => [
                'text' => $this->l('Upscale Images'),
                'icon' => 'icon-resize-full',
                'confirm' => $this->l('Upscale images for selected products?'),
            ],
            'recolorImages' => [
                'text' => $this->l('Recolor Object'),
                'icon' => 'icon-tint',
                'confirm' => $this->l('Recolor the target object in selected products\' images?'),
            ],
            'writeDescriptions' => [
                'text' => $this->l('Generate Descriptions'),
                'icon' => 'icon-file-text',
                'confirm' => $this->l('Generate AI descriptions for selected products?'),
            ],
            'writeAltTexts' => [
                'text' => $this->l('Generate Alt Texts'),
                'icon' => 'icon-tag',
                'confirm' => $this->l('Generate image alt texts for selected products?'),
            ],
            'completeListings' => [
                'text' => $this->l('Complete Listing (image + copy)'),
                'icon' => 'icon-rocket',
                'confirm' => $this->l('Run the full listing pipeline for selected products?'),
            ],
        ];
    }

    /**
     * Initialize page content
     */
    public function initContent(): void
    {
        // Check if API key is configured
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->warnings[] = $this->l('Please configure your FOTOhub API key first.')
                . ' <a href="' . $this->context->link->getAdminLink('AdminFotoHubConfig') . '">'
                . $this->l('Go to Configuration') . '</a>';
        }

        // Job control actions (retry-failed / cancel). Both mutate remote state,
        // so they require the admin CSRF token and edit permission.
        if (Tools::isSubmit('retryFailedJob') || Tools::isSubmit('cancelBridgeJob')) {
            if (!$this->verifyRequestToken()) {
                $this->errors[] = $this->l('Invalid security token. Please reload the page and try again.');
            } elseif (!$this->canEdit()) {
                $this->errors[] = $this->l('You do not have permission to manage FOTOhub jobs.');
            } elseif (Tools::isSubmit('retryFailedJob')) {
                $this->processRetryFailed((string) Tools::getValue('bridge_job_id'));
            } else {
                $this->processCancelJob((string) Tools::getValue('bridge_job_id'));
            }
        }

        // Show results if we just processed a batch
        if (Tools::getValue('bulk_results')) {
            $results = json_decode(base64_decode(Tools::getValue('bulk_results')), true);
            if (!empty($results)) {
                $this->context->smarty->assign('fotohub_bulk_results', $results);
            }
        }

        // Progress UI data: refresh tracked jobs (survives page reload)
        $activeJobs = [];
        $presetsGrouped = [];
        $credits = null;

        if (!empty($apiKey)) {
            $bridge = new FotoHubBridgeClient($apiKey);

            try {
                FotoHubBulkProcessor::pollBridgeJobs($bridge);
            } catch (Exception $e) {
                // Poll failure is non-fatal for the page
            }

            $activeJobs = FotoHubBulkProcessor::getActiveJobs();

            // Precompute the progress percentage — Smarty templates must not
            // carry arithmetic, and division by zero has to be handled here.
            foreach ($activeJobs as &$job) {
                $total = (int) ($job['total_items'] ?? 0);
                $done = (int) ($job['done_items'] ?? 0);
                $job['percent'] = $total > 0 ? (int) round(100 * min($done, $total) / $total) : 0;
            }
            unset($job);

            // Preset gallery (feature 3) with PL names for Polish back offices
            try {
                $isoLang = strtolower((string) $this->context->language->iso_code);
                $presetsGrouped = $bridge->getPresetsGrouped($isoLang);
            } catch (Exception $e) {
                // Presets unavailable — wizard still works without them
            }

            try {
                $credits = $bridge->getCreditsAvailable();
            } catch (Exception $e) {
                // Meter shows n/a
            }
        }

        $this->context->smarty->assign([
            'fotohub_active_jobs' => $activeJobs,
            'fotohub_presets' => $presetsGrouped,
            'fotohub_default_preset' => Configuration::get('FOTOHUBAI_DEFAULT_PRESET'),
            'fotohub_credits' => $credits,
            'fotohub_low_balance' => ($credits !== null && $credits < 50),
            'fotohub_pending_drafts' => FotoHubDraft::countByStatus(FotoHubDraft::STATUS_PENDING),
            'fotohub_drafts_url' => $this->context->link->getAdminLink('AdminFotohubDrafts'),
            'fotohub_bulk_ajax_url' => $this->context->link->getAdminLink('AdminFotoHubBulk') . '&ajax=1',
            'fotohub_image_models' => FotoHubBridgeClient::IMAGE_MODELS,
            'fotohub_tones' => FotoHubBridgeClient::TONES,
            'fotohub_languages' => FotoHubBridgeClient::LANGUAGES,
            'fotohub_text_fields' => FotoHubBridgeClient::TEXT_FIELDS,
            'fotohub_token' => $this->token,
            'fotohub_can_edit' => $this->canEdit(),
            'fotohub_bridge_connected' => (bool) FotoHubBridgeClient::getStoredConnectionId(),
            'fotohub_config_url' => $this->context->link->getAdminLink('AdminFotoHubConfig'),
        ]);

        parent::initContent();
    }

    /**
     * Build the product list SQL query.
     *
     * Product picker filters (feature 2):
     *   fh_filter=missing_description — products with empty long description
     *   fh_filter=few_images&fh_max_images=N — products with fewer than N images
     *   fh_filter=no_images — products with no image at all
     *   fh_price_min / fh_price_max — price range
     * These are APPENDED to $this->_where so the list's own category filter and
     * text search keep working alongside them.
     */
    public function renderList(): string|false
    {
        $this->addRowAction('view');

        $this->_select = 'cl.`name` as category, image_shop.`id_image` as id_image,
            (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image` i WHERE i.`id_product` = a.`id_product`) as image_count';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON (cl.`id_category` = a.`id_category_default`
                AND cl.`id_lang` = ' . (int) $this->context->language->id . '
                AND cl.`id_shop` = ' . (int) $this->context->shop->id . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` image_shop
                ON (image_shop.`id_product` = a.`id_product`
                AND image_shop.`cover` = 1
                AND image_shop.`id_shop` = ' . (int) $this->context->shop->id . ')';

        $filter = (string) Tools::getValue('fh_filter');
        $maxImages = max(1, min(20, (int) Tools::getValue('fh_max_images', 2)));

        if ($filter === 'missing_description') {
            $this->_where .= ' AND (b.`description` IS NULL OR b.`description` = \'\')';
        } elseif ($filter === 'few_images') {
            $this->_where .= ' AND (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'image` i2
                WHERE i2.`id_product` = a.`id_product`) < ' . (int) $maxImages;
        } elseif ($filter === 'no_images') {
            $this->_where .= ' AND NOT EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'image` i3
                WHERE i3.`id_product` = a.`id_product`)';
        }

        // Price range filter
        $priceMin = Tools::getValue('fh_price_min');
        $priceMax = Tools::getValue('fh_price_max');

        if ($priceMin !== false && $priceMin !== '' && Validate::isPrice($priceMin)) {
            $this->_where .= ' AND a.`price` >= ' . (float) $priceMin;
        }

        if ($priceMax !== false && $priceMax !== '' && Validate::isPrice($priceMax)) {
            $this->_where .= ' AND a.`price` <= ' . (float) $priceMax;
        }

        $this->context->smarty->assign([
            'fotohub_active_filter' => $filter,
            'fotohub_max_images' => $maxImages,
            'fotohub_price_min' => is_string($priceMin) ? $priceMin : '',
            'fotohub_price_max' => is_string($priceMax) ? $priceMax : '',
        ]);

        return parent::renderList();
    }

    // ── Bulk action dispatchers (nine kinds) ─────────────────────────────────

    protected function processBulkGenerateImages(): void
    {
        $this->processBridgeBulkAction('image_generate');
    }

    protected function processBulkEditImages(): void
    {
        $this->processBridgeBulkAction('image_edit');
    }

    protected function processBulkRemoveBackgrounds(): void
    {
        $this->processBridgeBulkAction('bg_remove');
    }

    protected function processBulkReplaceBackgrounds(): void
    {
        $this->processBridgeBulkAction('bg_replace');
    }

    protected function processBulkUpscaleImages(): void
    {
        $this->processBridgeBulkAction('upscale');
    }

    protected function processBulkRecolorImages(): void
    {
        $this->processBridgeBulkAction('recolor');
    }

    protected function processBulkWriteDescriptions(): void
    {
        $this->processBridgeBulkAction('description');
    }

    protected function processBulkWriteAltTexts(): void
    {
        $this->processBridgeBulkAction('alt_text');
    }

    protected function processBulkCompleteListings(): void
    {
        $this->processBridgeBulkAction('complete_listing');
    }

    /**
     * Submit a bulk action as a bridge job. Falls back to the local resumable
     * pipeline when the bridge is not connected. Runs the POST /estimate
     * preflight and blocks with a clear message when credits are insufficient.
     */
    private function processBridgeBulkAction(string $kind): void
    {
        if (!$this->canEdit()) {
            $this->errors[] = $this->l('You do not have permission to run FOTOhub bulk actions.');
            return;
        }

        $productIds = [];

        foreach ((array) $this->boxes as $value) {
            $id = (int) $value;

            if ($id > 0 && Validate::isUnsignedId($id)) {
                $productIds[] = $id;
            }
        }

        $productIds = array_values(array_unique($productIds));

        if (empty($productIds)) {
            $this->errors[] = $this->l('Please select at least one product.');
            return;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->errors[] = $this->l('API key not configured. Please set up your FOTOhub API key first.');
            return;
        }

        $options = $this->collectJobOptions($kind);

        $model = (string) Tools::getValue('fh_model');
        if (!isset(FotoHubBridgeClient::IMAGE_MODELS[$model])) {
            $model = (string) Configuration::get('FOTOHUBAI_DEFAULT_MODEL');
        }

        $presetSlug = trim((string) Tools::getValue('fh_preset'));
        if ($presetSlug === '' || !Validate::isLinkRewrite($presetSlug)) {
            $presetSlug = (string) Configuration::get('FOTOHUBAI_DEFAULT_PRESET');
        }
        $presetSlug = $presetSlug !== '' ? $presetSlug : null;

        // Remember the last chosen preset as the store default (feature 3)
        if ($presetSlug !== null) {
            Configuration::updateValue('FOTOHUBAI_DEFAULT_PRESET', $presetSlug);
        }

        // Per-variant mode: one job item per combination (feature B6)
        $perVariant = (bool) Tools::getValue('fh_per_variant');

        $bridge = new FotoHubBridgeClient($apiKey);
        $connectionId = FotoHubBridgeClient::getStoredConnectionId();

        // No bridge connection → local fallback pipeline
        if (empty($connectionId)) {
            $this->runLocalFallback($bridge, $productIds, $kind, $options);
            return;
        }

        // The estimate must be based on the real item count, which for
        // per-variant jobs is the number of combinations, not products.
        $processor = new FotoHubBulkProcessor($bridge, (int) $this->context->language->id);
        $itemCount = count($processor->buildBridgeItems($productIds, $kind, $perVariant));

        if ($itemCount === 0) {
            $this->errors[] = $this->l('None of the selected products can be processed with this action (a source image is required).');
            return;
        }

        // Cost preflight (feature 4)
        try {
            $estimate = $bridge->estimate($kind, $itemCount, $options, $model);

            if (isset($estimate['sufficient']) && !$estimate['sufficient']) {
                $this->errors[] = sprintf(
                    $this->l('Insufficient credits: this job needs %1$s credits but you have %2$s. Top up at fotohub.app/dashboard or reduce the selection.'),
                    (string) ($estimate['total_credits'] ?? '?'),
                    (string) ($estimate['available_credits'] ?? '?')
                );
                return;
            }

            $this->confirmations[] = sprintf(
                $this->l('Estimated cost: %1$d item(s) = %2$s credits (you have %3$s).'),
                $itemCount,
                (string) ($estimate['total_credits'] ?? '?'),
                (string) ($estimate['available_credits'] ?? '?')
            );
        } catch (Exception $e) {
            // Estimation failure is not fatal — the job submit enforces 402 anyway
            $this->warnings[] = $this->l('Cost estimation unavailable: ') . $e->getMessage();
        }

        // Submit the bridge job
        try {
            $response = $processor->submitBridgeJob($productIds, $kind, $options, $model, $presetSlug, $perVariant);

            $this->confirmations[] = sprintf(
                $this->l('Job submitted (ID: %1$s, %2$d items, ~%3$s credits). Progress updates below; results will appear in Drafts Review.'),
                (string) ($response['job_id'] ?? '?'),
                (int) ($response['total_items'] ?? $itemCount),
                (string) ($response['estimated_credits'] ?? '?')
            );
        } catch (FotoHubInsufficientFundsException $e) {
            $this->errors[] = sprintf(
                $this->l('Insufficient funds: $%1$s required, $%2$s available. Top up at fotohub.app/console.'),
                number_format($e->requiredUsd, 2),
                number_format($e->availableUsd, 2)
            );
        } catch (Exception $e) {
            $this->errors[] = $this->l('Job submission failed: ') . $e->getMessage();
        }
    }

    /**
     * Verify the admin CSRF token for the current request
     */
    private function verifyRequestToken(): bool
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
     * Allowed aspect ratios (anything else is dropped rather than forwarded)
     */
    private const ASPECT_RATIOS = ['1:1', '4:3', '3:4', '16:9', '9:16'];

    /**
     * Collect job options from the submit form (feature 8: tone, language and
     * field checkboxes; plus image options). Every value is validated against
     * the contract enum — unknown values are dropped, never forwarded.
     */
    private function collectJobOptions(string $kind): array
    {
        $options = [];

        $language = (string) Tools::getValue('fh_language');
        if (in_array($language, FotoHubBridgeClient::LANGUAGES, true)) {
            $options['language'] = $language;
        }

        $tone = (string) Tools::getValue('fh_tone');
        if (in_array($tone, FotoHubBridgeClient::TONES, true)) {
            $options['tone'] = $tone;
        }

        $aspectRatio = (string) Tools::getValue('fh_aspect_ratio');
        if (in_array($aspectRatio, self::ASPECT_RATIOS, true)) {
            $options['aspect_ratio'] = $aspectRatio;
        }

        $numImages = (int) Tools::getValue('fh_num_images');
        if ($numImages >= 1 && $numImages <= 4) {
            $options['num_images'] = $numImages;
        }

        // Field checkboxes for the text kinds (feature 8)
        if (in_array($kind, ['description', 'alt_text', 'complete_listing'], true)) {
            $rawFields = Tools::getValue('fh_fields');
            $fields = [];

            if (is_array($rawFields)) {
                foreach ($rawFields as $field) {
                    if (in_array($field, FotoHubBridgeClient::TEXT_FIELDS, true)) {
                        $fields[] = (string) $field;
                    }
                }
            }

            if (!empty($fields)) {
                $options['fields'] = array_values(array_unique($fields));
            }
        }

        if ($kind === 'bg_replace') {
            $background = trim((string) Tools::getValue('fh_background'));
            if ($background !== '' && Validate::isCleanHtml($background)) {
                $options['background'] = $background;
            }
        }

        if ($kind === 'recolor') {
            $recolorPrompt = trim((string) Tools::getValue('fh_recolor_prompt'));
            $targetObject = trim((string) Tools::getValue('fh_target_object'));

            if ($recolorPrompt !== '' && Validate::isCleanHtml($recolorPrompt)) {
                $options['recolor_prompt'] = $recolorPrompt;
            }

            if ($targetObject !== '' && Validate::isCleanHtml($targetObject)) {
                $options['target_object'] = $targetObject;
            }
        }

        $brandRules = trim((string) Tools::getValue('fh_brand_rules'));
        if ($brandRules !== '' && Validate::isCleanHtml($brandRules)) {
            $options['brand_rules'] = $brandRules;
        }

        return $options;
    }

    /**
     * Local fallback: run the original resumable synchronous pipeline for
     * the kinds it supports.
     */
    private function runLocalFallback(FotoHubBridgeClient $client, array $productIds, string $kind, array $options): void
    {
        $localActions = [
            'image_generate' => 'generate',
            'bg_remove' => 'remove_background',
            'bg_replace' => 'replace_background',
            'upscale' => 'upscale',
            'description' => 'copywrite',
            'complete_listing' => 'pipeline',
        ];

        if (!isset($localActions[$kind])) {
            $this->errors[] = $this->l('This operation requires a bridge connection. Re-save your API key in Configuration to register one.');
            return;
        }

        $this->warnings[] = $this->l('No bridge connection — running in local mode. Re-save your API key in Configuration to enable bridge jobs.');

        $processor = new FotoHubBulkProcessor($client, $this->context->language->id);

        $options['model'] = Tools::getValue('fh_model') ?: Configuration::get('FOTOHUBAI_DEFAULT_MODEL');
        $options['width'] = (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH');
        $options['height'] = (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT');

        $batchId = uniqid('local_', true);
        $results = $processor->resumeBatch($batchId, $productIds, $localActions[$kind], $options);
        $summary = $processor->getSummary();

        if ($summary['success'] > 0) {
            $this->confirmations[] = sprintf(
                $this->l('%d product(s) processed. Results are waiting in Drafts Review.'),
                $summary['success']
            );
        }

        if ($summary['error'] > 0) {
            $this->errors[] = sprintf(
                $this->l('%d product(s) failed. Check the results below for details.'),
                $summary['error']
            );
        }

        if ($summary['skipped'] > 0) {
            $this->warnings[] = sprintf(
                $this->l('%d product(s) skipped (no images to process).'),
                $summary['skipped']
            );
        }

        $this->context->smarty->assign([
            'fotohub_bulk_results' => $results,
            'fotohub_bulk_summary' => $summary,
            'fotohub_bulk_action' => $kind,
        ]);
    }

    /**
     * Retry only the failed items of a bridge job (feature 6)
     */
    private function processRetryFailed($jobId): void
    {
        $jobId = $this->sanitizeJobId($jobId);

        if ($jobId === '') {
            $this->errors[] = $this->l('No job selected.');
            return;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->errors[] = $this->l('API key not configured.');
            return;
        }

        try {
            $bridge = new FotoHubBridgeClient($apiKey);
            $result = $bridge->retryFailed($jobId);
            FotoHubBulkProcessor::rememberJob($jobId, 'retry');
            $this->confirmations[] = sprintf(
                $this->l('%d failed item(s) requeued.'),
                (int) ($result['requeued'] ?? 0)
            );
        } catch (Exception $e) {
            $this->errors[] = $this->l('Retry failed: ') . $e->getMessage();
        }
    }

    /**
     * Cancel a bridge job (feature 6)
     */
    private function processCancelJob($jobId): void
    {
        $jobId = $this->sanitizeJobId($jobId);

        if ($jobId === '') {
            $this->errors[] = $this->l('No job selected.');
            return;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->errors[] = $this->l('API key not configured.');
            return;
        }

        try {
            $bridge = new FotoHubBridgeClient($apiKey);
            $bridge->cancelJob($jobId);
            FotoHubBulkProcessor::forgetJob($jobId);
            $this->confirmations[] = $this->l('Job cancelled.');
        } catch (Exception $e) {
            $this->errors[] = $this->l('Cancel failed: ') . $e->getMessage();
        }
    }

    /**
     * AJAX router (progress polling, estimation, single-item processing).
     *
     * Every branch spends credits or exposes account data, so the admin token
     * is verified before dispatch — an authenticated employee session alone is
     * not enough (that would leave these endpoints open to CSRF).
     */
    public function postProcess(): void
    {
        if (Tools::getValue('ajax')) {
            $action = (string) Tools::getValue('fh_action');
            $legacyAction = (string) Tools::getValue('action');

            $isFotohubAjax = in_array($action, ['job_status', 'job_items', 'estimate'], true)
                || $legacyAction === 'ProcessGenerateSingle';

            if ($isFotohubAjax) {
                if (!$this->verifyRequestToken()) {
                    $this->respondJson(['error' => 'Invalid security token'], 403);
                }

                if (!$this->canEdit() && $legacyAction === 'ProcessGenerateSingle') {
                    $this->respondJson(['error' => 'Insufficient permission'], 403);
                }

                if ($action === 'job_status') {
                    $this->ajaxJobStatus();
                } elseif ($action === 'job_items') {
                    $this->ajaxJobItems();
                } elseif ($action === 'estimate') {
                    $this->ajaxEstimate();
                } else {
                    $this->ajaxProcessGenerateSingle();
                }
            }
        }

        parent::postProcess();
    }

    /**
     * Emit a JSON response and stop
     *
     * @param array $payload Response body
     * @param int $httpCode HTTP status code
     */
    private function respondJson(array $payload, int $httpCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo json_encode($payload);
        exit;
    }

    /**
     * Validate a bridge job ID before putting it in a URL path
     */
    private function sanitizeJobId($jobId): string
    {
        $jobId = (string) $jobId;

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $jobId) ? $jobId : '';
    }

    /**
     * AJAX: bridge job status for the progress UI (feature 6)
     */
    private function ajaxJobStatus(): void
    {
        $jobId = $this->sanitizeJobId(Tools::getValue('job_id'));
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if ($jobId === '' || empty($apiKey)) {
            $this->respondJson(['error' => 'Missing job_id or API key'], 400);
        }

        try {
            $bridge = new FotoHubBridgeClient($apiKey);
            $this->respondJson(['success' => true, 'job' => $bridge->getJob($jobId)]);
        } catch (Exception $e) {
            $this->respondJson(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * AJAX: per-item table for the progress UI (feature 6)
     */
    private function ajaxJobItems(): void
    {
        $jobId = $this->sanitizeJobId(Tools::getValue('job_id'));
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if ($jobId === '' || empty($apiKey)) {
            $this->respondJson(['error' => 'Missing job_id or API key'], 400);
        }

        $status = (string) Tools::getValue('status');
        $allowedStatuses = ['queued', 'processing', 'completed', 'failed', 'skipped', 'cancelled'];
        $status = in_array($status, $allowedStatuses, true) ? $status : null;

        $limit = min(200, max(1, (int) Tools::getValue('limit', 100)));
        $offset = max(0, (int) Tools::getValue('offset', 0));

        try {
            $bridge = new FotoHubBridgeClient($apiKey);
            $this->respondJson([
                'success' => true,
                'items' => $bridge->getJobItems($jobId, $status, $limit, $offset),
            ]);
        } catch (Exception $e) {
            $this->respondJson(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * AJAX: cost estimate for the preflight box (feature 4)
     */
    private function ajaxEstimate(): void
    {
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->respondJson(['error' => 'API key not configured'], 400);
        }

        $kind = (string) Tools::getValue('kind', 'image_generate');

        if (!in_array($kind, FotoHubBridgeClient::JOB_KINDS, true)) {
            $this->respondJson(['error' => 'Invalid job kind'], 400);
        }

        $numItems = max(1, (int) Tools::getValue('num_items', 1));

        $model = (string) Tools::getValue('model');
        $model = isset(FotoHubBridgeClient::IMAGE_MODELS[$model]) ? $model : null;

        $numImages = (int) Tools::getValue('num_images', 1);
        $options = ($numImages > 1 && $numImages <= 4) ? ['num_images' => $numImages] : [];

        try {
            $bridge = new FotoHubBridgeClient($apiKey);
            $this->respondJson([
                'success' => true,
                'estimate' => $bridge->estimate($kind, $numItems, $options, $model),
            ]);
        } catch (Exception $e) {
            $this->respondJson(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * AJAX: Process single product locally (for progress-based UI fallback)
     */
    public function ajaxProcessGenerateSingle(): void
    {
        $idProduct = (int) Tools::getValue('id_product');

        if ($idProduct <= 0 || !Validate::isUnsignedId($idProduct)) {
            $this->respondJson(['error' => 'No product ID'], 400);
        }

        $action = (string) Tools::getValue('bulk_action', 'generate');
        $allowedActions = ['generate', 'remove_background', 'replace_background', 'upscale', 'copywrite', 'pipeline'];

        if (!in_array($action, $allowedActions, true)) {
            $this->respondJson(['error' => 'Invalid action'], 400);
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->respondJson(['error' => 'API key not configured'], 400);
        }

        $client = new FotoHubApiClient($apiKey);
        $processor = new FotoHubBulkProcessor($client, (int) $this->context->language->id);

        $options = [
            'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
            'width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
            'height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
        ];

        try {
            $results = $processor->processBatch([$idProduct], $action, $options);
        } catch (Exception $e) {
            $this->respondJson(['error' => $e->getMessage()], 502);
        }

        $this->respondJson([
            'success' => !empty($results) && $results[0]['status'] === 'success',
            'result' => $results[0] ?? null,
        ]);
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
}
