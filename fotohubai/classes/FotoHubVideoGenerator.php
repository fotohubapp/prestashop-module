<?php
/**
 * FOTOhub Video Generator for PrestaShop
 *
 * Generates product videos using FOTOhub AI API:
 * - Turntable/360 rotation videos
 * - Lifestyle showcase videos
 * - Video status polling and product attachment
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FotoHubVideoGenerator
{
    private FotoHubApiClient $client;
    private int $idLang;

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
     * Generate a 360 turntable rotation video for a product
     *
     * @param int $idProduct Product ID
     * @param array $options Generation options:
     *   - model (string): Video model (default: veo-2)
     *   - duration (int): Duration in seconds (default: 5)
     *   - aspect_ratio (string): Aspect ratio (e.g. '1:1', '16:9')
     * @return array Response with 'job_id' for status polling
     * @throws PrestaShopException
     */
    public function generateTurntableVideo(int $idProduct, array $options = []): array
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            throw new PrestaShopException('FOTOhub Video: Product not found (ID: ' . $idProduct . ')');
        }

        $imageUrl = $this->getProductImageUrl($idProduct);
        $productName = is_array($product->name) ? $product->name[$this->idLang] : $product->name;

        $prompt = '360 turntable rotation of product: ' . $productName;

        $videoOptions = [
            'model' => $options['model'] ?? 'veo-2',
            'duration' => $options['duration'] ?? 5,
        ];

        if (!empty($options['aspect_ratio'])) {
            $videoOptions['aspect_ratio'] = $options['aspect_ratio'];
        }

        if (!empty($imageUrl)) {
            $videoOptions['image_url'] = $imageUrl;
        }

        return $this->client->generateVideo($prompt, $videoOptions);
    }

    /**
     * Generate a lifestyle showcase video for a product
     *
     * @param int $idProduct Product ID
     * @param array $options Generation options:
     *   - model (string): Video model (default: veo-2)
     *   - duration (int): Duration in seconds (default: 5)
     *   - aspect_ratio (string): Aspect ratio
     * @return array Response with 'job_id' for status polling
     * @throws PrestaShopException
     */
    public function generateLifestyleVideo(int $idProduct, array $options = []): array
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            throw new PrestaShopException('FOTOhub Video: Product not found (ID: ' . $idProduct . ')');
        }

        $productName = is_array($product->name) ? $product->name[$this->idLang] : $product->name;

        // Get category name for richer context
        $categoryName = '';
        $idDefaultCategory = (int) $product->id_category_default;
        if ($idDefaultCategory > 0) {
            $category = new Category($idDefaultCategory, $this->idLang);
            if (Validate::isLoadedObject($category)) {
                $categoryName = is_array($category->name) ? $category->name[$this->idLang] : $category->name;
            }
        }

        $prompt = 'Lifestyle video showcasing ' . $productName . ' in use, cinematic quality';
        if (!empty($categoryName)) {
            $prompt = 'Lifestyle video showcasing ' . $productName . ' (' . $categoryName . ') in use, cinematic quality';
        }

        $videoOptions = [
            'model' => $options['model'] ?? 'veo-2',
            'duration' => $options['duration'] ?? 5,
        ];

        if (!empty($options['aspect_ratio'])) {
            $videoOptions['aspect_ratio'] = $options['aspect_ratio'];
        }

        $imageUrl = $this->getProductImageUrl($idProduct);
        if (!empty($imageUrl)) {
            $videoOptions['image_url'] = $imageUrl;
        }

        return $this->client->generateVideo($prompt, $videoOptions);
    }

    /**
     * Check the status of a video generation job
     *
     * @param string $jobId Job ID returned by generateVideo
     * @return array Response with 'status', 'progress', and 'video_url' when complete
     * @throws PrestaShopException
     */
    public function checkStatus(string $jobId): array
    {
        return $this->client->checkVideoStatus($jobId);
    }

    /**
     * Poll video generation status until complete or timeout
     *
     * @param string $jobId Job ID to poll
     * @param int $maxWait Maximum wait time in seconds (default: 300)
     * @param int $interval Polling interval in seconds (default: 5)
     * @return array Final status response with 'video_url' on success
     * @throws PrestaShopException On timeout or generation failure
     */
    public function pollUntilComplete(string $jobId, int $maxWait = 300, int $interval = 5): array
    {
        $elapsed = 0;

        while ($elapsed < $maxWait) {
            $status = $this->checkStatus($jobId);

            if (isset($status['status'])) {
                if ($status['status'] === 'completed') {
                    return $status;
                }

                if ($status['status'] === 'failed') {
                    $errorMsg = $status['error'] ?? 'Video generation failed';
                    throw new PrestaShopException('FOTOhub Video: ' . $errorMsg);
                }
            }

            sleep($interval);
            $elapsed += $interval;
        }

        throw new PrestaShopException('FOTOhub Video: Timeout waiting for video generation (job: ' . $jobId . ')');
    }

    /**
     * Download and save a generated video to a product as an Attachment
     *
     * @param int $idProduct Product ID to attach video to
     * @param string $videoUrl URL of the generated video
     * @param string $filename Custom filename (auto-generated if empty)
     * @return bool True on success
     * @throws PrestaShopException
     */
    public function saveVideoToProduct(int $idProduct, string $videoUrl, string $filename = ''): bool
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            throw new PrestaShopException('FOTOhub Video: Product not found (ID: ' . $idProduct . ')');
        }

        // Download the video
        $videoContent = Tools::file_get_contents($videoUrl);

        if (empty($videoContent)) {
            throw new PrestaShopException('FOTOhub Video: Failed to download video from URL');
        }

        // Determine filename
        if (empty($filename)) {
            $productName = is_array($product->name) ? $product->name[$this->idLang] : $product->name;
            $filename = Tools::str2url($productName) . '-video-' . time() . '.mp4';
        }

        // Create Attachment object
        $attachment = new Attachment();
        $attachment->name[$this->idLang] = pathinfo($filename, PATHINFO_FILENAME);
        $attachment->description[$this->idLang] = 'AI-generated video for ' . (is_array($product->name) ? $product->name[$this->idLang] : $product->name);
        $attachment->file_name = $filename;
        $attachment->mime = 'video/mp4';
        $attachment->file_size = strlen($videoContent);

        // Save the attachment file
        $attachment->file = sha1(microtime());

        if (!$attachment->add()) {
            throw new PrestaShopException('FOTOhub Video: Failed to create attachment record');
        }

        // Write video file to attachment directory
        $attachmentDir = _PS_DOWNLOAD_DIR_;
        $filePath = $attachmentDir . $attachment->file;

        if (file_put_contents($filePath, $videoContent) === false) {
            $attachment->delete();
            throw new PrestaShopException('FOTOhub Video: Failed to write video file');
        }

        // Associate attachment with product
        $attachment->attachProduct($idProduct);

        return true;
    }

    /**
     * Get all video attachments for a product
     *
     * @param int $idProduct Product ID
     * @return array Array of Attachment objects with video MIME types
     */
    public function getProductVideos(int $idProduct): array
    {
        $attachments = Attachment::getAttachments($this->idLang, $idProduct);
        $videos = [];

        foreach ($attachments as $attachmentData) {
            if (strpos($attachmentData['mime'], 'video/') === 0) {
                $videos[] = $attachmentData;
            }
        }

        return $videos;
    }

    /**
     * Get list of supported video generation models
     *
     * @return array Model IDs available for video generation
     */
    public function getSupportedModels(): array
    {
        return [
            'veo-2',
            'veo-3',
            'wan',
            'kling',
            'hailuo',
            'seedance',
            'sora-2',
        ];
    }

    /**
     * Get the public URL of a product's cover image
     *
     * @param int $idProduct Product ID
     * @return string Image URL or empty string if no image
     */
    private function getProductImageUrl(int $idProduct): string
    {
        $images = Image::getImages($this->idLang, $idProduct);

        if (empty($images)) {
            return '';
        }

        $image = new Image((int) $images[0]['id_image']);
        $link = Context::getContext()->link;

        try {
            $imageUrl = $link->getImageLink(
                Product::getProductName($idProduct),
                $image->id,
                ImageType::getFormattedName('large')
            );

            if (!empty($imageUrl)) {
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
}
