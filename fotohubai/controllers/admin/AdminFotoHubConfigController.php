<?php
/**
 * FOTOhub AI Configuration Controller
 *
 * Admin controller for module settings: API key, default model, dimensions.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

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

        // Get available models for the dropdown
        $models = $this->getAvailableModels();

        $this->context->smarty->assign([
            'fotohub_api_key_set' => !empty(Configuration::get('FOTOHUBAI_API_KEY')),
            'fotohub_default_model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
            'fotohub_default_width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
            'fotohub_default_height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
            'fotohub_auto_generate' => (int) Configuration::get('FOTOHUBAI_AUTO_GENERATE'),
            'fotohub_models' => $models,
            'fotohub_config_url' => $this->context->link->getAdminLink('AdminFotoHubConfig'),
            'fotohub_bulk_url' => $this->context->link->getAdminLink('AdminFotoHubBulk'),
            'fotohub_module_version' => $this->module->version,
        ]);

        $this->setTemplate('configure.tpl');
    }

    /**
     * Process configuration form submission
     */
    private function processConfiguration(): void
    {
        $apiKey = Tools::getValue('FOTOHUBAI_API_KEY');
        $defaultModel = Tools::getValue('FOTOHUBAI_DEFAULT_MODEL');
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

        // Store API key encrypted (only update if a new key was provided)
        if (!empty($apiKey) && $apiKey !== '••••••••') {
            $encrypted = FotoHubAi::encryptApiKey($apiKey);
            Configuration::updateValue('FOTOHUBAI_API_KEY', $encrypted);
        }

        Configuration::updateValue('FOTOHUBAI_DEFAULT_MODEL', pSQL($defaultModel));
        Configuration::updateValue('FOTOHUBAI_DEFAULT_WIDTH', $defaultWidth);
        Configuration::updateValue('FOTOHUBAI_DEFAULT_HEIGHT', $defaultHeight);
        Configuration::updateValue('FOTOHUBAI_AUTO_GENERATE', $autoGenerate);

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
            $balance = $client->getBalance();
            $credits = $balance['credits'] ?? $balance['balance'] ?? 'unknown';
            $this->confirmations[] = $this->l('Connection successful! Your credit balance: ') . $credits;
        } catch (Exception $e) {
            $this->errors[] = $this->l('Connection failed: ') . $e->getMessage();
        }
    }

    /**
     * Handle AJAX requests (generate image from product page)
     */
    private function processAjax(): void
    {
        $action = Tools::getValue('action');

        header('Content-Type: application/json');

        switch ($action) {
            case 'generate':
                $this->ajaxGenerate();
                break;
            case 'test':
                $this->ajaxTestConnection();
                break;
            default:
                echo json_encode(['error' => 'Unknown action']);
        }

        exit;
    }

    /**
     * AJAX: Generate image for a product
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
                $saved = $module->addImageToProduct($idProduct, $result['image_url']);
                echo json_encode([
                    'success' => true,
                    'image_url' => $result['image_url'],
                    'saved' => $saved,
                    'message' => $saved ? 'Image generated and added to product' : 'Image generated but could not be saved',
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
            $balance = $client->getBalance();
            echo json_encode([
                'success' => true,
                'credits' => $balance['credits'] ?? $balance['balance'] ?? 0,
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get available models list
     */
    private function getAvailableModels(): array
    {
        // Default models — can be refreshed via API
        return [
            ['id' => 'seedream-5-0-260128', 'name' => 'SeeDream 5.0 (Recommended)'],
            ['id' => 'flux-1-1-pro', 'name' => 'Flux 1.1 Pro'],
            ['id' => 'flux-1-1-pro-ultra', 'name' => 'Flux 1.1 Pro Ultra'],
            ['id' => 'ideogram-v3', 'name' => 'Ideogram v3'],
            ['id' => 'recraft-v3', 'name' => 'Recraft v3'],
            ['id' => 'stable-diffusion-xl', 'name' => 'Stable Diffusion XL'],
        ];
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
