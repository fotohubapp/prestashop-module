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
require_once dirname(__FILE__) . '/classes/FotoHubBulkProcessor.php';
require_once dirname(__FILE__) . '/classes/FotoHubVideoGenerator.php';
require_once dirname(__FILE__) . '/classes/FotoHubStabilityTools.php';
require_once dirname(__FILE__) . '/classes/FotoHubCopywriter.php';
require_once dirname(__FILE__) . '/classes/FotoHubAnalytics.php';
require_once dirname(__FILE__) . '/classes/FotoHubScheduler.php';

class FotoHubAi extends Module
{
    public function __construct()
    {
        $this->name = 'fotohubai';
        $this->tab = 'administration';
        $this->version = '2.0.0';
        $this->author = 'FOTOhub';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FOTOhub AI — Creative Suite for PrestaShop');
        $this->description = $this->l('Complete AI creative toolkit: generate product photos & videos, remove backgrounds, upscale images, use 13 Stability AI tools, AI copywriting, and scheduled batch processing.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall FOTOhub AI? Your API key and settings will be removed.');
    }

    /**
     * Module installation
     */
    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('displayAdminProductsExtra')
            && $this->registerHook('actionAdminProductsControllerSaveAfter')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('actionProductAdd')
            && $this->registerHook('actionObjectCombinationAddAfter')
            && $this->registerHook('displayAdminProductsMainStepLeftColumnMiddle')
            && $this->installTab('AdminFotoHubConfig', 'FOTOhub AI', 'AdminParentModulesSf')
            && $this->installTab('AdminFotoHubBulk', 'Bulk Processing', 'AdminFotoHubConfig')
            && $this->installTab('AdminFotoHubVideo', 'Video Generation', 'AdminFotoHubConfig')
            && $this->installTab('AdminFotoHubTools', 'Creative Tools', 'AdminFotoHubConfig')
            && $this->installTab('AdminFotoHubCopy', 'AI Copywriter', 'AdminFotoHubConfig')
            && $this->installTab('AdminFotoHubAnalytics', 'Analytics', 'AdminFotoHubConfig')
            && Configuration::updateValue('FOTOHUBAI_API_KEY', '')
            && Configuration::updateValue('FOTOHUBAI_DEFAULT_MODEL', 'seedream-5-0-260128')
            && Configuration::updateValue('FOTOHUBAI_DEFAULT_WIDTH', 1024)
            && Configuration::updateValue('FOTOHUBAI_DEFAULT_HEIGHT', 1024)
            && Configuration::updateValue('FOTOHUBAI_AUTO_GENERATE', 0)
            && Configuration::updateValue('FOTOHUBAI_DEFAULT_VIDEO_MODEL', 'veo-2')
            && Configuration::updateValue('FOTOHUBAI_DEFAULT_CHAT_MODEL', 'gemini-flash')
            && Configuration::updateValue('FOTOHUBAI_COPYWRITER_TONE', 'professional')
            && Configuration::updateValue('FOTOHUBAI_COPYWRITER_LANGUAGE', '')
            && Configuration::updateValue('FOTOHUBAI_SCHEDULER_BATCH_SIZE', 5)
            && Configuration::updateValue('FOTOHUBAI_SCHEDULER_ENABLED', 0)
            && Configuration::updateValue('FOTOHUBAI_AUTO_COPYWRITE', 0)
            && FotoHubAnalytics::install()
            && FotoHubScheduler::install();
    }

    /**
     * Module uninstallation
     */
    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallTab('AdminFotoHubConfig')
            && $this->uninstallTab('AdminFotoHubBulk')
            && $this->uninstallTab('AdminFotoHubVideo')
            && $this->uninstallTab('AdminFotoHubTools')
            && $this->uninstallTab('AdminFotoHubCopy')
            && $this->uninstallTab('AdminFotoHubAnalytics')
            && Configuration::deleteByName('FOTOHUBAI_API_KEY')
            && Configuration::deleteByName('FOTOHUBAI_DEFAULT_MODEL')
            && Configuration::deleteByName('FOTOHUBAI_DEFAULT_WIDTH')
            && Configuration::deleteByName('FOTOHUBAI_DEFAULT_HEIGHT')
            && Configuration::deleteByName('FOTOHUBAI_AUTO_GENERATE')
            && Configuration::deleteByName('FOTOHUBAI_DEFAULT_VIDEO_MODEL')
            && Configuration::deleteByName('FOTOHUBAI_DEFAULT_CHAT_MODEL')
            && Configuration::deleteByName('FOTOHUBAI_COPYWRITER_TONE')
            && Configuration::deleteByName('FOTOHUBAI_COPYWRITER_LANGUAGE')
            && Configuration::deleteByName('FOTOHUBAI_SCHEDULER_BATCH_SIZE')
            && Configuration::deleteByName('FOTOHUBAI_SCHEDULER_ENABLED')
            && Configuration::deleteByName('FOTOHUBAI_AUTO_COPYWRITE')
            && FotoHubAnalytics::uninstall()
            && FotoHubScheduler::uninstall();
    }

    /**
     * Install an admin tab
     */
    private function installTab(string $className, string $tabName, string $parent): bool
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $className;
        $tab->name = [];

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $tabName;
        }

        $tab->id_parent = (int) Tab::getIdFromClassName($parent);
        $tab->module = $this->name;

        return $tab->add();
    }

    /**
     * Uninstall an admin tab
     */
    private function uninstallTab(string $className): bool
    {
        $idTab = (int) Tab::getIdFromClassName($className);

        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }

        return true;
    }

    /**
     * Module configuration page redirect
     */
    public function getContent(): string
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminFotoHubConfig'));
        return '';
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
            'fotohub_generate_url' => $this->context->link->getAdminLink('AdminFotoHubConfig') . '&ajax=1&action=generate',
            'fotohub_module_path' => $this->_path,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/product_tab.tpl');
    }

    /**
     * Hook: After product save — auto-generate if enabled
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
                $this->addImageToProduct($idProduct, $result['image_url']);
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
            $parts[] = $product->name;
        }

        if (!empty($product->description_short)) {
            $parts[] = strip_tags($product->description_short);
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
     * Download and add an image to a product
     */
    public function addImageToProduct(int $idProduct, string $imageUrl): bool
    {
        $product = new Product($idProduct);

        $image = new Image();
        $image->id_product = $idProduct;
        $image->position = Image::getHighestPosition($idProduct) + 1;

        // Set as cover if no other images exist
        $existingImages = Image::getImages($this->context->language->id, $idProduct);
        $image->cover = empty($existingImages) ? 1 : 0;

        if (!$image->add()) {
            return false;
        }

        // Download image and copy to PrestaShop image directory
        $tmpFile = _PS_TMP_IMG_DIR_ . 'fotohub_' . $idProduct . '_' . $image->id . '.jpg';
        $imageContent = Tools::file_get_contents($imageUrl);

        if (empty($imageContent)) {
            $image->delete();
            return false;
        }

        file_put_contents($tmpFile, $imageContent);

        $newPath = $image->getPathForCreation();

        if (!ImageManager::resize($tmpFile, $newPath . '.jpg')) {
            $image->delete();
            @unlink($tmpFile);
            return false;
        }

        // Generate thumbnails for all image types
        $imageTypes = ImageType::getImagesTypes('products');
        foreach ($imageTypes as $imageType) {
            ImageManager::resize(
                $tmpFile,
                $newPath . '-' . stripslashes($imageType['name']) . '.jpg',
                (int) $imageType['width'],
                (int) $imageType['height']
            );
        }

        @unlink($tmpFile);

        return true;
    }

    /**
     * Get decrypted API key
     */
    public function getDecryptedApiKey(): string
    {
        $encrypted = Configuration::get('FOTOHUBAI_API_KEY');

        if (empty($encrypted)) {
            return '';
        }

        // PrestaShop stores Configuration values with cookie encryption if available
        // For additional security, we use openssl if the key was stored encrypted
        if (function_exists('openssl_decrypt') && strlen($encrypted) > 64) {
            $key = _COOKIE_KEY_;
            $decoded = base64_decode($encrypted);
            if ($decoded === false) {
                return $encrypted;
            }
            $iv = substr($decoded, 0, 16);
            $ciphertext = substr($decoded, 16);
            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : $encrypted;
        }

        return $encrypted;
    }

    /**
     * Encrypt and store API key
     */
    public static function encryptApiKey(string $apiKey): string
    {
        if (empty($apiKey)) {
            return '';
        }

        if (function_exists('openssl_encrypt')) {
            $key = _COOKIE_KEY_;
            $iv = openssl_random_pseudo_bytes(16);
            $ciphertext = openssl_encrypt($apiKey, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            return base64_encode($iv . $ciphertext);
        }

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
        if (!$idProduct) return;

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

        // Immediate generation (existing pattern from hookActionAdminProductsControllerSaveAfter)
        $apiKey = $this->getDecryptedApiKey();
        if (empty($apiKey)) return;

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
                $this->addImageToProduct($idProduct, $result['image_url']);
                FotoHubAnalytics::logApiCall($idProduct, 'generate', Configuration::get('FOTOHUBAI_DEFAULT_MODEL'), 0, 'success');
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
        if (!$combination || !($combination instanceof Combination)) return;

        $idProduct = (int) $combination->id_product;
        if (!$idProduct) return;

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
        if (!$idProduct) return '';

        $apiKey = $this->getDecryptedApiKey();
        if (empty($apiKey)) return '';

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
     * Cron task entry point — called by PrestaShop cron module
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
