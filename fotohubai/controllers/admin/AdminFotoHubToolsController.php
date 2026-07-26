<?php
/**
 * FOTOhub AI Creative Tools Controller
 *
 * Admin controller for Stability AI creative tools (remove background, upscale, etc).
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubApiClient.php';
require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubStabilityTools.php';

class AdminFotoHubToolsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = $this->l('FOTOhub AI — Creative Tools');
    }

    /**
     * Initialize page content
     */
    public function initContent(): void
    {
        parent::initContent();

        // Check if API key is configured
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->warnings[] = $this->l('Please configure your FOTOhub API key first.')
                . ' <a href="' . $this->context->link->getAdminLink('AdminFotoHubConfig') . '">'
                . $this->l('Go to Configuration') . '</a>';
        }

        // Handle AJAX requests. Every branch spends credits and writes to a
        // product, so the admin token (CSRF) and the controller's edit
        // permission are both required — a logged-in session is not enough.
        if (Tools::getValue('ajax') && Tools::getValue('action')) {
            $action = Tools::getValue('action');

            if (!$this->verifyRequestToken()) {
                $this->respondJson(['error' => 'Invalid security token'], 403);
            }

            if (!$this->canEdit()) {
                $this->respondJson(['error' => 'Insufficient permission'], 403);
            }

            switch ($action) {
                case 'processTool':
                    $this->ajaxProcessProcessTool();
                    break;
                case 'saveToProduct':
                    $this->ajaxProcessSaveToProduct();
                    break;
                default:
                    $this->respondJson(['error' => 'Unknown action'], 400);
            }

            return;
        }

        // Get available tools
        // getAvailableTools() is an instance method
        $tools = !empty($apiKey)
            ? (new FotoHubStabilityTools(new FotoHubApiClient($apiKey), (int) $this->context->language->id))
                ->getAvailableTools()
            : [];

        // Get product list for dropdown
        $products = Product::getProducts(
            $this->context->language->id,
            0,
            0,
            'id_product',
            'ASC',
            false,
            true
        );

        $productList = [];
        foreach ($products as $product) {
            $productList[] = [
                'id_product' => $product['id_product'],
                'name' => $product['name'],
            ];
        }

        $this->context->smarty->assign([
            'fotohub_tools' => $tools,
            'fotohub_products' => $productList,
            'fotohub_tools_url' => $this->context->link->getAdminLink('AdminFotoHubTools'),
            // The AJAX endpoints verify this token explicitly (CSRF).
            'fotohub_token' => $this->token,
            'fotohub_can_edit' => $this->canEdit(),
            'fotohub_drafts_url' => $this->context->link->getAdminLink('AdminFotohubDrafts'),
        ]);

        $this->setTemplate('tools.tpl');
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
     * AJAX: Process a creative tool on an image
     */
    public function ajaxProcessProcessTool(): void
    {
        header('Content-Type: application/json');

        $idProduct = (int) Tools::getValue('id_product');
        $toolId = Tools::getValue('tool_id');
        $image = Tools::getValue('image');
        $mask = Tools::getValue('mask');
        $prompt = Tools::getValue('prompt', '');
        $options = Tools::getValue('options', '{}');

        if (empty($toolId)) {
            echo json_encode(['error' => 'No tool specified']);
            exit;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            exit;
        }

        // Parse options JSON
        $parsedOptions = json_decode($options, true) ?: [];

        if (!empty($prompt)) {
            $parsedOptions['prompt'] = $prompt;
        }

        if (!empty($mask)) {
            $parsedOptions['mask'] = $mask;
        }

        try {
            $stabilityTools = new FotoHubStabilityTools(new FotoHubApiClient($apiKey), (int) $this->context->language->id);

            if ($idProduct) {
                // Process product image
                $result = $stabilityTools->processProductImage($idProduct, $toolId, $parsedOptions);
            } elseif (!empty($image)) {
                // Process uploaded image — save base64 to tmp file
                $tmpFile = tempnam(sys_get_temp_dir(), 'fotohub_');
                $imageData = base64_decode($image);
                file_put_contents($tmpFile, $imageData);

                try {
                    $result = $stabilityTools->processFromUpload($tmpFile, $toolId, $parsedOptions);
                } finally {
                    @unlink($tmpFile);
                }
            } else {
                echo json_encode(['error' => 'No product or image provided']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'result_url' => $result['image_url'] ?? null,
                'result_base64' => $result['base64'] ?? null,
                'draft_id' => $result['draft_id'] ?? null,
                'drafts_url' => $this->context->link->getAdminLink('AdminFotohubDrafts'),
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * AJAX: queue a processed image as a pending draft on a product.
     *
     * DRAFT-FIRST: this never writes to the live product. Approval in
     * AdminFotohubDrafts is the only path from an AI result to live data.
     */
    public function ajaxProcessSaveToProduct(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        $imageUrl = (string) Tools::getValue('image_url');

        if ($idProduct <= 0 || !Validate::isUnsignedId($idProduct)) {
            $this->respondJson(['error' => 'No product ID provided'], 400);
        }

        if ($imageUrl === '') {
            $this->respondJson(['error' => 'No image provided'], 400);
        }

        // Only http(s) URLs and data: URIs are accepted, so a crafted value
        // cannot turn the write-back into a local file read.
        if (!preg_match('#^(https?://|data:image/)#i', $imageUrl)) {
            $this->respondJson(['error' => 'Unsupported image source'], 400);
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->respondJson(['error' => 'API key not configured'], 400);
        }

        $tools = new FotoHubStabilityTools(
            new FotoHubApiClient($apiKey),
            (int) $this->context->language->id
        );

        $idDraft = $tools->queueResultAsDraft($idProduct, $imageUrl);

        if ($idDraft <= 0) {
            $this->respondJson(['error' => 'Could not store the draft'], 500);
        }

        $this->respondJson([
            'success' => true,
            'draft_id' => $idDraft,
            'drafts_url' => $this->context->link->getAdminLink('AdminFotohubDrafts'),
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
