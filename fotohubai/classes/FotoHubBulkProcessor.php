<?php
/**
 * FOTOhub Bulk Processor for PrestaShop
 *
 * Two execution paths:
 *
 * 1. BRIDGE (preferred): submits bulk jobs to the FOTOhub commerce-bridge
 *    (POST /v1/commerce/jobs) with full product_context. Job IDs persist in
 *    Configuration so progress survives page reloads; polling happens in
 *    FotoHubScheduler::processCron. Completed item results land as pending
 *    drafts (fotohub_draft) — nothing is written to live products until
 *    the merchant approves in AdminFotohubDrafts.
 *
 * 2. LOCAL (fallback): the original resumable synchronous pipeline against
 *    the direct api-server endpoints, kept for single-store sync ops or when
 *    the bridge is unavailable. Results also land as drafts.
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
    /** Configuration key holding the JSON list of active bridge jobs */
    public const CONFIG_ACTIVE_JOBS = 'FOTOHUBAI_BRIDGE_JOBS';

    /** Bridge job states that mean "still running" */
    private const RUNNING_STATES = ['queued', 'processing', 'awaiting_credits'];

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
     * @param FotoHubApiClient $client API client instance (FotoHubBridgeClient enables bridge ops)
     * @param int $idLang Language ID for product data
     */
    public function __construct(FotoHubApiClient $client, int $idLang)
    {
        $this->client = $client;
        $this->idLang = $idLang;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Bridge path
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Submit a bulk job to the commerce-bridge.
     *
     * @param array $productIds Product IDs to process
     * @param string $kind Bridge job kind (see FotoHubBridgeClient::JOB_KINDS)
     * @param array $options Job options (language, tone, num_images, ...)
     * @param string|null $model Image model ID
     * @param string|null $presetSlug Preset slug
     * @return array Bridge response {job_id, status, total_items, estimated_credits}
     * @throws FotoHubInsufficientFundsException
     * @throws PrestaShopException
     */
    public function submitBridgeJob(
        array $productIds,
        string $kind,
        array $options = [],
        ?string $model = null,
        ?string $presetSlug = null,
        bool $perVariant = false
    ): array {
        if (!($this->client instanceof FotoHubBridgeClient)) {
            throw new PrestaShopException('FOTOhub Bulk: Bridge jobs require a FotoHubBridgeClient instance');
        }

        $items = $this->buildBridgeItems($productIds, $kind, $perVariant);

        if (empty($items)) {
            throw new PrestaShopException('FOTOhub Bulk: No valid products for this job');
        }

        // Idempotency key must cover every input that changes the output, so a
        // re-submit with different options is not silently deduplicated by the
        // bridge into the first job's result.
        $fingerprint = implode(',', array_map('intval', $productIds))
            . '|' . $kind
            . '|' . ($model ?? '')
            . '|' . ($presetSlug ?? '')
            . '|' . ($perVariant ? 'variants' : 'products')
            . '|' . json_encode($options)
            . '|' . date('YmdH');

        $idempotencyKey = 'ps-' . md5($fingerprint);

        $response = $this->client->createJob($kind, $items, $options, $model, $presetSlug, $idempotencyKey);

        if (!empty($response['job_id'])) {
            self::rememberJob((string) $response['job_id'], $kind, count($items));
        }

        return $response;
    }

    /**
     * Build bridge job items with real product_context.
     *
     * With $perVariant the iteration is over product combinations: one item per
     * combination, external_id "<id_product>:<id_product_attribute>", the
     * combination attributes merged into product_context.attributes, and the
     * combination's own image (when it has one) as source_image_url. On
     * approval the resulting draft is associated back to that combination.
     *
     * @param array $productIds Product IDs
     * @param string $kind Job kind (image kinds attach source_image_url)
     * @param bool $perVariant Emit one item per combination instead of per product
     * @return array Items ready for POST /jobs
     */
    public function buildBridgeItems(array $productIds, string $kind, bool $perVariant = false): array
    {
        // 'tryon' belongs here too: the product photo is the garment it dresses
        // the model photo in.
        $needsSourceImage = in_array($kind, ['image_edit', 'bg_remove', 'bg_replace', 'upscale', 'recolor', 'tryon'], true);
        $items = [];

        foreach ($productIds as $idProduct) {
            $idProduct = (int) $idProduct;
            $product = new Product($idProduct, false, $this->idLang);

            if (!Validate::isLoadedObject($product)) {
                continue;
            }

            $baseContext = $this->buildProductContext($product);
            $productImageUrl = $this->getCoverImageUrl($idProduct);

            $combinations = $perVariant ? $this->getCombinations($idProduct) : [];

            if ($perVariant && !empty($combinations)) {
                foreach ($combinations as $combination) {
                    $idProductAttribute = (int) $combination['id_product_attribute'];
                    $context = $baseContext;

                    if (!empty($combination['attributes'])) {
                        $context['attributes'] = array_merge(
                            $context['attributes'] ?? [],
                            $combination['attributes']
                        );
                    }

                    if (!empty($combination['title_suffix'])) {
                        $context['title'] = $baseContext['title'] . ' — ' . $combination['title_suffix'];
                    }

                    if (isset($combination['price'])) {
                        $context['price'] = (float) $combination['price'];
                    }

                    $item = [
                        'external_id' => $idProduct . ':' . $idProductAttribute,
                        'variant_id' => (string) $idProductAttribute,
                        'product_context' => $context,
                    ];

                    $sku = !empty($combination['reference']) ? $combination['reference'] : $product->reference;

                    if (!empty($sku)) {
                        $item['sku'] = $sku;
                    }

                    $sourceUrl = !empty($combination['image_url']) ? $combination['image_url'] : $productImageUrl;

                    if (!empty($sourceUrl)) {
                        $item['source_image_url'] = $sourceUrl;
                    } elseif ($needsSourceImage) {
                        continue;
                    }

                    $items[] = $item;
                }

                continue;
            }

            $item = [
                'external_id' => (string) $idProduct,
                'product_context' => $baseContext,
            ];

            if (!empty($product->reference)) {
                $item['sku'] = $product->reference;
            }

            if (!empty($productImageUrl)) {
                $item['source_image_url'] = $productImageUrl;
            } elseif ($needsSourceImage) {
                // Image-transform kinds are pointless without a source image
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Load a product's combinations with attribute labels and their own image.
     *
     * @param int $idProduct Product ID
     * @return array [{id_product_attribute, reference, price, attributes, title_suffix, image_url}]
     */
    public function getCombinations(int $idProduct): array
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        $rows = $product->getAttributeCombinations($this->idLang);

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $combinations = [];

        // getAttributeCombinations() returns one row per attribute, so group
        // the rows back into one entry per combination.
        foreach ($rows as $row) {
            $idProductAttribute = (int) $row['id_product_attribute'];

            if (!isset($combinations[$idProductAttribute])) {
                $combinations[$idProductAttribute] = [
                    'id_product_attribute' => $idProductAttribute,
                    'reference' => $row['reference'] ?? '',
                    'price' => Product::getPriceStatic($idProduct, true, $idProductAttribute, 2),
                    'attributes' => [],
                    'title_suffix' => '',
                    'image_url' => $this->getCombinationImageUrl($idProduct, $idProductAttribute),
                ];
            }

            $groupName = $row['group_name'] ?? ($row['group'] ?? '');
            $attributeName = $row['attribute_name'] ?? '';

            if ($groupName !== '' && $attributeName !== '') {
                $combinations[$idProductAttribute]['attributes'][$groupName] = $attributeName;
            }
        }

        foreach ($combinations as &$combination) {
            $combination['title_suffix'] = implode(' ', array_values($combination['attributes']));
        }
        unset($combination);

        return array_values($combinations);
    }

    /**
     * Public URL of a combination's own image, empty when it has none
     */
    public function getCombinationImageUrl(int $idProduct, int $idProductAttribute): string
    {
        $idImage = (int) Db::getInstance()->getValue(
            'SELECT `id_image` FROM `' . _DB_PREFIX_ . 'product_attribute_image`
             WHERE `id_product_attribute` = ' . (int) $idProductAttribute . '
             LIMIT 1'
        );

        if ($idImage <= 0) {
            return '';
        }

        return $this->buildImageUrl($idProduct, $idImage);
    }

    /**
     * Build the bridge product_context object from a loaded product
     */
    public function buildProductContext(Product $product): array
    {
        $name = is_array($product->name) ? ($product->name[$this->idLang] ?? reset($product->name)) : $product->name;

        $context = [
            'title' => (string) $name,
        ];

        // Category
        $idDefaultCategory = (int) $product->id_category_default;
        if ($idDefaultCategory > 0) {
            $category = new Category($idDefaultCategory, $this->idLang);
            if (Validate::isLoadedObject($category)) {
                $catName = is_array($category->name) ? ($category->name[$this->idLang] ?? '') : $category->name;
                if (!empty($catName)) {
                    $context['category'] = $catName;
                }
            }
        }

        // Attributes from product features
        $attributes = [];
        $features = $product->getFrontFeatures($this->idLang);
        if (!empty($features)) {
            foreach ($features as $feature) {
                if (!empty($feature['name'])) {
                    $attributes[$feature['name']] = $feature['value'] ?? '';
                }
            }
        }

        if ((int) $product->id_manufacturer > 0) {
            $mfr = new Manufacturer((int) $product->id_manufacturer, $this->idLang);
            if (Validate::isLoadedObject($mfr) && !empty($mfr->name)) {
                $attributes['brand'] = $mfr->name;
            }
        }

        if (!empty($attributes)) {
            $context['attributes'] = $attributes;
        }

        // Price
        $price = Product::getPriceStatic($product->id, true, null, 2);
        if ($price !== null) {
            $context['price'] = (float) $price;
        }

        // Current description
        $description = is_array($product->description)
            ? ($product->description[$this->idLang] ?? '')
            : $product->description;

        if (!empty($description)) {
            $context['current_description'] = strip_tags($description);
        }

        return $context;
    }

    /**
     * Persist a job ID so progress survives page reloads
     */
    public static function rememberJob(string $jobId, string $kind, int $totalItems = 0): void
    {
        $jobs = self::getActiveJobs();
        $jobs[$jobId] = [
            'job_id' => $jobId,
            'kind' => $kind,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'queued',
            'total_items' => $totalItems,
            'done_items' => 0,
            'failed_items' => 0,
        ];

        Configuration::updateValue(self::CONFIG_ACTIVE_JOBS, json_encode($jobs));
    }

    /**
     * Get all tracked bridge jobs
     *
     * @return array job_id => {job_id, kind, created_at, status}
     */
    public static function getActiveJobs(): array
    {
        $stored = Configuration::get(self::CONFIG_ACTIVE_JOBS);

        if (empty($stored)) {
            return [];
        }

        $jobs = json_decode($stored, true);

        return is_array($jobs) ? $jobs : [];
    }

    /**
     * Stop tracking a bridge job
     */
    public static function forgetJob(string $jobId): void
    {
        $jobs = self::getActiveJobs();
        unset($jobs[$jobId]);
        Configuration::updateValue(self::CONFIG_ACTIVE_JOBS, json_encode($jobs));
    }

    /**
     * Poll all tracked bridge jobs; ingest results of finished jobs as drafts.
     *
     * Called from FotoHubScheduler::processCron and from the progress AJAX.
     *
     * @param FotoHubBridgeClient $bridge Bridge client
     * @return array Summary: {polled, completed, ingested_drafts, failed}
     */
    public static function pollBridgeJobs(FotoHubBridgeClient $bridge): array
    {
        $summary = ['polled' => 0, 'completed' => 0, 'ingested_drafts' => 0, 'failed' => 0];
        $jobs = self::getActiveJobs();

        if (empty($jobs)) {
            return $summary;
        }

        // Mutate one local copy and persist ONCE at the end. Writing inside the
        // loop while also calling forgetJob() (which re-reads storage) would
        // resurrect jobs that were just finished and removed.
        foreach ($jobs as $jobId => $meta) {
            $summary['polled']++;

            try {
                $status = $bridge->getJob((string) $jobId);
            } catch (Exception $e) {
                PrestaShopLogger::addLog('FOTOhub Bridge poll failed for job ' . $jobId . ': ' . $e->getMessage(), 2);
                continue;
            }

            $state = $status['status'] ?? 'processing';

            // Keep tracked counters fresh for the progress UI. Fall back to the
            // remembered value so a response without total_items does not reset
            // the progress column to 0.
            $jobs[$jobId]['status'] = $state;
            $jobs[$jobId]['done_items'] = (int) ($status['done_items'] ?? ($meta['done_items'] ?? 0));
            $jobs[$jobId]['failed_items'] = (int) ($status['failed_items'] ?? ($meta['failed_items'] ?? 0));
            $jobs[$jobId]['total_items'] = (int) ($status['total_items'] ?? ($meta['total_items'] ?? 0));

            if (in_array($state, self::RUNNING_STATES, true)) {
                continue;
            }

            if ($state === 'completed' || $state === 'completed_with_errors') {
                $summary['completed']++;
                $summary['ingested_drafts'] += self::ingestJobResults($bridge, (string) $jobId, $meta['kind'] ?? '');
            } else {
                // failed | cancelled
                $summary['failed']++;
                PrestaShopLogger::addLog('FOTOhub Bridge job ' . $jobId . ' ended with status ' . $state, 2);
            }

            // Terminal state: stop tracking
            unset($jobs[$jobId]);
        }

        Configuration::updateValue(self::CONFIG_ACTIVE_JOBS, json_encode($jobs));

        return $summary;
    }

    /**
     * Fetch completed items of a finished job and store them as pending drafts
     *
     * @return int Number of drafts created
     */
    private static function ingestJobResults(FotoHubBridgeClient $bridge, string $jobId, string $kind): int
    {
        $created = 0;
        $offset = 0;
        $limit = 100;

        while (true) {
            try {
                $page = $bridge->getJobItems($jobId, 'completed', $limit, $offset);
            } catch (Exception $e) {
                PrestaShopLogger::addLog('FOTOhub Bridge: item fetch failed for job ' . $jobId . ': ' . $e->getMessage(), 3);
                break;
            }

            $items = $page['items'] ?? [];

            if (!is_array($items) || empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $created += self::ingestItemResult($item, $jobId, $kind);
            }

            if (count($items) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return $created;
    }

    /**
     * Turn one bridge item into pending draft(s).
     *
     * Shared by the cron poll and the webhook receiver so external_id parsing
     * and bridge_item_id deduplication live in exactly one place.
     *
     * @param array $item Bridge item {id, external_id, result, ...}
     * @param string|null $jobId Job ID
     * @param string|null $kind Job kind
     * @return int Number of drafts created
     */
    public static function ingestItemResult(array $item, ?string $jobId = null, ?string $kind = null): int
    {
        [$idProduct, $idProductAttribute] = self::parseExternalId((string) ($item['external_id'] ?? ''));
        $result = $item['result'] ?? [];
        $bridgeItemId = !empty($item['id']) ? (string) $item['id'] : null;

        if ($idProduct <= 0 || empty($result) || !is_array($result)) {
            return 0;
        }

        $created = 0;

        try {
            if (!empty($result['image_urls']) && is_array($result['image_urls'])) {
                $created += FotoHubDraft::add(
                    $idProduct,
                    FotoHubDraft::TYPE_IMAGE,
                    ['image_urls' => array_values($result['image_urls'])],
                    $jobId,
                    $kind,
                    $idProductAttribute,
                    $bridgeItemId
                ) > 0 ? 1 : 0;
            }

            if (!empty($result['text']) && is_array($result['text'])) {
                $created += FotoHubDraft::add(
                    $idProduct,
                    FotoHubDraft::TYPE_TEXT,
                    $result['text'],
                    $jobId,
                    $kind,
                    $idProductAttribute,
                    $bridgeItemId
                ) > 0 ? 1 : 0;
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'FOTOhub Bridge: draft ingest failed for product ' . $idProduct . ': ' . $e->getMessage(),
                3,
                null,
                'Product',
                $idProduct
            );
        }

        return $created;
    }

    /**
     * Parse an item external_id back into [id_product, id_product_attribute].
     *
     * Per-variant jobs use "<id_product>:<id_product_attribute>"; per-product
     * jobs use the bare product ID.
     *
     * @return array{0: int, 1: int}
     */
    public static function parseExternalId(string $externalId): array
    {
        if (strpos($externalId, ':') !== false) {
            $parts = explode(':', $externalId, 2);

            return [(int) $parts[0], (int) $parts[1]];
        }

        return [(int) $externalId, 0];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Local fallback path (resumable synchronous pipeline)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Process a batch of products with the specified action against the
     * direct api-server endpoints. Results land as pending drafts.
     *
     * @param array $productIds Array of product IDs to process
     * @param string $action Action: generate, remove_background, replace_background,
     *                       upscale, generate_video, copywrite, pipeline
     * @param array $options Additional options for the action
     * @return array Results array with status per product
     */
    public function processBatch(array $productIds, string $action, array $options = []): array
    {
        $this->results = [];
        $this->current = 0;
        $this->total = count($productIds);

        foreach ($productIds as $idProduct) {
            $this->processOne((int) $idProduct, $action, $options);
            $this->current++;
        }

        return $this->results;
    }

    /**
     * Dispatch a single product through the local pipeline
     */
    private function processOne(int $idProduct, string $action, array $options): void
    {
        try {
            switch ($action) {
                case 'generate':
                    $this->processGenerate($idProduct, $options);
                    break;
                case 'remove_background':
                    $this->processRemoveBackground($idProduct);
                    break;
                case 'replace_background':
                    $this->processReplaceBackground($idProduct, $options);
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

    /**
     * Generate an AI image for a product → pending draft
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

        if (!empty($options['num_images'])) {
            $genOptions['num_images'] = (int) $options['num_images'];
        }

        $result = $this->client->generateImage($prompt, $genOptions);

        $this->storeImageDraft($idProduct, $result, 'image_generate', $product->name);
    }

    /**
     * Remove background from a product's cover image → pending draft
     */
    private function processRemoveBackground(int $idProduct): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $imageUrl = $this->getCoverImageUrl($idProduct);

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'skipped', 'No images to process');
            return;
        }

        $result = $this->client->removeBackground($imageUrl);

        $this->storeImageDraft($idProduct, $result, 'bg_remove', $product->name);
    }

    /**
     * Replace background of a product's cover image → pending draft
     */
    private function processReplaceBackground(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $imageUrl = $this->getCoverImageUrl($idProduct);

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'skipped', 'No images to process');
            return;
        }

        $background = $options['background'] ?? 'clean white studio background';
        $result = $this->client->replaceBackground($imageUrl, $background, $options['background_type'] ?? 'auto');

        $this->storeImageDraft($idProduct, $result, 'bg_replace', $product->name);
    }

    /**
     * Upscale a product's cover image → pending draft
     */
    private function processUpscale(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $imageUrl = $this->getCoverImageUrl($idProduct);

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'skipped', 'No images to upscale');
            return;
        }

        $scale = (int) ($options['scale'] ?? 2);
        $result = $this->client->upscaleImage($imageUrl, $scale);

        $this->storeImageDraft($idProduct, $result, 'upscale', $product->name);
    }

    /**
     * Generate a video from a product's cover image (async, tracked by job_id)
     */
    private function processGenerateVideo(int $idProduct, array $options): void
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            $this->addResult($idProduct, 'error', 'Product not found');
            return;
        }

        $imageUrl = $this->getCoverImageUrl($idProduct);

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'skipped', 'No images for video generation');
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

        if (!empty($result['video_url'])) {
            $this->addResult($idProduct, 'success', 'Video generated', [
                'video_url' => $result['video_url'],
                'product_name' => $product->name,
            ]);
        } elseif (!empty($result['job_id'])) {
            $this->addResult($idProduct, 'success', 'Video generation started', [
                'job_id' => $result['job_id'],
                'product_name' => $product->name,
            ]);
        } else {
            $this->addResult($idProduct, 'error', 'No video URL or job ID in response');
        }
    }

    /**
     * Generate AI copywriting for a product → pending TEXT draft
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
        $shortDesc = strip_tags(is_array($product->description_short)
            ? ($product->description_short[$this->idLang] ?? '')
            : (string) $product->description_short);
        $categories = [];

        $productCategories = Product::getProductCategoriesFull($idProduct, $this->idLang);
        foreach ($productCategories as $cat) {
            $categories[] = $cat['name'];
        }

        $categoryStr = implode(', ', $categories);
        $language = Language::getLanguage($this->idLang);
        $langName = $language['name'] ?? 'English';
        $tone = $options['tone'] ?? Configuration::get('FOTOHUBAI_COPYWRITER_TONE') ?: 'professional';

        $systemPrompt = 'You are a professional e-commerce copywriter. Write compelling product descriptions '
            . 'that are SEO-optimized and persuasive, in a ' . $tone . ' tone. Always respond in ' . $langName . '.';

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

        // Parse the response into structured text fields
        $payload = [];

        if (preg_match('/DESCRIPTION:\s*\n(.*?)(?=\n\s*META:)/s', $content, $matches)) {
            $payload['description'] = trim($matches[1]);
        } else {
            $payload['description'] = '<p>' . nl2br(htmlspecialchars($content)) . '</p>';
        }

        if (preg_match('/META:\s*\n(.*?)(?=\n\s*BULLETS:)/s', $content, $matches)) {
            $payload['meta_description'] = mb_substr(trim($matches[1]), 0, 160);
        }

        if (preg_match('/BULLETS:\s*\n(.*)/s', $content, $matches)) {
            $payload['description'] .= "\n" . trim($matches[1]);
        }

        // DRAFT-FIRST: store for merchant review, never write live content here
        FotoHubDraft::add($idProduct, FotoHubDraft::TYPE_TEXT, $payload, null, 'description');

        $this->addResult($idProduct, 'success', 'Product copy generated (draft pending review)', [
            'product_name' => $productName,
        ]);
    }

    /**
     * Run a full pipeline: generate image + remove background + write description.
     * All output lands as pending drafts.
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

        // Step 2: Remove background (only possible on http(s) URLs)
        $finalImageUrl = $imageUrl;

        if (strpos($imageUrl, 'http') === 0) {
            try {
                $bgResult = $this->client->removeBackground($imageUrl);

                if (!empty($bgResult['image_url'])) {
                    $steps[] = 'background_removed';
                    $finalImageUrl = $bgResult['image_url'];
                } else {
                    $steps[] = 'background_removal_skipped';
                }
            } catch (Exception $e) {
                $steps[] = 'background_removal_failed';
                PrestaShopLogger::addLog(
                    'FOTOhub pipeline: bg removal failed for product ' . $idProduct . ': ' . $e->getMessage(),
                    2,
                    null,
                    'Product',
                    $idProduct
                );
            }
        } else {
            $steps[] = 'background_removal_skipped';
        }

        // DRAFT-FIRST: image draft for merchant review
        try {
            FotoHubDraft::add($idProduct, FotoHubDraft::TYPE_IMAGE, [
                'image_urls' => [$finalImageUrl],
            ], null, 'complete_listing');
            $steps[] = 'image_draft_saved';
        } catch (Exception $e) {
            $steps[] = 'image_draft_failed';
        }

        // Step 3: Generate copywriting (stores its own text draft)
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

        $this->addResult($idProduct, 'success', 'Pipeline completed (drafts pending review)', [
            'product_name' => $product->name,
            'steps_completed' => $steps,
            'final_image_url' => $finalImageUrl,
        ]);
    }

    /**
     * Store an image API result as a pending draft and record the outcome
     */
    private function storeImageDraft(int $idProduct, array $result, string $kind, $productName): void
    {
        $imageUrl = $result['image_url'] ?? '';

        if (empty($imageUrl)) {
            $this->addResult($idProduct, 'error', 'No image URL in response');
            return;
        }

        FotoHubDraft::add($idProduct, FotoHubDraft::TYPE_IMAGE, [
            'image_urls' => [$imageUrl],
        ], null, $kind);

        $this->addResult($idProduct, 'success', 'Result saved as draft (pending review)', [
            'image_url' => $imageUrl,
            'product_name' => $productName,
        ]);
    }

    /**
     * Get the public URL of a product's cover image
     */
    public function getCoverImageUrl(int $idProduct): string
    {
        $images = Image::getImages($this->idLang, $idProduct);

        if (empty($images)) {
            return '';
        }

        return $this->buildImageUrl($idProduct, (int) $images[0]['id_image']);
    }

    /**
     * Build a publicly reachable URL for one product image.
     *
     * The bridge fetches this URL from outside the shop, so it must be
     * absolute; the manual /img/p/ path is the fallback when the link builder
     * is unavailable (e.g. running from cron with no front-office context).
     */
    private function buildImageUrl(int $idProduct, int $idImage): string
    {
        if ($idImage <= 0) {
            return '';
        }

        $image = new Image($idImage);

        if (!Validate::isLoadedObject($image)) {
            return '';
        }

        try {
            $imageUrl = Context::getContext()->link->getImageLink(
                Product::getProductName($idProduct),
                $image->id,
                ImageType::getFormattedName('large')
            );

            if (!empty($imageUrl)) {
                if (strpos($imageUrl, 'http') !== 0) {
                    $imageUrl = 'https://' . ltrim($imageUrl, '/');
                }

                return $imageUrl;
            }
        } catch (Exception $e) {
            // Fallback below
        }

        $shopUrl = rtrim(Configuration::get('PS_SSL_ENABLED')
            ? Tools::getShopDomainSsl(true)
            : Tools::getShopDomain(true), '/');

        if (strpos($shopUrl, 'http') !== 0) {
            $shopUrl = 'https://' . $shopUrl;
        }

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
            $this->processOne((int) $idProduct, $action, $options);
            $this->current++;

            // Save progress after each item
            $this->saveProgress($batchId);
        }

        return $this->results;
    }
}
