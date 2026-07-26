<?php
/**
 * FOTOhub Write-back Service for PrestaShop
 *
 * The ONE path from an AI result to live catalog data. This logic used to live
 * inside the FotoHubAi module class (addImageToProduct) and the copywriter
 * (description updates); it is extracted here so draft approval, the bulk
 * processor, and the module hooks all share a single audited implementation.
 *
 * Nothing in this class is called before a merchant approves a draft —
 * see FotoHubDraft::approve().
 *
 * Capabilities:
 *  - images from remote URLs or data: URIs, optionally associated with a
 *    combination (id_product_attribute) for variant-level galleries
 *  - bridge text result fields (title, short_description, description,
 *    meta_title, meta_description, alt_text, faq)
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FotoHubWriteback
{
    /** Maximum bytes accepted for a downloaded image (guards against huge payloads) */
    public const MAX_IMAGE_BYTES = 25165824; // 24 MiB

    /** Product text fields this service is allowed to write */
    public const TEXT_FIELDS = [
        'title' => 'name',
        'name' => 'name',
        'short_description' => 'description_short',
        'description' => 'description',
        'meta_title' => 'meta_title',
        'meta_description' => 'meta_description',
    ];

    protected int $idLang;

    public function __construct(?int $idLang = null)
    {
        $this->idLang = $idLang ?: (int) Configuration::get('PS_LANG_DEFAULT');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Images
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Download an image and add it to a product's gallery.
     *
     * When $idProductAttribute is non-zero the new image is also associated
     * with that combination, so variant galleries show the variant-specific
     * render (feature B6).
     *
     * @param int $idProduct Product ID
     * @param string $imageUrl Remote URL or data: URI of the image
     * @param int $idProductAttribute Optional combination ID to associate
     * @return bool True on success
     */
    public function addImageToProduct(int $idProduct, string $imageUrl, int $idProductAttribute = 0): bool
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        // Fetch the bytes BEFORE creating the Image row so a dead URL does not
        // leave an orphan record behind.
        $imageContent = $this->fetchImageContent($imageUrl);

        if ($imageContent === '') {
            return false;
        }

        $image = new Image();
        $image->id_product = $idProduct;
        $image->position = Image::getHighestPosition($idProduct) + 1;

        // Set as cover only when the product has no images at all
        $existingImages = Image::getImages($this->idLang, $idProduct);
        $image->cover = empty($existingImages) ? 1 : 0;

        if (!$image->add()) {
            return false;
        }

        $tmpFile = tempnam(_PS_TMP_IMG_DIR_, 'fotohub_');

        if ($tmpFile === false) {
            $image->delete();
            return false;
        }

        if (file_put_contents($tmpFile, $imageContent) === false) {
            $image->delete();
            @unlink($tmpFile);
            return false;
        }

        $newPath = $image->getPathForCreation();

        if (!ImageManager::resize($tmpFile, $newPath . '.jpg')) {
            $image->delete();
            @unlink($tmpFile);
            return false;
        }

        // Generate thumbnails for every registered product image type
        foreach (ImageType::getImagesTypes('products') as $imageType) {
            ImageManager::resize(
                $tmpFile,
                $newPath . '-' . stripslashes($imageType['name']) . '.jpg',
                (int) $imageType['width'],
                (int) $imageType['height']
            );
        }

        @unlink($tmpFile);

        if ($idProductAttribute > 0) {
            $this->associateImageWithCombination((int) $image->id, $idProductAttribute);
        }

        return true;
    }

    /**
     * Associate an existing image with a combination (variant gallery).
     *
     * Uses INSERT IGNORE semantics so re-approving a draft cannot fail on a
     * duplicate key.
     *
     * @param int $idImage Image ID
     * @param int $idProductAttribute Combination ID
     * @return bool True on success
     */
    public function associateImageWithCombination(int $idImage, int $idProductAttribute): bool
    {
        if ($idImage <= 0 || $idProductAttribute <= 0) {
            return false;
        }

        $combination = new Combination($idProductAttribute);

        if (!Validate::isLoadedObject($combination)) {
            return false;
        }

        return (bool) Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'product_attribute_image`
                (`id_product_attribute`, `id_image`)
             VALUES (' . (int) $idProductAttribute . ', ' . (int) $idImage . ')'
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Text
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply bridge text result fields to a product.
     *
     * Recognised payload keys: title, short_description, description,
     * meta_title, meta_description, faq (appended to description), alt_text
     * (written to the image legend). Unknown keys are ignored and empty values
     * never overwrite existing copy.
     *
     * json_ld is deliberately NOT written into product fields — PrestaShop
     * sanitises <script> out of descriptions, so the snippet stays visible in
     * the drafts UI for the merchant to copy into their SEO module instead.
     *
     * @param int $idProduct Product ID
     * @param array $fields Text fields
     * @param int $idProductAttribute Optional combination (scopes alt text)
     * @return bool True on success
     * @throws PrestaShopException When the product does not exist
     */
    public function applyTextFields(int $idProduct, array $fields, int $idProductAttribute = 0): bool
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            throw new PrestaShopException('FOTOhub Writeback: Product not found (ID: ' . $idProduct . ')');
        }

        $changed = false;

        foreach (self::TEXT_FIELDS as $payloadKey => $productField) {
            if (empty($fields[$payloadKey]) || !is_string($fields[$payloadKey])) {
                continue;
            }

            $value = $fields[$payloadKey];

            if ($productField === 'meta_description') {
                $value = Tools::substr(strip_tags($value), 0, 512);
            } elseif ($productField === 'meta_title' || $productField === 'name') {
                $value = Tools::substr(strip_tags($value), 0, 128);
            }

            $product->{$productField}[$this->idLang] = $value;
            $changed = true;
        }

        // FAQ is appended to the long description when provided
        if (!empty($fields['faq']) && is_string($fields['faq'])) {
            $current = $product->description[$this->idLang] ?? '';
            $product->description[$this->idLang] = $current . "\n" . $fields['faq'];
            $changed = true;
        }

        $saved = $changed ? (bool) $product->save() : true;

        if (!empty($fields['alt_text']) && is_string($fields['alt_text'])) {
            $this->applyAltText($idProduct, $fields['alt_text'], $idProductAttribute);
        }

        return $saved;
    }

    /**
     * Apply a single named text field (legacy copywriter path)
     *
     * @param int $idProduct Product ID
     * @param string $field description | description_short | meta_description | meta_title
     * @param string $content Content to apply
     * @return bool True on success
     * @throws PrestaShopException
     */
    public function applyField(int $idProduct, string $field, string $content): bool
    {
        $allowedFields = ['description', 'description_short', 'meta_description', 'meta_title'];

        if (!in_array($field, $allowedFields, true)) {
            throw new PrestaShopException('FOTOhub Writeback: Invalid field "' . $field . '"');
        }

        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            throw new PrestaShopException('FOTOhub Writeback: Product not found (ID: ' . $idProduct . ')');
        }

        $product->{$field}[$this->idLang] = $content;

        return (bool) $product->save();
    }

    /**
     * Set the legend (alt text) of a product image.
     *
     * With a combination the legend is applied to that combination's images;
     * otherwise it goes to the product cover.
     */
    private function applyAltText(int $idProduct, string $altText, int $idProductAttribute = 0): void
    {
        $legend = Tools::substr(strip_tags($altText), 0, 128);
        $imageIds = [];

        if ($idProductAttribute > 0) {
            $rows = Db::getInstance()->executeS(
                'SELECT `id_image` FROM `' . _DB_PREFIX_ . 'product_attribute_image`
                 WHERE `id_product_attribute` = ' . (int) $idProductAttribute
            );

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $imageIds[] = (int) $row['id_image'];
                }
            }
        }

        if (empty($imageIds)) {
            $images = Image::getImages($this->idLang, $idProduct);

            if (empty($images)) {
                return;
            }

            $imageIds = [(int) $images[0]['id_image']];
        }

        foreach ($imageIds as $idImage) {
            try {
                $image = new Image($idImage);

                if (!Validate::isLoadedObject($image)) {
                    continue;
                }

                $image->legend[$this->idLang] = $legend;
                $image->update();
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    'FOTOhub Writeback: alt text update failed — ' . $e->getMessage(),
                    2,
                    null,
                    'Product',
                    $idProduct
                );
            }
        }
    }

    /**
     * Fetch image bytes from a remote URL or decode a data: URI.
     *
     * Remote URLs must be http(s) — this blocks file://, ftp:// and other
     * schemes that would turn an API response into a local file read.
     */
    private function fetchImageContent(string $imageUrl): string
    {
        if (strpos($imageUrl, 'data:') === 0) {
            $commaPos = strpos($imageUrl, ',');

            if ($commaPos === false) {
                return '';
            }

            $decoded = base64_decode(Tools::substr($imageUrl, $commaPos + 1), true);

            if ($decoded === false || strlen($decoded) > self::MAX_IMAGE_BYTES) {
                return '';
            }

            return $decoded;
        }

        if (!preg_match('#^https?://#i', $imageUrl)) {
            return '';
        }

        $content = Tools::file_get_contents($imageUrl);

        if (!is_string($content) || $content === '' || strlen($content) > self::MAX_IMAGE_BYTES) {
            return '';
        }

        return $content;
    }
}
