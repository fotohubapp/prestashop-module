<?php
/**
 * FOTOhub AI Copywriter Controller
 *
 * Admin controller for AI-powered product copywriting and content generation.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubCopywriter.php';

class AdminFotoHubCopyController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = $this->l('FOTOhub AI — AI Copywriter');
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

        // Handle AJAX requests
        if (Tools::getValue('ajax') && Tools::getValue('action')) {
            $action = Tools::getValue('action');

            switch ($action) {
                case 'generate':
                    $this->ajaxProcessGenerate();
                    break;
                case 'apply':
                    $this->ajaxProcessApply();
                    break;
                case 'bulkGenerate':
                    $this->ajaxProcessBulkGenerate();
                    break;
                default:
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Unknown action']);
                    exit;
            }

            return;
        }

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

        // Get available languages
        $languages = Language::getLanguages(true);

        // Get available tones
        $tones = FotoHubCopywriter::getTones();

        // Content types
        $contentTypes = [
            'description',
            'meta_description',
            'short_description',
            'bullets',
            'social_facebook',
            'social_instagram',
            'social_pinterest',
        ];

        $this->context->smarty->assign([
            'fotohub_products' => $productList,
            'fotohub_languages' => $languages,
            'fotohub_tones' => $tones,
            'fotohub_content_types' => $contentTypes,
            'fotohub_copy_url' => $this->context->link->getAdminLink('AdminFotoHubCopy'),
        ]);

        $this->setTemplate('copywriter.tpl');
    }

    /**
     * AJAX: Generate copy for a product
     */
    public function ajaxProcessGenerate(): void
    {
        header('Content-Type: application/json');

        $idProduct = (int) Tools::getValue('id_product');
        $contentType = Tools::getValue('content_type', 'description');
        $idLang = (int) Tools::getValue('language', $this->context->language->id);
        $tone = Tools::getValue('tone', 'professional');
        $platform = Tools::getValue('platform', '');

        if (!$idProduct) {
            echo json_encode(['error' => 'No product ID provided']);
            exit;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            exit;
        }

        try {
            $copywriter = new FotoHubCopywriter($apiKey);

            $options = [
                'tone' => $tone,
                'id_lang' => $idLang,
            ];

            if (!empty($platform)) {
                $options['platform'] = $platform;
            }

            switch ($contentType) {
                case 'description':
                    $content = $copywriter->generateDescription($idProduct, $options);
                    break;
                case 'meta_description':
                    $content = $copywriter->generateMetaDescription($idProduct, $options);
                    break;
                case 'short_description':
                    $content = $copywriter->generateShortDescription($idProduct, $options);
                    break;
                case 'bullets':
                    $content = $copywriter->generateBullets($idProduct, $options);
                    break;
                case 'social_facebook':
                    $options['platform'] = 'facebook';
                    $content = $copywriter->generateSocialPost($idProduct, $options);
                    break;
                case 'social_instagram':
                    $options['platform'] = 'instagram';
                    $content = $copywriter->generateSocialPost($idProduct, $options);
                    break;
                case 'social_pinterest':
                    $options['platform'] = 'pinterest';
                    $content = $copywriter->generateSocialPost($idProduct, $options);
                    break;
                default:
                    echo json_encode(['error' => 'Invalid content type']);
                    exit;
            }

            echo json_encode([
                'success' => true,
                'content' => $content,
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * AJAX: Apply generated content to a product
     */
    public function ajaxProcessApply(): void
    {
        header('Content-Type: application/json');

        $idProduct = (int) Tools::getValue('id_product');
        $field = Tools::getValue('field');
        $content = Tools::getValue('content');

        if (!$idProduct || empty($field) || empty($content)) {
            echo json_encode(['error' => 'Missing required parameters (id_product, field, content)']);
            exit;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            exit;
        }

        try {
            $result = FotoHubCopywriter::applyToProduct($idProduct, $field, $content);

            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Failed to apply content to product']);
            }
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * AJAX: Bulk generate content for multiple products
     */
    public function ajaxProcessBulkGenerate(): void
    {
        header('Content-Type: application/json');

        $productIds = Tools::getValue('product_ids');
        $contentType = Tools::getValue('content_type', 'description');
        $options = Tools::getValue('options', '{}');

        if (empty($productIds) || !is_array($productIds)) {
            echo json_encode(['error' => 'No products selected']);
            exit;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            exit;
        }

        $parsedOptions = json_decode($options, true) ?: [];

        try {
            $copywriter = new FotoHubCopywriter($apiKey);
            $results = [];

            foreach ($productIds as $idProduct) {
                $idProduct = (int) $idProduct;

                try {
                    switch ($contentType) {
                        case 'description':
                            $content = $copywriter->generateDescription($idProduct, $parsedOptions);
                            break;
                        case 'meta_description':
                            $content = $copywriter->generateMetaDescription($idProduct, $parsedOptions);
                            break;
                        case 'short_description':
                            $content = $copywriter->generateShortDescription($idProduct, $parsedOptions);
                            break;
                        case 'bullets':
                            $content = $copywriter->generateBullets($idProduct, $parsedOptions);
                            break;
                        default:
                            $content = $copywriter->generateDescription($idProduct, $parsedOptions);
                    }

                    $results[] = [
                        'id_product' => $idProduct,
                        'status' => 'success',
                        'content' => $content,
                    ];
                } catch (Exception $e) {
                    $results[] = [
                        'id_product' => $idProduct,
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'results' => $results,
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

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
