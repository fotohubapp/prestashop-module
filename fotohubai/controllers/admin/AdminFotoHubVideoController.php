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

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubApiClient.php';
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

        // Handle AJAX requests. Video generation spends credits, so the admin
        // token (CSRF) is verified on every branch and generation additionally
        // requires the controller's edit permission.
        if (Tools::getValue('ajax') && Tools::getValue('action')) {
            $action = Tools::getValue('action');

            if (!$this->verifyRequestToken()) {
                $this->respondJson(['error' => 'Invalid security token'], 403);
            }

            switch ($action) {
                case 'generateVideo':
                    if (!$this->canEdit()) {
                        $this->respondJson(['error' => 'Insufficient permission'], 403);
                    }
                    $this->ajaxProcessGenerateVideo();
                    break;
                case 'checkStatus':
                    $this->ajaxProcessCheckStatus();
                    break;
                case 'getVideos':
                    $this->ajaxProcessGetVideos();
                    break;
                default:
                    $this->respondJson(['error' => 'Unknown action'], 400);
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

        // Get supported video models (getSupportedModels() is an instance method)
        $videoModels = [];

        if (!empty($apiKey)) {
            $videoModels = $this->buildGenerator($apiKey)->getSupportedModels();
        }

        $this->context->smarty->assign([
            'fotohub_products' => $productList,
            'fotohub_video_models' => $videoModels,
            'fotohub_video_url' => $this->context->link->getAdminLink('AdminFotoHubVideo'),
            // The AJAX endpoints verify this token explicitly (CSRF).
            'fotohub_token' => $this->token,
            'fotohub_can_edit' => $this->canEdit(),
        ]);

        $this->setTemplate('video.tpl');
    }

    /**
     * Build a video generator from an API key.
     *
     * FotoHubVideoGenerator takes a FotoHubApiClient plus a language ID — it
     * was previously constructed with the raw key string, which is a TypeError.
     */
    private function buildGenerator(string $apiKey): FotoHubVideoGenerator
    {
        return new FotoHubVideoGenerator(
            new FotoHubApiClient($apiKey),
            (int) $this->context->language->id
        );
    }

    /**
     * Emit a JSON response and stop
     */
    private function respondJson(array $payload, int $httpCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($httpCode);
        echo json_encode($payload);
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
     * AJAX: Generate video for a product
     */
    public function ajaxProcessGenerateVideo(): void
    {
        $idProduct = (int) Tools::getValue('id_product');

        if ($idProduct <= 0 || !Validate::isUnsignedId($idProduct)) {
            $this->respondJson(['error' => 'No product ID provided'], 400);
        }

        $prompt = (string) Tools::getValue('prompt', '');

        // The form posts video_type; older callers used type. Accept both,
        // otherwise "lifestyle" was unreachable and every request rendered a
        // turntable.
        $rawType = (string) Tools::getValue('video_type', (string) Tools::getValue('type', ''));
        $type = $rawType === 'lifestyle' ? 'lifestyle' : 'turntable';
        $duration = min(60, max(1, (int) Tools::getValue('duration', 5)));

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->respondJson(['error' => 'API key not configured'], 400);
        }

        $generator = $this->buildGenerator($apiKey);

        // Reject unknown model IDs up front — the API returns 400 for anything
        // outside its own list, so validating here yields a clearer error.
        $model = (string) Tools::getValue('model', '');
        $supported = $generator->getSupportedModels();

        if (!in_array($model, $supported, true)) {
            $model = (string) Configuration::get('FOTOHUBAI_DEFAULT_VIDEO_MODEL');

            if (!in_array($model, $supported, true)) {
                $model = 'veo-3.1-fast-generate-001';
            }
        }

        $options = [
            'model' => $model,
            'duration' => $duration,
        ];

        if ($prompt !== '' && Validate::isCleanHtml($prompt)) {
            $options['prompt'] = $prompt;
        }

        try {
            // Method names are generateTurntableVideo/generateLifestyleVideo
            $result = $type === 'turntable'
                ? $generator->generateTurntableVideo($idProduct, $options)
                : $generator->generateLifestyleVideo($idProduct, $options);
        } catch (Exception $e) {
            $this->respondJson(['error' => $e->getMessage()], 502);
        }

        // POST /v1/ai/generate/video is synchronous: it normally returns
        // video_url directly and only sometimes carries a job_id.
        $this->respondJson([
            'success' => true,
            'video_url' => $result['video_url'] ?? null,
            'job_id' => $result['job_id'] ?? null,
            'credits_used' => $result['credits_used'] ?? null,
            'status' => $result['status'] ?? (!empty($result['video_url']) ? 'completed' : 'processing'),
        ]);
    }

    /**
     * AJAX: Check video generation status
     */
    public function ajaxProcessCheckStatus(): void
    {
        $jobId = (string) Tools::getValue('job_id');

        if ($jobId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $jobId)) {
            $this->respondJson(['error' => 'No job ID provided'], 400);
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->respondJson(['error' => 'API key not configured'], 400);
        }

        try {
            // Correct method name is checkStatus() on the generator
            $result = $this->buildGenerator($apiKey)->checkStatus($jobId);
        } catch (Exception $e) {
            $this->respondJson(['error' => $e->getMessage()], 502);
        }

        $response = [
            'success' => true,
            'status' => $result['status'] ?? 'unknown',
        ];

        if (!empty($result['video_url'])) {
            $response['video_url'] = $result['video_url'];
        }

        $this->respondJson($response);
    }

    /**
     * AJAX: Get videos for a product
     */
    public function ajaxProcessGetVideos(): void
    {
        $idProduct = (int) Tools::getValue('id_product');

        if ($idProduct <= 0 || !Validate::isUnsignedId($idProduct)) {
            $this->respondJson(['error' => 'No product ID provided'], 400);
        }

        $module = Module::getInstanceByName('fotohubai');
        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            $this->respondJson(['error' => 'API key not configured'], 400);
        }

        try {
            $videos = $this->buildGenerator($apiKey)->getProductVideos($idProduct);
        } catch (Exception $e) {
            $this->respondJson(['error' => $e->getMessage()], 502);
        }

        $this->respondJson(['success' => true, 'videos' => $videos]);
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
