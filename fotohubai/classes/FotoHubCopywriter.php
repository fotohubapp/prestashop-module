<?php
/**
 * FOTOhub AI Copywriter for PrestaShop
 *
 * Generates product content using FOTOhub AI API:
 * - Product descriptions (long and short)
 * - SEO meta descriptions and titles
 * - Bullet points and feature lists
 * - Social media captions
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FotoHubCopywriter
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
     * Generate a full product description
     *
     * @param int $idProduct Product ID
     * @param array $options Generation options:
     *   - tone (string): Writing tone (default: professional)
     *   - length (string): short, medium, or long (default: medium)
     *   - language (string): Target language (default: from PS context)
     *   - model (string): AI model ID
     * @return string Generated product description
     * @throws PrestaShopException
     */
    public function generateDescription(int $idProduct, array $options = []): string
    {
        $context = $this->getProductContext($idProduct);
        $tone = $options['tone'] ?? 'professional';
        $length = $options['length'] ?? 'medium';
        $language = $options['language'] ?? $this->getLanguageName();

        $lengthGuide = match ($length) {
            'short' => '100-150 words',
            'long' => '400-600 words',
            default => '200-300 words',
        };

        $systemPrompt = 'You are an e-commerce copywriter specializing in product descriptions. '
            . 'Write in a ' . $tone . ' tone. Write in ' . $language . '. '
            . 'Target length: ' . $lengthGuide . '. '
            . 'Focus on benefits and features that drive purchasing decisions. '
            . 'Use HTML formatting (paragraphs, bold for key features) suitable for an e-commerce product page.';

        $userPrompt = 'Write a product description for:' . "\n"
            . 'Product: ' . $context['name'] . "\n"
            . 'Category: ' . $context['category'] . "\n"
            . 'Price: ' . $context['price'] . "\n";

        if (!empty($context['features'])) {
            $userPrompt .= 'Features: ' . implode(', ', $context['features']) . "\n";
        }

        if (!empty($context['manufacturer'])) {
            $userPrompt .= 'Brand: ' . $context['manufacturer'] . "\n";
        }

        if (!empty($context['short_description'])) {
            $userPrompt .= 'Current short description: ' . $context['short_description'] . "\n";
        }

        $messages = $this->buildChatMessages($systemPrompt, $userPrompt);

        $chatOptions = ['model' => $options['model'] ?? 'gemini-flash'];
        $result = $this->client->chat($messages, $chatOptions);

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Generate an SEO meta description for a product
     *
     * @param int $idProduct Product ID
     * @param array $options Generation options (tone, language, model)
     * @return string Generated meta description (max 155 characters)
     * @throws PrestaShopException
     */
    public function generateMetaDescription(int $idProduct, array $options = []): string
    {
        $context = $this->getProductContext($idProduct);
        $tone = $options['tone'] ?? 'professional';
        $language = $options['language'] ?? $this->getLanguageName();

        $systemPrompt = 'You are an SEO specialist. Write a meta description for a product page. '
            . 'Maximum 155 characters. Write in ' . $language . '. Tone: ' . $tone . '. '
            . 'Include the product name and a compelling reason to click. '
            . 'Return ONLY the meta description text, no quotes or extra formatting.';

        $userPrompt = 'Product: ' . $context['name'] . "\n"
            . 'Category: ' . $context['category'] . "\n"
            . 'Price: ' . $context['price'];

        $messages = $this->buildChatMessages($systemPrompt, $userPrompt);

        $chatOptions = ['model' => $options['model'] ?? 'gemini-flash'];
        $result = $this->client->chat($messages, $chatOptions);

        $content = $result['choices'][0]['message']['content'] ?? '';

        // Ensure it does not exceed 155 characters
        if (mb_strlen($content) > 155) {
            $content = mb_substr($content, 0, 152) . '...';
        }

        return $content;
    }

    /**
     * Generate a short product description
     *
     * @param int $idProduct Product ID
     * @param array $options Generation options (tone, language, model)
     * @return string Generated short description (max 300 characters)
     * @throws PrestaShopException
     */
    public function generateShortDescription(int $idProduct, array $options = []): string
    {
        $context = $this->getProductContext($idProduct);
        $tone = $options['tone'] ?? 'professional';
        $language = $options['language'] ?? $this->getLanguageName();

        $systemPrompt = 'You are an e-commerce copywriter. Write a short product description. '
            . 'Maximum 300 characters. Write in ' . $language . '. Tone: ' . $tone . '. '
            . 'Be concise and highlight the most compelling selling point. '
            . 'Return ONLY the description text.';

        $userPrompt = 'Product: ' . $context['name'] . "\n"
            . 'Category: ' . $context['category'] . "\n";

        if (!empty($context['features'])) {
            $userPrompt .= 'Key features: ' . implode(', ', $context['features']) . "\n";
        }

        $messages = $this->buildChatMessages($systemPrompt, $userPrompt);

        $chatOptions = ['model' => $options['model'] ?? 'gemini-flash'];
        $result = $this->client->chat($messages, $chatOptions);

        $content = $result['choices'][0]['message']['content'] ?? '';

        if (mb_strlen($content) > 300) {
            $content = mb_substr($content, 0, 297) . '...';
        }

        return $content;
    }

    /**
     * Generate bullet points highlighting product features
     *
     * @param int $idProduct Product ID
     * @param int $count Number of bullet points to generate (default: 5)
     * @return array Array of bullet point strings
     * @throws PrestaShopException
     */
    public function generateBulletPoints(int $idProduct, int $count = 5): array
    {
        $context = $this->getProductContext($idProduct);
        $language = $this->getLanguageName();

        $systemPrompt = 'You are an e-commerce copywriter. Generate exactly ' . $count . ' bullet points '
            . 'for a product listing. Write in ' . $language . '. '
            . 'Each bullet should highlight a distinct benefit or feature. '
            . 'Return ONLY the bullet points, one per line, without bullet characters or numbering.';

        $userPrompt = 'Product: ' . $context['name'] . "\n"
            . 'Category: ' . $context['category'] . "\n"
            . 'Price: ' . $context['price'] . "\n";

        if (!empty($context['features'])) {
            $userPrompt .= 'Features: ' . implode(', ', $context['features']) . "\n";
        }

        if (!empty($context['manufacturer'])) {
            $userPrompt .= 'Brand: ' . $context['manufacturer'] . "\n";
        }

        $messages = $this->buildChatMessages($systemPrompt, $userPrompt);

        $result = $this->client->chat($messages, ['model' => 'gemini-flash']);
        $content = $result['choices'][0]['message']['content'] ?? '';

        // Split by newlines and clean up
        $lines = array_filter(
            array_map('trim', explode("\n", $content)),
            function ($line) {
                return !empty($line);
            }
        );

        // Remove any leading bullet characters or numbers
        $bullets = array_map(function ($line) {
            return ltrim($line, "- \t*0123456789.");
        }, $lines);

        return array_values(array_slice($bullets, 0, $count));
    }

    /**
     * Generate a social media caption for a product
     *
     * @param int $idProduct Product ID
     * @param string $platform Target platform: facebook, instagram, pinterest
     * @return string Generated caption for the specified platform
     * @throws PrestaShopException
     */
    public function generateSocialCaption(int $idProduct, string $platform = 'instagram'): string
    {
        $context = $this->getProductContext($idProduct);
        $language = $this->getLanguageName();

        $platformGuide = match ($platform) {
            'facebook' => 'Write a Facebook post (150-300 characters). Conversational tone, include a call to action.',
            'pinterest' => 'Write a Pinterest pin description (100-200 characters). Descriptive, keyword-rich for discovery.',
            default => 'Write an Instagram caption (150-250 characters). Engaging tone, include 3-5 relevant hashtags at the end.',
        };

        $systemPrompt = 'You are a social media content creator for e-commerce brands. '
            . $platformGuide . ' Write in ' . $language . '. '
            . 'Return ONLY the caption text.';

        $userPrompt = 'Product: ' . $context['name'] . "\n"
            . 'Category: ' . $context['category'] . "\n"
            . 'Price: ' . $context['price'];

        $messages = $this->buildChatMessages($systemPrompt, $userPrompt);

        $result = $this->client->chat($messages, ['model' => 'gemini-flash']);

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Apply generated content to a product field
     *
     * @param int $idProduct Product ID
     * @param string $field Field name: description, description_short, meta_description, meta_title
     * @param string $content Content to apply
     * @return bool True on success
     * @throws PrestaShopException
     */
    public function applyToProduct(int $idProduct, string $field, string $content): bool
    {
        $writer = new FotoHubWriteback($this->idLang);

        return $writer->applyField($idProduct, $field, $content);
    }

    /**
     * Generate descriptions for multiple products in bulk
     *
     * @param array $productIds Array of product IDs
     * @param array $options Generation options (tone, length, language, model)
     * @return array Results array keyed by product ID with 'status', 'content', or 'error'
     */
    public function bulkGenerateDescriptions(array $productIds, array $options = []): array
    {
        $results = [];

        foreach ($productIds as $idProduct) {
            $idProduct = (int) $idProduct;

            try {
                $description = $this->generateDescription($idProduct, $options);
                $results[$idProduct] = [
                    'status' => 'success',
                    'content' => $description,
                ];
            } catch (Exception $e) {
                $results[$idProduct] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];

                PrestaShopLogger::addLog(
                    'FOTOhub Copywriter: Bulk generation error — ' . $e->getMessage(),
                    3,
                    null,
                    'Product',
                    $idProduct
                );
            }
        }

        return $results;
    }

    /**
     * Get available languages from PrestaShop
     *
     * @return array Array of ['id' => int, 'name' => string] for each active language
     */
    public function getSupportedLanguages(): array
    {
        $languages = Language::getLanguages(true);

        return array_map(function ($lang) {
            return [
                'id' => (int) $lang['id_lang'],
                'name' => $lang['name'],
            ];
        }, $languages);
    }

    /**
     * Get available writing tones
     *
     * @return array Array of supported tone strings
     */
    public function getSupportedTones(): array
    {
        return [
            'professional',
            'casual',
            'luxury',
            'technical',
            'playful',
            'minimal',
        ];
    }

    /**
     * Gather product context for use in AI prompts
     *
     * @param int $idProduct Product ID
     * @return array Product data: name, short_description, category, price, features, manufacturer
     * @throws PrestaShopException
     */
    private function getProductContext(int $idProduct): array
    {
        $product = new Product($idProduct, false, $this->idLang);

        if (!Validate::isLoadedObject($product)) {
            throw new PrestaShopException('FOTOhub Copywriter: Product not found (ID: ' . $idProduct . ')');
        }

        $productName = is_array($product->name) ? ($product->name[$this->idLang] ?? '') : $product->name;
        $shortDesc = is_array($product->description_short)
            ? ($product->description_short[$this->idLang] ?? '')
            : $product->description_short;

        // Get category name
        $categoryName = '';
        $idDefaultCategory = (int) $product->id_category_default;
        if ($idDefaultCategory > 0) {
            $category = new Category($idDefaultCategory, $this->idLang);
            if (Validate::isLoadedObject($category)) {
                $categoryName = is_array($category->name) ? ($category->name[$this->idLang] ?? '') : $category->name;
            }
        }

        // Get product features
        $features = [];
        $productFeatures = $product->getFrontFeatures($this->idLang);
        if (!empty($productFeatures)) {
            foreach ($productFeatures as $feature) {
                $features[] = $feature['name'] . ': ' . $feature['value'];
            }
        }

        // Get manufacturer name
        $manufacturer = '';
        if ((int) $product->id_manufacturer > 0) {
            $mfr = new Manufacturer((int) $product->id_manufacturer, $this->idLang);
            if (Validate::isLoadedObject($mfr)) {
                $manufacturer = $mfr->name;
            }
        }

        // Format price
        $price = Product::getPriceStatic($idProduct, true, null, 2);
        $currency = Context::getContext()->currency;
        $formattedPrice = Tools::displayPrice($price, $currency);

        return [
            'name' => $productName,
            'short_description' => strip_tags($shortDesc),
            'category' => $categoryName,
            'price' => $formattedPrice,
            'features' => $features,
            'manufacturer' => $manufacturer,
        ];
    }

    /**
     * Build chat messages array for the API
     *
     * @param string $systemPrompt System instruction for the AI
     * @param string $userPrompt User message with product context
     * @return array Messages array with role/content pairs
     */
    private function buildChatMessages(string $systemPrompt, string $userPrompt): array
    {
        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
    }

    /**
     * Get the language name for the current idLang
     *
     * @return string Language name (e.g. 'English', 'Polish')
     */
    private function getLanguageName(): string
    {
        $language = new Language($this->idLang);

        if (Validate::isLoadedObject($language)) {
            return $language->name;
        }

        return 'English';
    }
}
