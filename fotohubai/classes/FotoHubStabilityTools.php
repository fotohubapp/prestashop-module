<?php
/**
 * FOTOhub Stability AI Tools for PrestaShop
 *
 * Provides access to all 13 Stability AI image processing tools:
 * upscaling, background removal, inpainting, outpainting,
 * search-replace, recolor, style transfer, and control tools.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/FotoHubDraft.php';

class FotoHubStabilityTools
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
     * Get all available Stability AI tools with metadata
     *
     * @return array Array of tool definitions with id, name, description, requires_mask
     */
    public function getAvailableTools(): array
    {
        return [
            [
                'id' => 'fast-upscale',
                'name' => 'Fast Upscale',
                'description' => 'Quickly upscale an image with minimal processing time',
                'requires_mask' => false,
            ],
            [
                'id' => 'creative-upscale',
                'name' => 'Creative Upscale',
                'description' => 'Upscale with AI-enhanced detail generation for creative results',
                'requires_mask' => false,
            ],
            [
                'id' => 'conservative-upscale',
                'name' => 'Conservative Upscale',
                'description' => 'Upscale while preserving original details as closely as possible',
                'requires_mask' => false,
            ],
            [
                'id' => 'remove-background',
                'name' => 'Remove Background',
                'description' => 'Remove the background from an image, leaving only the subject',
                'requires_mask' => false,
            ],
            [
                'id' => 'erase-object',
                'name' => 'Erase Object',
                'description' => 'Erase a specific object from an image using a mask',
                'requires_mask' => true,
            ],
            [
                'id' => 'inpaint',
                'name' => 'Inpaint',
                'description' => 'Fill in a masked area with AI-generated content matching a prompt',
                'requires_mask' => true,
            ],
            [
                'id' => 'outpaint',
                'name' => 'Outpaint',
                'description' => 'Extend an image beyond its original boundaries',
                'requires_mask' => false,
            ],
            [
                'id' => 'search-replace',
                'name' => 'Search & Replace',
                'description' => 'Find an element in an image and replace it with something else',
                'requires_mask' => false,
            ],
            [
                'id' => 'search-recolor',
                'name' => 'Search & Recolor',
                'description' => 'Find an element in an image and change its color',
                'requires_mask' => false,
            ],
            [
                'id' => 'style-transfer',
                'name' => 'Style Transfer',
                'description' => 'Apply the style of a reference image to the product image',
                'requires_mask' => false,
            ],
            [
                'id' => 'style-guide',
                'name' => 'Style Guide',
                'description' => 'Apply a predefined style guide to the image',
                'requires_mask' => false,
            ],
            [
                'id' => 'control-sketch',
                'name' => 'Control: Sketch',
                'description' => 'Generate an image using a sketch as structural guidance',
                'requires_mask' => false,
            ],
            [
                'id' => 'control-structure',
                'name' => 'Control: Structure',
                'description' => 'Generate an image preserving the structural composition of the input',
                'requires_mask' => false,
            ],
        ];
    }

    /**
     * Process a product's cover image with a Stability AI tool
     *
     * @param int $idProduct Product ID
     * @param string $toolId Stability tool identifier
     * @param array $options Tool-specific options (prompt, mask, etc.)
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function processProductImage(int $idProduct, string $toolId, array $options = []): array
    {
        $imageBase64 = $this->getProductImageBase64($idProduct);

        $result = $this->client->stabilityTool($toolId, $imageBase64, $options);

        // DRAFT-FIRST: the result is queued for review in AdminFotohubDrafts.
        // It used to be written straight onto the live product, which silently
        // published un-reviewed AI output into the catalogue.
        if (!empty($result['image_url']) || !empty($result['image'])) {
            $imageData = (string) ($result['image_url'] ?? $result['image']);

            try {
                $result['draft_id'] = FotoHubDraft::add(
                    $idProduct,
                    FotoHubDraft::TYPE_IMAGE,
                    ['image_urls' => [$imageData]],
                    null,
                    'image_edit'
                );
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    'FOTOhub Stability: could not store draft — ' . $e->getMessage(),
                    3,
                    null,
                    'Product',
                    $idProduct
                );
            }
        }

        return $result;
    }

    /**
     * Process a locally uploaded image with a Stability AI tool
     *
     * @param string $imagePath Local file path of the image
     * @param string $toolId Stability tool identifier
     * @param array $options Tool-specific options
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function processFromUpload(string $imagePath, string $toolId, array $options = []): array
    {
        if (!file_exists($imagePath)) {
            throw new PrestaShopException('FOTOhub Stability: File not found: ' . $imagePath);
        }

        $imageContent = file_get_contents($imagePath);

        if ($imageContent === false) {
            throw new PrestaShopException('FOTOhub Stability: Failed to read file: ' . $imagePath);
        }

        $imageBase64 = base64_encode($imageContent);

        return $this->client->stabilityTool($toolId, $imageBase64, $options);
    }

    /**
     * Fast upscale a product's cover image
     *
     * @param int $idProduct Product ID
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function fastUpscale(int $idProduct): array
    {
        return $this->processProductImage($idProduct, 'fast-upscale');
    }

    /**
     * Creative upscale a product's cover image with optional prompt guidance
     *
     * @param int $idProduct Product ID
     * @param string $prompt Optional prompt for creative detail generation
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function creativeUpscale(int $idProduct, string $prompt = ''): array
    {
        $options = [];

        if (!empty($prompt)) {
            $options['prompt'] = $prompt;
        }

        return $this->processProductImage($idProduct, 'creative-upscale', $options);
    }

    /**
     * Conservative upscale a product's cover image
     *
     * @param int $idProduct Product ID
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function conservativeUpscale(int $idProduct): array
    {
        return $this->processProductImage($idProduct, 'conservative-upscale');
    }

    /**
     * Remove background from a product's cover image
     *
     * @param int $idProduct Product ID
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function removeBackground(int $idProduct): array
    {
        return $this->processProductImage($idProduct, 'remove-background');
    }

    /**
     * Erase an object from a product's image using a mask
     *
     * @param int $idProduct Product ID
     * @param string $maskBase64 Base64-encoded mask image (white = area to erase)
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function eraseObject(int $idProduct, string $maskBase64): array
    {
        return $this->processProductImage($idProduct, 'erase-object', [
            'mask' => $maskBase64,
        ]);
    }

    /**
     * Inpaint a masked area of a product's image with prompt-guided content
     *
     * @param int $idProduct Product ID
     * @param string $maskBase64 Base64-encoded mask image (white = area to fill)
     * @param string $prompt Description of what to generate in the masked area
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function inpaint(int $idProduct, string $maskBase64, string $prompt): array
    {
        return $this->processProductImage($idProduct, 'inpaint', [
            'mask' => $maskBase64,
            'prompt' => $prompt,
        ]);
    }

    /**
     * Extend an image beyond its boundaries in a specified direction
     *
     * @param int $idProduct Product ID
     * @param string $direction Direction to extend: left, right, up, down
     * @param int $pixels Number of pixels to extend (default: 256)
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function outpaint(int $idProduct, string $direction, int $pixels = 256): array
    {
        return $this->processProductImage($idProduct, 'outpaint', [
            'direction' => $direction,
            'pixels' => $pixels,
        ]);
    }

    /**
     * Search for an element in a product image and replace it
     *
     * @param int $idProduct Product ID
     * @param string $searchPrompt Description of what to find
     * @param string $replacePrompt Description of what to replace it with
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function searchReplace(int $idProduct, string $searchPrompt, string $replacePrompt): array
    {
        return $this->processProductImage($idProduct, 'search-replace', [
            'search_prompt' => $searchPrompt,
            'replace_prompt' => $replacePrompt,
        ]);
    }

    /**
     * Search for an element in a product image and change its color
     *
     * @param int $idProduct Product ID
     * @param string $selectPrompt Description of what to select
     * @param string $colorPrompt Target color or color description
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function searchRecolor(int $idProduct, string $selectPrompt, string $colorPrompt): array
    {
        return $this->processProductImage($idProduct, 'search-recolor', [
            'select_prompt' => $selectPrompt,
            'color_prompt' => $colorPrompt,
        ]);
    }

    /**
     * Apply the style of a reference image to a product image
     *
     * @param int $idProduct Product ID
     * @param string $styleImageBase64 Base64-encoded style reference image
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function styleTransfer(int $idProduct, string $styleImageBase64): array
    {
        return $this->processProductImage($idProduct, 'style-transfer', [
            'style_image' => $styleImageBase64,
        ]);
    }

    /**
     * Generate an image from a product's cover using sketch-based control
     *
     * @param int $idProduct Product ID
     * @param string $prompt Description of the image to generate
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function controlSketch(int $idProduct, string $prompt): array
    {
        return $this->processProductImage($idProduct, 'control-sketch', [
            'prompt' => $prompt,
        ]);
    }

    /**
     * Generate an image preserving the structural composition of a product's cover
     *
     * @param int $idProduct Product ID
     * @param string $prompt Description of the image to generate
     * @return array Response with processed image
     * @throws PrestaShopException
     */
    public function controlStructure(int $idProduct, string $prompt): array
    {
        return $this->processProductImage($idProduct, 'control-structure', [
            'prompt' => $prompt,
        ]);
    }

    /**
     * Get the base64-encoded cover image for a product
     *
     * @param int $idProduct Product ID
     * @return string Base64-encoded image data
     * @throws PrestaShopException
     */
    private function getProductImageBase64(int $idProduct): string
    {
        $images = Image::getImages($this->idLang, $idProduct);

        if (empty($images)) {
            throw new PrestaShopException('FOTOhub Stability: Product has no images (ID: ' . $idProduct . ')');
        }

        $image = new Image((int) $images[0]['id_image']);
        $link = Context::getContext()->link;

        $imageUrl = '';

        try {
            $imageUrl = $link->getImageLink(
                Product::getProductName($idProduct),
                $image->id,
                ImageType::getFormattedName('large')
            );

            if (!empty($imageUrl) && strpos($imageUrl, 'http') !== 0) {
                $imageUrl = 'https://' . $imageUrl;
            }
        } catch (Exception $e) {
            // Fallback below
        }

        if (empty($imageUrl)) {
            $shopUrl = rtrim(Configuration::get('PS_SSL_ENABLED') ?
                Tools::getShopDomainSsl(true) : Tools::getShopDomain(true), '/');
            $imageUrl = $shopUrl . '/img/p/' . $image->getImgPath() . '.jpg';
        }

        $imageContent = Tools::file_get_contents($imageUrl);

        if (empty($imageContent)) {
            throw new PrestaShopException('FOTOhub Stability: Failed to download product image');
        }

        return base64_encode($imageContent);
    }

    /**
     * Queue a processed image as a pending draft for the given product.
     *
     * This replaces the old saveResultToProduct(), which wrote straight to the
     * live catalogue. Approval in AdminFotohubDrafts is now the only path from
     * a Stability result to a live product image.
     *
     * @param int $idProduct Product ID
     * @param string $imageBase64OrUrl data: URI or URL of the processed image
     * @return int Draft ID, or 0 when nothing was stored
     */
    public function queueResultAsDraft(int $idProduct, string $imageBase64OrUrl): int
    {
        if ($idProduct <= 0 || $imageBase64OrUrl === '') {
            return 0;
        }

        try {
            return FotoHubDraft::add(
                $idProduct,
                FotoHubDraft::TYPE_IMAGE,
                ['image_urls' => [$imageBase64OrUrl]],
                null,
                'image_edit'
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'FOTOhub Stability: Failed to queue result as draft — ' . $e->getMessage(),
                3,
                null,
                'Product',
                $idProduct
            );

            return 0;
        }
    }
}
