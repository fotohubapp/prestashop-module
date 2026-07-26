<?php
/**
 * FOTOhub AI Configuration Controller
 *
 * Connection wizard (API key → balance validation → bridge registration),
 * module settings, credits meter, connection health check, and MCP help tab.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubBridgeClient.php';

class AdminFotoHubConfigController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->className = 'Configuration';
        $this->table = 'configuration';

        parent::__construct();

        $this->meta_title = $this->l('FOTOhub AI — Configuration');
    }

    /**
     * Render the configuration page
     */
    public function renderForm(): string
    {
        return '';
    }

    /**
     * Initialize page content
     */
    public function initContent(): void
    {
        parent::initContent();

        // Handle AJAX requests
        if (Tools::getValue('ajax') && Tools::getValue('action')) {
            $this->processAjax();
            return;
        }

        // Handle form submission
        if (Tools::isSubmit('submitFotoHubConfig')) {
            $this->processConfiguration();
        }

        if (Tools::isSubmit('testConnection')) {
            $this->processTestConnection();
        }

        if (Tools::isSubmit('healthCheck')) {
            $this->processHealthCheck();
        }

        // Documented configure vars + credits meter
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();
        $configured = !empty($apiKey);
        $credits = null;
        $plan = null;

        if ($configured) {
            try {
                $client = new FotoHubApiClient($apiKey);
                $balance = $client->getBalance();
                $credits = $client->getCreditsAvailable();
                $plan = $balance['tier'] ?? null;
            } catch (Exception $e) {
                // Balance unavailable — page still renders
            }
        }

        $this->context->smarty->assign([
            // Documented configure Smarty vars
            'fotohub_configured' => $configured,
            'fotohub_credits' => $credits,
            'fotohub_plan' => $plan,
            // Credits meter / low balance warning (feature 9)
            'fotohub_low_balance' => ($credits !== null && $credits < 50),
            // Connection wizard state
            'fotohub_api_key_set' => $configured,
            'fotohub_connection_id' => FotoHubBridgeClient::getStoredConnectionId(),
            // Settings
            'fotohub_default_model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
            'fotohub_default_width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
            'fotohub_default_height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
            'fotohub_auto_generate' => (int) Configuration::get('FOTOHUBAI_AUTO_GENERATE'),
            'fotohub_models' => $this->getAvailableModels(),
            'fotohub_config_url' => $this->context->link->getAdminLink('AdminFotoHubConfig'),
            'fotohub_bulk_url' => $this->context->link->getAdminLink('AdminFotoHubBulk'),
            'fotohub_drafts_url' => $this->context->link->getAdminLink('AdminFotohubDrafts'),
            'fotohub_module_version' => $this->module->version,
            // MCP help tab (feature 10)
            'fotohub_mcp_url' => 'https://apis.fotohub.app/mcp/',
            // The AJAX endpoints verify this token explicitly (CSRF).
            'fotohub_token' => $this->token,
        ]);

        $this->setTemplate('configure.tpl');
    }

    /**
     * Process configuration form submission.
     *
     * Connection wizard flow: when a new API key is entered, validate it via
     * GET /v1/billing/balance, then register the store as a commerce-bridge
     * connection and persist connection_id + callback_secret. The key itself
     * is stored AES-256-CBC encrypted with an explicit encryption flag.
     */
    private function processConfiguration(): void
    {
        $apiKey = Tools::getValue('FOTOHUBAI_API_KEY');
        $defaultModel = Tools::getValue('FOTOHUBAI_DEFAULT_MODEL');
        $defaultPreset = Tools::getValue('FOTOHUBAI_DEFAULT_PRESET');
        $defaultWidth = (int) Tools::getValue('FOTOHUBAI_DEFAULT_WIDTH');
        $defaultHeight = (int) Tools::getValue('FOTOHUBAI_DEFAULT_HEIGHT');
        $autoGenerate = (int) Tools::getValue('FOTOHUBAI_AUTO_GENERATE');

        // Validate dimensions
        if ($defaultWidth < 256 || $defaultWidth > 4096) {
            $this->errors[] = $this->l('Width must be between 256 and 4096 pixels.');
            return;
        }

        if ($defaultHeight < 256 || $defaultHeight > 4096) {
            $this->errors[] = $this->l('Height must be between 256 and 4096 pixels.');
            return;
        }

        // Connection wizard: only when a new key was actually provided
        if (!empty($apiKey) && $apiKey !== '••••••••') {
            // Step 1: validate the key against the live API
            $client = new FotoHubApiClient($apiKey);

            try {
                $client->getBalance();
            } catch (Exception $e) {
                $this->errors[] = $this->l('API key validation failed: ') . $e->getMessage();
                return;
            }

            // Step 2: store the key encrypted with the explicit flag
            if (!FotoHubAi::storeApiKey($apiKey)) {
                $this->errors[] = $this->l('Failed to store the API key.');
                return;
            }

            // Step 3: register the bridge connection (idempotent)
            try {
                $bridge = new FotoHubBridgeClient($apiKey);
                $shopUrl = rtrim(Configuration::get('PS_SSL_ENABLED')
                    ? Tools::getShopDomainSsl(true)
                    : Tools::getShopDomain(true), '/');
                $shopName = (string) Configuration::get('PS_SHOP_NAME');
                $callbackUrl = $shopUrl . '/module/fotohubai/webhook';

                $bridge->ensureConnection($shopUrl, $shopName ?: 'PrestaShop store', $callbackUrl);
                $this->confirmations[] = $this->l('Store connected to FOTOhub commerce-bridge.');
            } catch (Exception $e) {
                // Not fatal: direct API ops still work without a bridge connection
                $this->warnings[] = $this->l('API key saved, but bridge registration failed: ') . $e->getMessage();
            }
        }

        Configuration::updateValue('FOTOHUBAI_DEFAULT_MODEL', pSQL($defaultModel));
        Configuration::updateValue('FOTOHUBAI_DEFAULT_WIDTH', $defaultWidth);
        Configuration::updateValue('FOTOHUBAI_DEFAULT_HEIGHT', $defaultHeight);
        Configuration::updateValue('FOTOHUBAI_AUTO_GENERATE', $autoGenerate);

        if ($defaultPreset !== false) {
            Configuration::updateValue('FOTOHUBAI_DEFAULT_PRESET', pSQL((string) $defaultPreset));
        }

        $this->confirmations[] = $this->l('Settings saved successfully.');
    }

    /**
     * Process test connection request
     */
    private function processTestConnection(): void
    {
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->errors[] = $this->l('Please save your API key first.');
            return;
        }

        $client = new FotoHubApiClient($apiKey);

        try {
            $credits = $client->getCreditsAvailable();
            $this->confirmations[] = $this->l('Connection successful! Available credits: ') . $credits;
        } catch (Exception $e) {
            $this->errors[] = $this->l('Connection failed: ') . $e->getMessage();
        }
    }

    /**
     * Connection health check: bridge GET /health + balance call (feature 11)
     */
    private function processHealthCheck(): void
    {
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->errors[] = $this->l('Please save your API key first.');
            return;
        }

        $bridge = new FotoHubBridgeClient($apiKey);
        $status = $bridge->healthCheck();

        if ($status['healthy']) {
            $credits = 0.0;
            if (!empty($status['balance'])) {
                $credits = (float) ($status['balance']['credits']['remaining_period']
                    ?? $status['balance']['credits_available'] ?? 0);
            }
            $this->confirmations[] = $this->l('Connection healthy. Bridge reachable, balance OK. Available credits: ') . $credits;
        } else {
            $this->errors[] = $this->l('Health check failed: ') . ($status['error'] ?? 'unknown');
        }
    }

    /**
     * Handle AJAX requests (generate image from product page).
     *
     * Every branch either spends credits or exposes account data, so the admin
     * token (CSRF) is verified first and generation additionally requires the
     * controller's edit permission.
     */
    private function processAjax(): void
    {
        $action = Tools::getValue('action');

        header('Content-Type: application/json');

        if (!$this->verifyRequestToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }

        switch ($action) {
            case 'generate':
                if (!$this->canEdit()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Insufficient permission']);
                    exit;
                }
                $this->ajaxGenerate();
                break;
            case 'test':
                $this->ajaxTestConnection();
                break;
            case 'balance':
                $this->ajaxBalance();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);
        }

        exit;
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
     * AJAX: Generate image for a product (result lands as a pending draft)
     */
    private function ajaxGenerate(): void
    {
        $idProduct = (int) Tools::getValue('id_product');
        $customPrompt = Tools::getValue('prompt', '');

        if (!$idProduct) {
            echo json_encode(['error' => 'No product ID provided']);
            return;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            return;
        }

        $client = new FotoHubApiClient($apiKey);
        $product = new Product($idProduct, false, $this->context->language->id);

        // Use custom prompt if provided, otherwise build from product
        $prompt = !empty($customPrompt) ? $customPrompt : $module->buildPromptFromProduct($product);

        try {
            $result = $client->generateImage($prompt, [
                'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
                'width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
                'height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
            ]);

            if (!empty($result['image_url'])) {
                // DRAFT-FIRST: pending review, not written to the live product
                $idDraft = FotoHubDraft::add($idProduct, FotoHubDraft::TYPE_IMAGE, [
                    'image_urls' => [$result['image_url']],
                ], null, 'image_generate');

                echo json_encode([
                    'success' => true,
                    'image_url' => $result['image_url'],
                    'draft_id' => $idDraft,
                    'message' => 'Image generated — review it in Drafts Review before it goes live',
                    'drafts_url' => $this->context->link->getAdminLink('AdminFotohubDrafts'),
                ]);
            } else {
                echo json_encode(['error' => 'No image returned from API']);
            }
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Test connection
     */
    private function ajaxTestConnection(): void
    {
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            return;
        }

        $client = new FotoHubApiClient($apiKey);

        try {
            echo json_encode([
                'success' => true,
                'credits' => $client->getCreditsAvailable(),
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Credits meter refresh
     */
    private function ajaxBalance(): void
    {
        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            return;
        }

        try {
            $client = new FotoHubApiClient($apiKey);
            $credits = $client->getCreditsAvailable();
            echo json_encode([
                'success' => true,
                'credits' => $credits,
                'low_balance' => $credits < 50,
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get available image models (mirrors FotoHubBridgeClient::IMAGE_MODELS)
     */
    private function getAvailableModels(): array
    {
        $models = [];

        foreach (FotoHubBridgeClient::IMAGE_MODELS as $id => $meta) {
            $models[] = [
                'id' => $id,
                'name' => $meta['name'],
                'credits' => $meta['credits'],
            ];
        }

        return $models;
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
