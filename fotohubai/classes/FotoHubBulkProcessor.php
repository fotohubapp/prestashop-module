<?php
/**
 * FOTOhub Bulk Processor for PrestaShop
 *
 * Handles batch processing of product images:
 * - Generate AI photos for multiple products
 * - Remove backgrounds in bulk
 * - Upscale product images in bulk
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FotoHubBulkProcessor
{
    private FotoHubApiClient $client;
    private int $idLang;

    /** @var array Processing results log */
    private array $results = [];

    /** @var int Current item index in batch */
    private int $current = 0;

    /** @var int Total items in batch */
    private int $total = 0;

    /** @var string Current batch identifier */
    private string $batchId = '';

    /**
     * @param FotoHubApiClient $client API client instance
     * @param int $idLang Language ID for product data
     */
    public function __construct(FotoHubApiClient $client, int $idLang)
    {
        $this->client = $client;
        $this->idLang = $idLang;
    }

    /**
     * Process a batch of products with the specified action
     *
     * @param array $productIds Array of product IDs to process
     * @param string $action Action: 'generate', 'remove_background', 'upscale'
     * @param array $options Additional options for the action
     * @return array Results array with status per product
     */
    public function processBatch(array $productIds, string $action, array $options = []): array
    {
        $this->results = [];
        $this->current = 0;
        $this->total = count($productIds);

        foreach ($productIds as $idProduct) {
            $idProduct = (int) $idProduct;
            $this->current++;

            try {
                switch ($action) {
                    case 'generate':
                        $this->processGenerate($idProduct, $options);
                        break;
                    case 'remove_background':
                        $this->processRemoveBackground($idProduct);
                        break;
                    case 'upscale':
                        $this->processUpscale($idProduct, $options);
                        break;
                    case 'generate_video':
                        $this->processGenerateVideo($idProduct, $options);
                        break;
                    case 'copywrite':
                        $this->processCopywrite($idProduct, $options);
                        break;
                    case 'pipeline':
                        $this->processPipeline($idProduct, $options);
                        break;
                    default:
                        $this->addResult($idProduct, 'error', 'Unknown action: ' . $action);
                }
            } catch (Exception $e) {
                $this->addResult($idProduct, 'error', $e->getMessage());
                PrestaShopLogger::addLog(
                    'FOTOhub bulk processing error: ' . $e->getMessage(),
                    3,
                    null,
                    'Product',
                    $idProduct
                );
            }
        }

        return $this->results;
    }

    /**
     * Generate an AI image for a product
     */
    private function processGenerate(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $module = Module::getInstanceByName('fotohubai');
        $prompt = $module->buildPromptFromProduct($product);

        $genOptions = [
            'model' => $options['model'] ?? Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
            'width' => (int) ($options['width'] ?? Configuration::get('FOTOHUBAI_DEFAULT_WIDTH')),
            'height' => (int) ($options['height'] ?? Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT')),
        ];

        if (!empty($options['negative_prompt'])) {
            $genOptions['negative_prompt'] = $options['negative_prompt'];
        }

        $result = $this->client->generateImage($prompt, $genOptions);

        if (!empty($result['image_url'])) {
            $success = $module->addImageToProduct($idProduct, $result['image_url']);

            if ($success) {
                $this->addResult($idProduct, 'success', 'Image generated', [
                    'image_url' => $result['image_url'],
                    'product_name' => $product->name,
                ]);
            } else {
                $this->addResult($idProduct, 'error', 'Image generated but failed to save to product');
            }
        } else {
            $this->addResult($idProduct, 'error', 'No image URL in response');
        }
    }

    /**
     * Remove background from a product's first image
     */
    private function processRemoveBackground(int $idProduct): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $images = Image::getImages($this->idLang, $idProduct);

        if (empty($images)) {
            $this->addResult($idProduct, 'skipped', 'No images to process');
            return;
        }

        // Process the cover image (first image)
        $image = new Image((int) $images[0]['id_image']);
        $imageUrl = $this->getProductImageUrl($idProduct, $image);

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'error', 'Could not determine image URL');
            return;
        }

        $result = $this->client->removeBackground($imageUrl);

        if (!empty($result['image_url'])) {
            $module = Module::getInstanceByName('fotohubai');
            $success = $module->addImageToProduct($idProduct, $result['image_url']);

            if ($success) {
                $this->addResult($idProduct, 'success', 'Background removed', [
                    'image_url' => $result['image_url'],
                    'product_name' => $product->name,
                ]);
            } else {
                $this->addResult($idProduct, 'error', 'Background removed but failed to save');
            }
        } else {
            $this->addResult($idProduct, 'error', 'No image URL in response');
        }
    }

    /**
     * Upscale a product's first image
     */
    private function processUpscale(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $images = Image::getImages($this->idLang, $idProduct);

        if (empty($images)) {
            $this->addResult($idProduct, 'skipped', 'No images to upscale');
            return;
        }

        $image = new Image((int) $images[0]['id_image']);
        $imageUrl = $this->getProductImageUrl($idProduct, $image);

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'error', 'Could not determine image URL');
            return;
        }

        $scale = (int) ($options['scale'] ?? 2);
        $result = $this->client->upscaleImage($imageUrl, $scale);

        if (!empty($result['image_url'])) {
            $module = Module::getInstanceByName('fotohubai');
            $success = $module->addImageToProduct($idProduct, $result['image_url']);

            if ($success) {
                $this->addResult($idProduct, 'success', 'Image upscaled (' . $scale . 'x)', [
                    'image_url' => $result['image_url'],
                    'product_name' => $product->name,
                ]);
            } else {
                $this->addResult($idProduct, 'error', 'Image upscaled but failed to save');
            }
        } else {
            $this->addResult($idProduct, 'error', 'No image URL in response');
        }
    }

    /**
     * Generate a video from a product's cover image
     */
    private function processGenerateVideo(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $images = Image::getImages($this->idLang, $idProduct);

        if (empty($images)) {
            $this->addResult($idProduct, 'skipped', 'No images for video generation');
            return;
        }

        $image = new Image((int) $images[0]['id_image']);
        $imageUrl = $this->getProductImageUrl($idProduct, $image);

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'error', 'Could not determine image URL');
            return;
        }

        $videoOptions = [
            'image_url' => $imageUrl,
        ];

        if (!empty($options['model'])) {
            $videoOptions['model'] = $options['model'];
        }

        if (!empty($options['duration'])) {
            $videoOptions['duration'] = (int) $options['duration'];
        }

        if (!empty($options['aspect_ratio'])) {
            $videoOptions['aspect_ratio'] = $options['aspect_ratio'];
        }

        $prompt = !empty($options['prompt'])
            ? $options['prompt']
            : 'Product showcase video of ' . $product->name;

        $result = $this->client->generateVideo($prompt, $videoOptions);

        if (!empty($result['job_id'])) {
            $this->addResult($idProduct, 'success', 'Video generation started', [
                'job_id' => $result['job_id'],
                'product_name' => $product->name,
            ]);
        } else {
            $this->addResult($idProduct, 'error', 'No job ID in video generation response');
        }
    }

    /**
     * Generate AI copywriting for a product (description, meta, bullets)
     */
    private function processCopywrite(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        // Gather product context
        $productName = $product->name;
        $shortDesc = strip_tags($product->description_short[$this->idLang] ?? '');
        $categories = [];

        $productCategories = Product::getProductCategoriesFull($idProduct, $this->idLang);
        foreach ($productCategories as $cat) {
            $categories[] = $cat['name'];
        }

        $categoryStr = implode(', ', $categories);
        $language = Language::getLanguage($this->idLang);
        $langName = $language['name'] ?? 'English';

        $systemPrompt = 'You are a professional e-commerce copywriter. Write compelling product descriptions that are SEO-optimized and persuasive. Always respond in ' . $langName . '.';

        $userPrompt = "Write a professional product description for the following product:\n\n"
            . "Product name: {$productName}\n"
            . "Short description: {$shortDesc}\n"
            . "Categories: {$categoryStr}\n\n"
            . "Please provide:\n"
            . "1. A compelling product description (2-3 paragraphs, HTML formatted with <p> tags)\n"
            . "2. An SEO meta description (max 160 characters, plain text)\n"
            . "3. 5 bullet points highlighting key features (HTML formatted as <ul><li>)\n\n"
            . "Format your response as:\n"
            . "DESCRIPTION:\n[description here]\n\n"
            . "META:\n[meta description here]\n\n"
            . "BULLETS:\n[bullet points here]";

        $chatOptions = [
            'model' => $options['model'] ?? 'gemini-flash',
            'temperature' => $options['temperature'] ?? 0.7,
            'system' => $systemPrompt,
        ];

        $messages = [
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $result = $this->client->chat($messages, $chatOptions);

        $content = $result['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            $this->addResult($idProduct, 'error', 'Empty response from AI copywriter');
            return;
        }

        // Parse the response and update product
        $langId = $this->idLang;

        // Extract description
        if (preg_match('/DESCRIPTION:\s*\n(.*?)(?=\n\s*META:)/s', $content, $matches)) {
            $product->description[$langId] = trim($matches[1]);
        } else {
            // Use entire content as description if parsing fails
            $product->description[$langId] = '<p>' . nl2br(htmlspecialchars($content)) . '</p>';
        }

        // Extract meta description
        if (preg_match('/META:\s*\n(.*?)(?=\n\s*BULLETS:)/s', $content, $matches)) {
            $meta = trim($matches[1]);
            $product->meta_description[$langId] = mb_substr($meta, 0, 160);
        }

        // Extract bullet points and append to description
        if (preg_match('/BULLETS:\s*\n(.*)/s', $content, $matches)) {
            $bullets = trim($matches[1]);
            $product->description[$langId] .= "\n" . $bullets;
        }

        $product->save();

        $this->addResult($idProduct, 'success', 'Product copy generated', [
            'product_name' => $productName,
        ]);
    }

    /**
     * Run a full pipeline: generate image + remove background + write description
     */
    private function processPipeline(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $steps = [];

        // Step 1: Generate image
        try {
            $module = Module::getInstanceByName('fotohubai');
            $prompt = $module->buildPromptFromProduct($product);

            $genOptions = [
                'model' => $options['model'] ?? Configuration::get('FOTOHUBAI_DEFAULT_MODEL'),
                'width' => (int) ($options['width'] ?? Configuration::get('FOTOHUBAI_DEFAULT_WIDTH')),
                'height' => (int) ($options['height'] ?? Configuration::get('FOTOHUBAI_DEFAULT_HEIGHT')),
            ];

            $genResult = $this->client->generateImage($prompt, $genOptions);

            if (!empty($genResult['image_url'])) {
                $steps[] = 'image_generated';
                $imageUrl = $genResult['image_url'];
            } else {
                $this->addResult($idProduct, 'error', 'Pipeline failed at step 1: No image generated');
                return;
            }
        } catch (Exception $e) {
            $this->addResult($idProduct, 'error', 'Pipeline failed at step 1 (generate): ' . $e->getMessage());
            return;
        }

        // Step 2: Remove background
        try {
            $bgResult = $this->client->removeBackground($imageUrl);

            if (!empty($bgResult['image_url'])) {
                $steps[] = 'background_removed';
                $finalImageUrl = $bgResult['image_url'];
            } else {
                // Use original image if bg removal fails to return URL
                $finalImageUrl = $imageUrl;
                $steps[] = 'background_removal_skipped';
            }
        } catch (Exception $e) {
            // Continue pipeline even if bg removal fails
            $finalImageUrl = $imageUrl;
            $steps[] = 'background_removal_failed';
            PrestaShopLogger::addLog(
                'FOTOhub pipeline: bg removal failed for product ' . $idProduct . ': ' . $e->getMessage(),
                2,
                null,
                'Product',
                $idProduct
            );
        }

        // Save the image to product
        try {
            $success = $module->addImageToProduct($idProduct, $finalImageUrl);
            if ($success) {
                $steps[] = 'image_saved';
            } else {
                $steps[] = 'image_save_failed';
            }
        } catch (Exception $e) {
            $steps[] = 'image_save_failed';
        }

        // Step 3: Generate copywriting
        try {
            $this->processCopywrite($idProduct, $options);
            $steps[] = 'copy_generated';
        } catch (Exception $e) {
            $steps[] = 'copy_generation_failed';
            PrestaShopLogger::addLog(
                'FOTOhub pipeline: copywrite failed for product ' . $idProduct . ': ' . $e->getMessage(),
                2,
                null,
                'Product',
                $idProduct
            );
        }

        $this->addResult($idProduct, 'success', 'Pipeline completed', [
            'product_name' => $product->name,
            'steps_completed' => $steps,
            'final_image_url' => $finalImageUrl,
        ]);
    }

    /**
     * Get the public URL of a product image
     */
    private function getProductImageUrl(int $idProduct, Image $image): string
    {
        $link = Context::getContext()->link;

        // Try to get the image URL via PrestaShop's Link class
        try {
            $imageUrl = $link->getImageLink(
                Product::getProductName($idProduct),
                $image->id,
                ImageType::getFormattedName('large')
            );

            if (!empty($imageUrl)) {
                // Ensure it's an absolute URL
                if (strpos($imageUrl, 'http') !== 0) {
                    $imageUrl = 'https://' . $imageUrl;
                }
                return $imageUrl;
            }
        } catch (Exception $e) {
            // Fallback below
        }

        // Fallback: construct URL manually
        $shopUrl = rtrim(Configuration::get('PS_SSL_ENABLED') ?
            Tools::getShopDomainSsl(true) : Tools::getShopDomain(true), '/');

        return $shopUrl . '/img/p/' . $image->getImgPath() . '.jpg';
    }

    /**
     * Add a result entry
     */
    private function addResult(int $idProduct, string $status, string $message, array $data = []): void
    {
        $this->results[] = array_merge([
            'id_product' => $idProduct,
            'status' => $status,
            'message' => $message,
        ], $data);
    }

    /**
     * Get processing results
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get summary counts
     */
    public function getSummary(): array
    {
        $summary = ['success' => 0, 'error' => 0, 'skipped' => 0, 'total' => count($this->results)];

        foreach ($this->results as $result) {
            if (isset($summary[$result['status']])) {
                $summary[$result['status']]++;
            }
        }

        return $summary;
    }

    /**
     * Get current batch progress
     *
     * @return array Progress info with 'current', 'total', and 'percentage'
     */
    public function getProgress(): array
    {
        $percentage = $this->total > 0 ? round(($this->current / $this->total) * 100, 1) : 0.0;

        return [
            'current' => $this->current,
            'total' => $this->total,
            'percentage' => $percentage,
        ];
    }

    /**
     * Save current batch progress to PrestaShop cache
     *
     * @param string $batchId Unique batch identifier
     */
    public function saveProgress(string $batchId): void
    {
        $this->batchId = $batchId;

        $data = [
            'batch_id' => $batchId,
            'current' => $this->current,
            'total' => $this->total,
            'results' => $this->results,
            'timestamp' => time(),
        ];

        $cacheKey = 'fotohub_batch_' . $batchId;

        if (class_exists('CacheCore') && CacheCore::getInstance()) {
            CacheCore::getInstance()->set($cacheKey, json_encode($data), 3600);
        } else {
            // Fallback: store in PrestaShop configuration (for shops without cache)
            Configuration::updateValue($cacheKey, json_encode($data));
        }
    }

    /**
     * Load saved batch progress from cache
     *
     * @param string $batchId Unique batch identifier
     * @return array|null Saved progress data or null if not found
     */
    public function loadProgress(string $batchId): ?array
    {
        $cacheKey = 'fotohub_batch_' . $batchId;

        $data = null;

        if (class_exists('CacheCore') && CacheCore::getInstance()) {
            $cached = CacheCore::getInstance()->get($cacheKey);
            if ($cached !== false) {
                $data = json_decode($cached, true);
            }
        }

        if ($data === null) {
            // Fallback: try configuration storage
            $stored = Configuration::get($cacheKey);
            if (!empty($stored)) {
                $data = json_decode($stored, true);
            }
        }

        if ($data === null || !is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Resume a batch from the last processed product
     *
     * @param string $batchId Unique batch identifier
     * @param array $productIds Full list of product IDs for the batch
     * @param string $action Action to perform
     * @param array $options Action options
     * @return array Results including previously completed items
     */
    public function resumeBatch(string $batchId, array $productIds, string $action, array $options = []): array
    {
        $this->batchId = $batchId;
        $saved = $this->loadProgress($batchId);

        $startFrom = 0;

        if ($saved !== null) {
            // Restore previous results
            $this->results = $saved['results'] ?? [];
            $startFrom = $saved['current'] ?? 0;
        }

        // Get remaining product IDs
        $remainingProducts = array_slice($productIds, $startFrom);

        if (empty($remainingProducts)) {
            return $this->results;
        }

        $this->current = $startFrom;
        $this->total = count($productIds);

        foreach ($remainingProducts as $idProduct) {
            $idProduct = (int) $idProduct;
            $this->current++;

            try {
                switch ($action) {
                    case 'generate':
                        $this->processGenerate($idProduct, $options);
                        break;
                    case 'remove_background':
                        $this->processRemoveBackground($idProduct);
                        break;
                    case 'upscale':
                        $this->processUpscale($idProduct, $options);
                        break;
                    case 'generate_video':
                        $this->processGenerateVideo($idProduct, $options);
                        break;
                    case 'copywrite':
                        $this->processCopywrite($idProduct, $options);
                        break;
                    case 'pipeline':
                        $this->processPipeline($idProduct, $options);
                        break;
                    default:
                        $this->addResult($idProduct, 'error', 'Unknown action: ' . $action);
                }
            } catch (Exception $e) {
                $this->addResult($idProduct, 'error', $e->getMessage());
                PrestaShopLogger::addLog(
                    'FOTOhub bulk processing error: ' . $e->getMessage(),
                    3,
                    null,
                    'Product',
                    $idProduct
                );
            }

            // Save progress after each item
            $this->saveProgress($batchId);
        }

        return $this->results;
    }
}
