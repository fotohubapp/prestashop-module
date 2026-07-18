<?php
/**
 * FOTOhub AI Bulk Processing Controller
 *
 * Admin controller for bulk image operations on multiple products.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

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
            'active' => [
                'title' => $this->l('Active'),
                'active' => 'status',
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-sm',
            ],
        ];

        // Bulk actions
        $this->bulk_actions = [
            'generateImages' => [
                'text' => $this->l('Generate AI Images'),
                'icon' => 'icon-magic',
                'confirm' => $this->l('Generate AI product photos for selected products?'),
            ],
            'removeBackgrounds' => [
                'text' => $this->l('Remove Backgrounds'),
                'icon' => 'icon-scissors',
                'confirm' => $this->l('Remove backgrounds from selected products\' images?'),
            ],
            'upscaleImages' => [
                'text' => $this->l('Upscale Images (2x)'),
                'icon' => 'icon-resize-full',
                'confirm' => $this->l('Upscale images for selected products?'),
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

        // Show results if we just processed a batch
        if (Tools::getValue('bulk_results')) {
            $results = json_decode(base64_decode(Tools::getValue('bulk_results')), true);
            if (!empty($results)) {
                $this->context->smarty->assign('fotohub_bulk_results', $results);
            }
        }

        parent::initContent();
    }

    /**
     * Build the product list SQL query
     */
    public function renderList(): string|false
    {
        $this->addRowAction('view');

        $this->_select = 'cl.`name` as category, image_shop.`id_image` as id_image';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON (cl.`id_category` = a.`id_category_default`
                AND cl.`id_lang` = ' . (int) $this->context->language->id . '
                AND cl.`id_shop` = ' . (int) $this->context->shop->id . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` image_shop
                ON (image_shop.`id_product` = a.`id_product`
                AND image_shop.`cover` = 1
                AND image_shop.`id_shop` = ' . (int) $this->context->shop->id . ')';

        return parent::renderList();
    }

    /**
     * Bulk action: Generate AI images
     */
    protected function processBulkGenerateImages(): void
    {
        $this->processBulkAction('generate');
    }

    /**
     * Bulk action: Remove backgrounds
     */
    protected function processBulkRemoveBackgrounds(): void
    {
        $this->processBulkAction('remove_background');
    }

    /**
     * Bulk action: Upscale images
     */
    protected function processBulkUpscaleImages(): void
    {
        $this->processBulkAction('upscale');
    }

    /**
     * Process a bulk action
     */
    private function processBulkAction(string $action): void
    {
        $productIds = $this->boxes;

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

        $client = new FotoHubApiClient($apiKey);
        $processor = new FotoHubBulkProcessor($client, $this->context->language->id);

        $options = [
            'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
            'width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
            'height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
        ];

        $results = $processor->processBatch($productIds, $action, $options);
        $summary = $processor->getSummary();

        // Store results for display
        $encodedResults = base64_encode(json_encode([
            'results' => $results,
            'summary' => $summary,
            'action' => $action,
        ]));

        // Show summary in confirmations/errors
        if ($summary['success'] > 0) {
            $this->confirmations[] = sprintf(
                $this->l('%d product(s) processed successfully.'),
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

        // Assign results to Smarty for detailed display
        $this->context->smarty->assign([
            'fotohub_bulk_results' => $results,
            'fotohub_bulk_summary' => $summary,
            'fotohub_bulk_action' => $action,
        ]);
    }

    /**
     * AJAX: Process single product (for progress-based UI)
     */
    public function ajaxProcessGenerateSingle(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        $action = Tools::getValue('bulk_action', 'generate');

        header('Content-Type: application/json');

        if (!$idProduct) {
            echo json_encode(['error' => 'No product ID']);
            exit;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            exit;
        }

        $client = new FotoHubApiClient($apiKey);
        $processor = new FotoHubBulkProcessor($client, $this->context->language->id);

        $options = [
            'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
            'width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
            'height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
        ];

        $results = $processor->processBatch([$idProduct], $action, $options);

        echo json_encode([
            'success' => !empty($results) && $results[0]['status'] === 'success',
            'result' => $results[0] ?? null,
        ]);

        exit;
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
