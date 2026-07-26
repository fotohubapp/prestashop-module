<?php
/**
 * FOTOhub AI — Product Image Generation for PrestaShop 8+
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/FotoHubApiClient.php';
require_once dirname(__FILE__) . '/classes/FotoHubBridgeClient.php';
require_once dirname(__FILE__) . '/classes/FotoHubWriteback.php';
require_once dirname(__FILE__) . '/classes/FotoHubProductWriter.php';
require_once dirname(__FILE__) . '/classes/FotoHubDraft.php';
require_once dirname(__FILE__) . '/classes/FotoHubBulkProcessor.php';
require_once dirname(__FILE__) . '/classes/FotoHubVideoGenerator.php';
require_once dirname(__FILE__) . '/classes/FotoHubStabilityTools.php';
require_once dirname(__FILE__) . '/classes/FotoHubCopywriter.php';
require_once dirname(__FILE__) . '/classes/FotoHubAnalytics.php';
require_once dirname(__FILE__) . '/classes/FotoHubScheduler.php';

class FotoHubAi extends Module
{
    /** @var array Install error collection */
    public array $installErrors = [];

    public function __construct()
    {
        $this->name = 'fotohubai';
        $this->tab = 'administration';
        $this->version = '2.1.0';
        $this->author = 'FOTOhub';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FOTOhub AI — Creative Suite for PrestaShop');
        $this->description = $this->l('Complete AI creative toolkit: generate product photos & videos, remove backgrounds, upscale images, use 13 Stability AI tools, AI copywriting, draft-first review, and scheduled batch processing.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall FOTOhub AI? Your API key and settings will be removed.');
    }

    /**
     * Module installation — stepwise with error collection instead of one
     * opaque && chain. Every failed step is recorded in $this->_errors so
     * the merchant sees exactly what broke.
     */
    public function install(): bool
    {
        $this->installErrors = [];

        if (!parent::install()) {
            $this->installErrors[] = 'Core module registration failed';
            return false;
        }

        $steps = [
            'hook displayAdminProductsExtra' => fn () => $this->registerHook('displayAdminProductsExtra'),
            'hook actionAdminProductsControllerSaveAfter' => fn () => $this->registerHook('actionAdminProductsControllerSaveAfter'),
            'hook displayBackOfficeHeader' => fn () => $this->registerHook('displayBackOfficeHeader'),
            'hook actionProductAdd' => fn () => $this->registerHook('actionProductAdd'),
            'hook actionObjectCombinationAddAfter' => fn () => $this->registerHook('actionObjectCombinationAddAfter'),
            'hook displayAdminProductsMainStepLeftColumnMiddle' => fn () => $this->registerHook('displayAdminProductsMainStepLeftColumnMiddle'),
            'tab AdminFotoHubConfig' => fn () => $this->installTab('AdminFotoHubConfig', 'FOTOhub AI', 'AdminParentModulesSf'),
            'tab AdminFotoHubBulk' => fn () => $this->installTab('AdminFotoHubBulk', 'Bulk Processing', 'AdminFotoHubConfig'),
            'tab AdminFotohubDrafts' => fn () => $this->installTab('AdminFotohubDrafts', 'Drafts Review', 'AdminFotoHubConfig'),
            'tab AdminFotoHubVideo' => fn () => $this->installTab('AdminFotoHubVideo', 'Video Generation', 'AdminFotoHubConfig'),
            'tab AdminFotoHubTools' => fn () => $this->installTab('AdminFotoHubTools', 'Creative Tools', 'AdminFotoHubConfig'),
            'tab AdminFotoHubCopy' => fn () => $this->installTab('AdminFotoHubCopy', 'AI Copywriter', 'AdminFotoHubConfig'),
            'tab AdminFotoHubAnalytics' => fn () => $this->installTab('AdminFotoHubAnalytics', 'Analytics', 'AdminFotoHubConfig'),
            'table fotohub_analytics' => fn () => FotoHubAnalytics::install(),
            'table fotohub_schedule' => fn () => FotoHubScheduler::install(),
            'table fotohub_draft' => fn () => FotoHubDraft::install(),
        ];

        $configDefaults = [
            'FOTOHUBAI_API_KEY' => '',
            'FOTOHUBAI_KEY_ENCRYPTED' => 0,
            'FOTOHUBAI_DEFAULT_MODEL' => 'seedream-5-0-260128',
            'FOTOHUBAI_DEFAULT_WIDTH' => 1024,
            'FOTOHUBAI_DEFAULT_HEIGHT' => 1024,
            'FOTOHUBAI_AUTO_GENERATE' => 0,
            'FOTOHUBAI_DEFAULT_VIDEO_MODEL' => 'veo-3.1-fast-generate-001',
            'FOTOHUBAI_DEFAULT_CHAT_MODEL' => 'gemini-flash',
            'FOTOHUBAI_COPYWRITER_TONE' => 'professional',
            'FOTOHUBAI_COPYWRITER_LANGUAGE' => '',
            'FOTOHUBAI_SCHEDULER_BATCH_SIZE' => 5,
            'FOTOHUBAI_SCHEDULER_ENABLED' => 0,
            'FOTOHUBAI_AUTO_COPYWRITE' => 0,
            'FOTOHUBAI_DEFAULT_PRESET' => '',
            'FOTOHUBAI_BRIDGE_CONNECTION_ID' => '',
            'FOTOHUBAI_BRIDGE_CALLBACK_SECRET' => '',
            'FOTOHUBAI_BRIDGE_JOBS' => '',
            'FOTOHUBAI_PRESET_CACHE' => '',
        ];

        foreach ($configDefaults as $key => $value) {
            $steps['config ' . $key] = fn () => Configuration::updateValue($key, $value);
        }

        $success = true;

        foreach ($steps as $label => $step) {
            try {
                if (!$step()) {
                    $this->installErrors[] = $label;
                    $success = false;
                }
            } catch (Exception $e) {
                $this->installErrors[] = $label . ': ' . $e->getMessage();
                $success = false;
            }
        }

        if (!$success) {
            $this->_errors[] = $this->l('FOTOhub AI install completed with errors: ')
                . implode('; ', $this->installErrors);
        }

        return $success;
    }

    /**
     * Module uninstallation — stepwise, best effort, reports failures
     */
    public function uninstall(): bool
    {
        $success = parent::uninstall();

        $tabs = [
            'AdminFotoHubConfig', 'AdminFotoHubBulk', 'AdminFotohubDrafts',
            'AdminFotoHubVideo', 'AdminFotoHubTools', 'AdminFotoHubCopy',
            'AdminFotoHubAnalytics',
        ];

        foreach ($tabs as $tab) {
            if (!$this->uninstallTab($tab)) {
                $success = false;
            }
        }

        $configKeys = [
            'FOTOHUBAI_API_KEY', 'FOTOHUBAI_KEY_ENCRYPTED', 'FOTOHUBAI_DEFAULT_MODEL',
            'FOTOHUBAI_DEFAULT_WIDTH', 'FOTOHUBAI_DEFAULT_HEIGHT', 'FOTOHUBAI_AUTO_GENERATE',
            'FOTOHUBAI_DEFAULT_VIDEO_MODEL', 'FOTOHUBAI_DEFAULT_CHAT_MODEL',
            'FOTOHUBAI_COPYWRITER_TONE', 'FOTOHUBAI_COPYWRITER_LANGUAGE',
            'FOTOHUBAI_SCHEDULER_BATCH_SIZE', 'FOTOHUBAI_SCHEDULER_ENABLED',
            'FOTOHUBAI_AUTO_COPYWRITE', 'FOTOHUBAI_DEFAULT_PRESET',
            'FOTOHUBAI_BRIDGE_CONNECTION_ID', 'FOTOHUBAI_BRIDGE_CALLBACK_SECRET',
            'FOTOHUBAI_BRIDGE_JOBS', 'FOTOHUBAI_PRESET_CACHE',
        ];

        foreach ($configKeys as $key) {
            Configuration::deleteByName($key);
        }

        if (!FotoHubAnalytics::uninstall()) {
            $success = false;
        }

        if (!FotoHubScheduler::uninstall()) {
            $success = false;
        }

        if (!FotoHubDraft::uninstall()) {
            $success = false;
        }

        return $success;
    }

    /**
     * Install an admin tab
     */
    private function installTab(string $className, string $tabName, string $parent): bool
    {
        // Idempotent: skip if the tab already exists (e.g. upgrade re-run)
        if ((int) Tab::getIdFromClassName($className)) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $className;
        $tab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $tabName;
        }

        $tab->id_parent = (int) Tab::getIdFromClassName($parent);
        $tab->module = $this->name;

        return (bool) $tab->add();
    }

    /**
     * Uninstall an admin tab
     */
    private function uninstallTab(string $className): bool
    {
        $idTab = (int) Tab::getIdFromClassName($className);

        if ($idTab) {
            $tab = new Tab($idTab);
            return (bool) $tab->delete();
        }

        return true;
    }

    /**
     * Module configuration page redirect
     */
    public function getContent(): string
    {
        // Assign the documented configure-page Smarty vars before redirecting,
        // so themes/templates hooking the configure view can rely on them.
        $this->assignConfigureSmartyVars();

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminFotoHubConfig'));
        return '';
    }

    /**
     * Assign the three documented configure Smarty vars:
     * $fotohub_configured, $fotohub_credits, $fotohub_plan
     */
    public function assignConfigureSmartyVars(): void
    {
        $apiKey = $this->getDecryptedApiKey();
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
                // Leave nulls — page still renders
            }
        }

        $this->context->smarty->assign([
            'fotohub_configured' => $configured,
            'fotohub_credits' => $credits,
            'fotohub_plan' => $plan,
        ]);
    }

    /**
     * Hook: Display back office header — load CSS/JS
     */
    public function hookDisplayBackOfficeHeader(array $params): void
    {
        if ($this->context->controller instanceof AdminController) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
        }
    }

    /**
     * Hook: Display on product edit page — Generate AI Photo button
     */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $idProduct = (int) ($params['id_product'] ?? Tools::getValue('id_product'));

        if (!$idProduct) {
            return '';
        }

        $product = new Product($idProduct, false, $this->context->language->id);
        $apiKey = $this->getDecryptedApiKey();

        $this->context->smarty->assign([
            'fotohub_product_id' => $idProduct,
            'fotohub_product_name' => $product->name,
            'fotohub_has_api_key' => !empty($apiKey),
            'fotohub_default_model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
            'fotohub_default_width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
            'fotohub_default_height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
            // getAdminLink() already appends &token=<controller token>, which
            // AdminFotoHubConfigController::processAjax() verifies (CSRF).
            'fotohub_generate_url' => $this->context->link->getAdminLink('AdminFotoHubConfig') . '&ajax=1&action=generate',
            'fotohub_module_path' => $this->_path,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/product_tab.tpl');
    }

    /**
     * Hook: After product save — auto-generate if enabled (draft-first)
     */
    public function hookActionAdminProductsControllerSaveAfter(array $params): void
    {
        if (!Configuration::get('FOTOHUBAI_AUTO_GENERATE')) {
            return;
        }

        $idProduct = (int) Tools::getValue('id_product');

        if (!$idProduct) {
            return;
        }

        $product = new Product($idProduct, false, $this->context->language->id);
        $images = Image::getImages($this->context->language->id, $idProduct);

        // Only auto-generate if product has no images
        if (!empty($images)) {
            return;
        }

        $apiKey = $this->getDecryptedApiKey();

        if (empty($apiKey)) {
            return;
        }

        $client = new FotoHubApiClient($apiKey);
        $prompt = $this->buildPromptFromProduct($product);

        try {
            $result = $client->generateImage($prompt, [
                'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
                'width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
                'height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
            ]);

            if (!empty($result['image_url'])) {
                // DRAFT-FIRST: never write to the live product silently
                FotoHubDraft::add($idProduct, FotoHubDraft::TYPE_IMAGE, [
                    'image_urls' => [$result['image_url']],
                ], null, 'image_generate');
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'FOTOhub AI auto-generate failed: ' . $e->getMessage(),
                3,
                null,
                'Product',
                $idProduct
            );
        }
    }

    /**
     * Build a generation prompt from product data
     */
    public function buildPromptFromProduct(Product $product): string
    {
        $parts = [];

        if (!empty($product->name)) {
            $parts[] = is_array($product->name) ? reset($product->name) : $product->name;
        }

        if (!empty($product->description_short)) {
            $shortDesc = is_array($product->description_short)
                ? reset($product->description_short)
                : $product->description_short;
            $parts[] = strip_tags((string) $shortDesc);
        }

        $categories = $product->getCategories();
        if (!empty($categories)) {
            $categoryNames = [];
            foreach (array_slice($categories, 0, 3) as $idCategory) {
                $category = new Category((int) $idCategory, $this->context->language->id);
                if (!empty($category->name)) {
                    $categoryNames[] = $category->name;
                }
            }
            if (!empty($categoryNames)) {
                $parts[] = 'Category: ' . implode(', ', $categoryNames);
            }
        }

        $prompt = 'Professional product photo of: ' . implode('. ', $parts);
        $prompt .= '. Clean white background, studio lighting, high quality e-commerce product photography.';

        return $prompt;
    }

    /**
     * Download and add an image to a product (delegates to the shared
     * write-back service; kept public for backward compatibility).
     *
     * NOTE: this writes to the LIVE product. Normal AI output must go through
     * FotoHubDraft and be approved first — see FotoHubDraft::approve().
     */
    public function addImageToProduct(int $idProduct, string $imageUrl, int $idProductAttribute = 0): bool
    {
        $writer = new FotoHubWriteback((int) $this->context->language->id);

        return $writer->addImageToProduct($idProduct, $imageUrl, $idProductAttribute);
    }

    /**
     * Get decrypted API key.
     *
     * Uses the FOTOHUBAI_KEY_ENCRYPTED configuration flag written at save
     * time instead of the old strlen(>64) heuristic, which misclassified
     * long plaintext keys and short encrypted blobs.
     */
    public function getDecryptedApiKey(): string
    {
        $stored = Configuration::get('FOTOHUBAI_API_KEY');

        if (empty($stored)) {
            return '';
        }

        if (!Configuration::get('FOTOHUBAI_KEY_ENCRYPTED')) {
            // Stored as plaintext (openssl unavailable at save time, or legacy)
            return $stored;
        }

        if (!function_exists('openssl_decrypt')) {
            return $stored;
        }

        $decoded = base64_decode($stored, true);

        if ($decoded === false || strlen($decoded) <= 16) {
            return $stored;
        }

        $iv = substr($decoded, 0, 16);
        $ciphertext = substr($decoded, 16);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', _COOKIE_KEY_, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : $stored;
    }

    /**
     * Encrypt and store the API key, recording whether encryption was applied
     * in FOTOHUBAI_KEY_ENCRYPTED so decryption never has to guess.
     *
     * @param string $apiKey Plaintext API key
     * @return bool True on success
     */
    public static function storeApiKey(string $apiKey): bool
    {
        if (empty($apiKey)) {
            return Configuration::updateValue('FOTOHUBAI_API_KEY', '')
                && Configuration::updateValue('FOTOHUBAI_KEY_ENCRYPTED', 0);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = openssl_random_pseudo_bytes(16);
            $ciphertext = openssl_encrypt($apiKey, 'aes-256-cbc', _COOKIE_KEY_, OPENSSL_RAW_DATA, $iv);

            if ($ciphertext !== false) {
                return Configuration::updateValue('FOTOHUBAI_API_KEY', base64_encode($iv . $ciphertext))
                    && Configuration::updateValue('FOTOHUBAI_KEY_ENCRYPTED', 1);
            }
        }

        return Configuration::updateValue('FOTOHUBAI_API_KEY', $apiKey)
            && Configuration::updateValue('FOTOHUBAI_KEY_ENCRYPTED', 0);
    }

    /**
     * Encrypt an API key (legacy helper — prefer storeApiKey(), which also
     * records the encryption flag).
     */
    public static function encryptApiKey(string $apiKey): string
    {
        if (empty($apiKey)) {
            return '';
        }

        if (function_exists('openssl_encrypt')) {
            $iv = openssl_random_pseudo_bytes(16);
            $ciphertext = openssl_encrypt($apiKey, 'aes-256-cbc', _COOKIE_KEY_, OPENSSL_RAW_DATA, $iv);

            if ($ciphertext !== false) {
                Configuration::updateValue('FOTOHUBAI_KEY_ENCRYPTED', 1);
                return base64_encode($iv . $ciphertext);
            }
        }

        Configuration::updateValue('FOTOHUBAI_KEY_ENCRYPTED', 0);

        return $apiKey;
    }

    /**
     * Hook: New product created — auto-generate if enabled
     */
    public function hookActionProductAdd(array $params): void
    {
        if (!Configuration::get('FOTOHUBAI_AUTO_GENERATE')) {
            return;
        }
        $idProduct = (int) ($params['id_product'] ?? ($params['product']->id ?? 0));
        if (!$idProduct) {
            return;
        }

        // Queue for scheduled processing instead of immediate
        if (Configuration::get('FOTOHUBAI_SCHEDULER_ENABLED')) {
            FotoHubScheduler::enqueue($idProduct, 'generate', [
                'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
                'width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
                'height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
            ]);
            if (Configuration::get('FOTOHUBAI_AUTO_COPYWRITE')) {
                FotoHubScheduler::enqueue($idProduct, 'copywrite', [
                    'tone' => Configuration::get('FOTOHUBAI_COPYWRITER_TONE'),
                ]);
            }
            return;
        }

        // Immediate generation → pending draft
        $apiKey = $this->getDecryptedApiKey();
        if (empty($apiKey)) {
            return;
        }

        $client = new FotoHubApiClient($apiKey);
        $product = new Product($idProduct, false, $this->context->language->id);
        $prompt = $this->buildPromptFromProduct($product);

        try {
            $result = $client->generateImage($prompt, [
                'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
                'width' => (int) Configuration::get('FOTOHUBAI_DEFAULT_WIDTH'),
                'height' => (int) Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT'),
            ]);
            if (!empty($result['image_url'])) {
                FotoHubDraft::add($idProduct, FotoHubDraft::TYPE_IMAGE, [
                    'image_urls' => [$result['image_url']],
                ], null, 'image_generate');
                FotoHubAnalytics::logApiCall($idProduct, 'generate', Configuration::get('FOTOHUBAI_DEFAULT_MODEL'), (float) ($result['credits_used'] ?? 0), 'success');
            }
        } catch (Exception $e) {
            FotoHubAnalytics::logApiCall($idProduct, 'generate', Configuration::get('FOTOHUBAI_DEFAULT_MODEL'), 0, 'failed', ['error' => $e->getMessage()]);
            PrestaShopLogger::addLog('FOTOhub AI auto-generate failed: ' . $e->getMessage(), 3, null, 'Product', $idProduct);
        }
    }

    /**
     * Hook: New combination/variant added — generate variant-specific image
     */
    public function hookActionObjectCombinationAddAfter(array $params): void
    {
        if (!Configuration::get('FOTOHUBAI_AUTO_GENERATE')) {
            return;
        }
        $combination = $params['object'] ?? null;
        if (!$combination || !($combination instanceof Combination)) {
            return;
        }

        $idProduct = (int) $combination->id_product;
        if (!$idProduct) {
            return;
        }

        if (Configuration::get('FOTOHUBAI_SCHEDULER_ENABLED')) {
            FotoHubScheduler::enqueue($idProduct, 'generate', [
                'model' => Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
                'combination_id' => $combination->id,
            ]);
        }
    }

    /**
     * Hook: Display video tab in product editor left column
     */
    public function hookDisplayAdminProductsMainStepLeftColumnMiddle(array $params): string
    {
        $idProduct = (int) ($params['id_product'] ?? Tools::getValue('id_product'));
        if (!$idProduct) {
            return '';
        }

        $apiKey = $this->getDecryptedApiKey();
        if (empty($apiKey)) {
            return '';
        }

        $client = new FotoHubApiClient($apiKey);
        $videoGen = new FotoHubVideoGenerator($client, $this->context->language->id);
        $videos = $videoGen->getProductVideos($idProduct);

        $this->context->smarty->assign([
            'fotohub_product_id' => $idProduct,
            'fotohub_product_videos' => $videos,
            'fotohub_video_models' => $videoGen->getSupportedModels(),
            'fotohub_video_url' => $this->context->link->getAdminLink('AdminFotoHubVideo'),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/product_video_tab.tpl');
    }

    /**
     * Cron task entry point — called by PrestaShop cron module.
     * Polls bridge jobs and processes the local queue.
     */
    public function cronTask(): void
    {
        if (!Configuration::get('FOTOHUBAI_SCHEDULER_ENABLED')) {
            return;
        }
        $batchSize = (int) Configuration::get('FOTOHUBAI_SCHEDULER_BATCH_SIZE') ?: 5;
        FotoHubScheduler::processCron($batchSize);
    }
}
