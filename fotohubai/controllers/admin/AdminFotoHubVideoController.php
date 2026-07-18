<?php
/**
 * FOTOhub AI Video Generation Controller
 *
 * Admin controller for AI video generation from product images.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubVideoGenerator.php';

class AdminFotoHubVideoController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = $this->l('FOTOhub AI — Video Generation');
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
                case 'generateVideo':
                    $this->ajaxProcessGenerateVideo();
                    break;
                case 'checkStatus':
                    $this->ajaxProcessCheckStatus();
                    break;
                case 'getVideos':
                    $this->ajaxProcessGetVideos();
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

        // Get supported video models
        $videoModels = FotoHubVideoGenerator::getSupportedModels();

        $this->context->smarty->assign([
            'fotohub_products' => $productList,
            'fotohub_video_models' => $videoModels,
            'fotohub_video_url' => $this->context->link->getAdminLink('AdminFotoHubVideo'),
        ]);

        $this->setTemplate('video.tpl');
    }

    /**
     * AJAX: Generate video for a product
     */
    public function ajaxProcessGenerateVideo(): void
    {
        header('Content-Type: application/json');

        $idProduct = (int) Tools::getValue('id_product');
        $prompt = Tools::getValue('prompt', '');
        $model = Tools::getValue('model', 'veo-2');
        $type = Tools::getValue('type', 'turntable');
        $duration = (int) Tools::getValue('duration', 5);

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
            $generator = new FotoHubVideoGenerator($apiKey);

            if ($type === 'turntable') {
                $result = $generator->generateTurntable($idProduct, [
                    'prompt' => $prompt,
                    'model' => $model,
                    'duration' => $duration,
                ]);
            } else {
                $result = $generator->generateLifestyle($idProduct, [
                    'prompt' => $prompt,
                    'model' => $model,
                    'duration' => $duration,
                ]);
            }

            echo json_encode([
                'success' => true,
                'job_id' => $result['job_id'] ?? null,
                'status' => $result['status'] ?? 'pending',
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * AJAX: Check video generation status
     */
    public function ajaxProcessCheckStatus(): void
    {
        header('Content-Type: application/json');

        $jobId = Tools::getValue('job_id');

        if (empty($jobId)) {
            echo json_encode(['error' => 'No job ID provided']);
            exit;
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            echo json_encode(['error' => 'API key not configured']);
            exit;
        }

        try {
            $generator = new FotoHubVideoGenerator($apiKey);
            $result = $generator->checkVideoStatus($jobId);

            $response = [
                'success' => true,
                'status' => $result['status'] ?? 'unknown',
            ];

            if (isset($result['video_url'])) {
                $response['video_url'] = $result['video_url'];
            }

            echo json_encode($response);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        exit;
    }

    /**
     * AJAX: Get videos for a product
     */
    public function ajaxProcessGetVideos(): void
    {
        header('Content-Type: application/json');

        $idProduct = (int) Tools::getValue('id_product');

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
            $generator = new FotoHubVideoGenerator($apiKey);
            $videos = $generator->getProductVideos($idProduct);

            echo json_encode([
                'success' => true,
                'videos' => $videos,
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
