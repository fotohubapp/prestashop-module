<?php
/**
 * FOTOhub API Client for PrestaShop
 *
 * Handles all communication with the FOTOhub AI API.
 * Uses PrestaShop's native HTTP methods — no external dependencies.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FotoHubApiClient
{
    private string $apiKey;
    private string $baseUrl = 'https://apis.fotohub.app';
    private int $timeout = 120;

    /**
     * @param string $apiKey FOTOhub API key
     * @param string|null $baseUrl Override base URL (for testing)
     */
    public function __construct(string $apiKey, ?string $baseUrl = null)
    {
        $this->apiKey = $apiKey;

        if ($baseUrl !== null) {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
    }

    /**
     * Generate an image from a text prompt
     *
     * @param string $prompt Text description of the image to generate
     * @param array $options Generation options:
     *   - model (string): Model ID (default: seedream-5-0-260128)
     *   - width (int): Image width in pixels (default: 1024)
     *   - height (int): Image height in pixels (default: 1024)
     *   - negative_prompt (string): What to avoid in the image
     *   - num_images (int): Number of images to generate (1-4)
     * @return array Response with 'image_url' and 'generation_id'
     * @throws PrestaShopException
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $payload = [
            'prompt' => $prompt,
            'model' => $options['model'] ?? 'seedream-5-0-260128',
            'width' => $options['width'] ?? 1024,
            'height' => $options['height'] ?? 1024,
        ];

        if (!empty($options['negative_prompt'])) {
            $payload['negative_prompt'] = $options['negative_prompt'];
        }

        if (!empty($options['num_images'])) {
            $payload['num_images'] = min(4, max(1, (int) $options['num_images']));
        }

        return $this->request('POST', '/v1/images/generate', $payload);
    }

    /**
     * Remove background from an image
     *
     * @param string $imageUrl URL of the image to process
     * @return array Response with 'image_url' of the processed image
     * @throws PrestaShopException
     */
    public function removeBackground(string $imageUrl): array
    {
        return $this->request('POST', '/v1/images/remove-background', [
            'image_url' => $imageUrl,
        ]);
    }

    /**
     * Upscale an image
     *
     * @param string $imageUrl URL of the image to upscale
     * @param int $scale Upscale factor (2 or 4)
     * @return array Response with 'image_url' of the upscaled image
     * @throws PrestaShopException
     */
    public function upscaleImage(string $imageUrl, int $scale = 2): array
    {
        return $this->request('POST', '/v1/images/upscale', [
            'image_url' => $imageUrl,
            'scale' => min(4, max(2, $scale)),
        ]);
    }

    /**
     * Get account credit balance
     *
     * @return array Response with 'credits' balance
     * @throws PrestaShopException
     */
    public function getBalance(): array
    {
        return $this->request('GET', '/v1/account/balance');
    }

    /**
     * List available AI models
     *
     * @param string|null $category Optional filter by category (e.g. 'image', 'video', 'audio', 'chat')
     * @return array Response with 'models' array
     * @throws PrestaShopException
     */
    public function listModels(?string $category = null): array
    {
        $endpoint = '/v1/models';

        if ($category !== null) {
            $endpoint .= '?category=' . urlencode($category);
        }

        return $this->request('GET', $endpoint);
    }

    /**
     * Test the API connection
     *
     * @return bool True if connection is successful
     */
    public function testConnection(): bool
    {
        try {
            $result = $this->getBalance();
            return isset($result['credits']) || isset($result['balance']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Edit an existing image using AI
     *
     * @param string $imageUrl URL of the source image
     * @param string $prompt Edit instructions
     * @param string $mode Edit mode (e.g. 'inpaint', 'outpaint', 'restyle')
     * @param string|null $maskUrl Optional mask image URL for inpainting
     * @return array Response with 'image_url' of the edited image
     * @throws PrestaShopException
     */
    public function editImage(string $imageUrl, string $prompt, string $mode, ?string $maskUrl = null): array
    {
        $payload = [
            'image_url' => $imageUrl,
            'prompt' => $prompt,
            'mode' => $mode,
        ];

        if ($maskUrl !== null) {
            $payload['mask_url'] = $maskUrl;
        }

        return $this->request('POST', '/v1/images/edit', $payload);
    }

    /**
     * Generate a video from a text prompt
     *
     * @param string $prompt Text description of the video to generate
     * @param array $options Generation options:
     *   - model (string): Model ID (default: veo-2)
     *   - duration (int): Video duration in seconds
     *   - aspect_ratio (string): Aspect ratio (e.g. '16:9', '9:16')
     *   - image_url (string): Optional reference image
     * @return array Response with 'job_id' for status polling
     * @throws PrestaShopException
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        $payload = [
            'prompt' => $prompt,
            'model' => $options['model'] ?? 'veo-2',
        ];

        if (!empty($options['duration'])) {
            $payload['duration'] = (int) $options['duration'];
        }

        if (!empty($options['aspect_ratio'])) {
            $payload['aspect_ratio'] = $options['aspect_ratio'];
        }

        if (!empty($options['image_url'])) {
            $payload['image_url'] = $options['image_url'];
        }

        return $this->request('POST', '/v1/videos/generate', $payload);
    }

    /**
     * Check the status of a video generation job
     *
     * @param string $jobId The job ID returned by generateVideo
     * @return array Response with 'status', 'progress', and 'video_url' when complete
     * @throws PrestaShopException
     */
    public function checkVideoStatus(string $jobId): array
    {
        return $this->request('GET', '/v1/videos/status/' . urlencode($jobId));
    }

    /**
     * Generate music from a text prompt
     *
     * @param string $prompt Text description of the music to generate
     * @param array $options Generation options:
     *   - duration (int): Duration in seconds
     *   - model (string): Music model ID
     *   - instrumental (bool): Instrumental only
     * @return array Response with 'audio_url'
     * @throws PrestaShopException
     */
    public function generateMusic(string $prompt, array $options = []): array
    {
        $payload = [
            'prompt' => $prompt,
        ];

        if (!empty($options['duration'])) {
            $payload['duration'] = (int) $options['duration'];
        }

        if (!empty($options['model'])) {
            $payload['model'] = $options['model'];
        }

        if (isset($options['instrumental'])) {
            $payload['instrumental'] = (bool) $options['instrumental'];
        }

        return $this->request('POST', '/v1/audio/music', $payload);
    }

    /**
     * Generate sound effects from a text prompt
     *
     * @param string $prompt Text description of the sound effect
     * @param int $duration Duration in seconds (default: 5)
     * @return array Response with 'audio_url'
     * @throws PrestaShopException
     */
    public function generateSfx(string $prompt, int $duration = 5): array
    {
        return $this->request('POST', '/v1/audio/sfx', [
            'prompt' => $prompt,
            'duration' => $duration,
        ]);
    }

    /**
     * Generate speech from text (text-to-speech)
     *
     * @param string $text Text to convert to speech
     * @param array $options TTS options:
     *   - voice (string): Voice ID
     *   - language (string): Language code
     *   - speed (float): Speech speed multiplier
     * @return array Response with 'audio_url'
     * @throws PrestaShopException
     */
    public function generateSpeech(string $text, array $options = []): array
    {
        $payload = [
            'text' => $text,
        ];

        if (!empty($options['voice'])) {
            $payload['voice'] = $options['voice'];
        }

        if (!empty($options['language'])) {
            $payload['language'] = $options['language'];
        }

        if (!empty($options['speed'])) {
            $payload['speed'] = (float) $options['speed'];
        }

        return $this->request('POST', '/v1/audio/speech', $payload);
    }

    /**
     * Transcribe audio to text
     *
     * @param string $audioUrl URL of the audio file to transcribe
     * @param string $language Language code or 'auto' for detection
     * @return array Response with 'text' transcription
     * @throws PrestaShopException
     */
    public function transcribe(string $audioUrl, string $language = 'auto'): array
    {
        return $this->request('POST', '/v1/audio/transcribe', [
            'audio_url' => $audioUrl,
            'language' => $language,
        ]);
    }

    /**
     * Send a chat completion request
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Chat options:
     *   - model (string): Model ID (default: gemini-flash)
     *   - temperature (float): Sampling temperature
     *   - max_tokens (int): Maximum response tokens
     *   - system (string): System prompt
     * @return array Response with 'choices' array
     * @throws PrestaShopException
     */
    public function chat(array $messages, array $options = []): array
    {
        $payload = [
            'messages' => $messages,
            'model' => $options['model'] ?? 'gemini-flash',
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        if (!empty($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        if (!empty($options['system'])) {
            $payload['system'] = $options['system'];
        }

        return $this->request('POST', '/v1/ai/chat/completions', $payload);
    }

    /**
     * Analyze an image with AI vision
     *
     * @param string $imageUrl URL of the image to analyze
     * @param array $features Features to extract (e.g. ['description', 'tags', 'colors'])
     * @return array Response with analysis results
     * @throws PrestaShopException
     */
    public function analyzeImage(string $imageUrl, array $features = []): array
    {
        $payload = [
            'image_url' => $imageUrl,
        ];

        if (!empty($features)) {
            $payload['features'] = $features;
        }

        return $this->request('POST', '/v1/images/analyze', $payload);
    }

    /**
     * Enhance a text prompt for better image generation
     *
     * @param string $prompt The original prompt to enhance
     * @param string $style Target style (default: 'photographic')
     * @return string The enhanced prompt text
     * @throws PrestaShopException
     */
    public function enhancePrompt(string $prompt, string $style = 'photographic'): string
    {
        $result = $this->request('POST', '/v1/ai/enhance-prompt', [
            'prompt' => $prompt,
            'style' => $style,
        ]);

        return $result['enhanced_prompt'] ?? $result['prompt'] ?? $prompt;
    }

    /**
     * Use a Stability AI tool (e.g. search-and-replace, structure, sketch)
     *
     * @param string $toolId Stability tool identifier
     * @param string $imageBase64 Base64-encoded input image
     * @param array $options Tool-specific options
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityTool(string $toolId, string $imageBase64, array $options = []): array
    {
        $payload = array_merge([
            'image' => $imageBase64,
        ], $options);

        return $this->request('POST', '/stability/' . urlencode($toolId), $payload);
    }

    /**
     * Upscale an image using Stability AI
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param string $type Upscale type: 'fast' or 'creative' (default: 'fast')
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityUpscale(string $imageBase64, string $type = 'fast'): array
    {
        return $this->stabilityTool('upscale', $imageBase64, [
            'type' => $type,
        ]);
    }

    /**
     * Remove background using Stability AI
     *
     * @param string $imageBase64 Base64-encoded input image
     * @return array Response with processed image data (transparent background)
     * @throws PrestaShopException
     */
    public function stabilityRemoveBg(string $imageBase64): array
    {
        return $this->stabilityTool('remove-background', $imageBase64);
    }

    /**
     * Inpaint an image using Stability AI (fill masked area with generated content)
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param string $maskBase64 Base64-encoded mask image (white = area to fill)
     * @param string $prompt Description of what to generate in the masked area
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityInpaint(string $imageBase64, string $maskBase64, string $prompt): array
    {
        return $this->stabilityTool('inpaint', $imageBase64, [
            'mask' => $maskBase64,
            'prompt' => $prompt,
        ]);
    }

    /**
     * Outpaint an image using Stability AI (extend image beyond its borders)
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param array $padding Padding in pixels: ['left' => int, 'right' => int, 'top' => int, 'bottom' => int]
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityOutpaint(string $imageBase64, array $padding): array
    {
        return $this->stabilityTool('outpaint', $imageBase64, [
            'left' => $padding['left'] ?? 0,
            'right' => $padding['right'] ?? 0,
            'top' => $padding['top'] ?? 0,
            'bottom' => $padding['bottom'] ?? 0,
        ]);
    }

    /**
     * Search and replace content in an image using Stability AI
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param string $search Description of what to find in the image
     * @param string $replace Description of what to replace it with
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilitySearchReplace(string $imageBase64, string $search, string $replace): array
    {
        return $this->stabilityTool('search-and-replace', $imageBase64, [
            'search_prompt' => $search,
            'prompt' => $replace,
        ]);
    }

    /**
     * Recolor a specific object in an image using Stability AI
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param string $search Description of the object to recolor
     * @param string $color Target color description (e.g. 'bright red', '#FF0000')
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityRecolor(string $imageBase64, string $search, string $color): array
    {
        return $this->stabilityTool('recolor', $imageBase64, [
            'select_prompt' => $search,
            'prompt' => $color,
        ]);
    }

    /**
     * Transfer style from a reference image using Stability AI
     *
     * @param string $imageBase64 Base64-encoded input image (content source)
     * @param string $referenceBase64 Base64-encoded reference image (style source)
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityStyleTransfer(string $imageBase64, string $referenceBase64): array
    {
        return $this->stabilityTool('style-transfer', $imageBase64, [
            'style_image' => $referenceBase64,
        ]);
    }

    /**
     * Estimate costs for a set of operations before execution
     *
     * @param array $operations Array of operations to estimate, each with 'action' and 'params'
     * @return array Response with 'total_credits' and per-operation costs
     * @throws PrestaShopException
     */
    public function estimateCost(array $operations): array
    {
        return $this->request('POST', '/v1/estimate-cost', [
            'operations' => $operations,
        ]);
    }

    /**
     * Get current pricing for all API operations
     *
     * @return array Response with pricing details per operation
     * @throws PrestaShopException
     */
    public function getPricing(): array
    {
        return $this->request('GET', '/v1/pricing');
    }

    /**
     * Get account transaction history
     *
     * @param int $page Page number (default: 1)
     * @param int $pageSize Number of transactions per page (default: 50)
     * @return array Response with 'transactions' array and pagination info
     * @throws PrestaShopException
     */
    public function getTransactions(int $page = 1, int $pageSize = 50): array
    {
        $query = http_build_query(['page' => $page, 'page_size' => $pageSize]);
        return $this->request('GET', '/v1/account/transactions?' . $query);
    }

    /**
     * Make an HTTP request to the FOTOhub API
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $endpoint API endpoint path
     * @param array|null $payload Request body (for POST)
     * @return array Decoded JSON response
     * @throws PrestaShopException
     */
    private function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FOTOhub-PrestaShop/1.0.0',
        ];

        // Use cURL if available (preferred), otherwise fallback to file_get_contents
        if (function_exists('curl_init')) {
            $response = $this->requestWithCurl($method, $url, $headers, $payload);
        } else {
            $response = $this->requestWithStream($method, $url, $headers, $payload);
        }

        if ($response === false || $response === '') {
            throw new PrestaShopException('FOTOhub API: No response received from server');
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PrestaShopException('FOTOhub API: Invalid JSON response');
        }

        if (isset($decoded['error'])) {
            $errorMsg = is_string($decoded['error']) ? $decoded['error'] : ($decoded['error']['message'] ?? 'Unknown error');
            throw new PrestaShopException('FOTOhub API: ' . $errorMsg);
        }

        return $decoded;
    }

    /**
     * Make request using cURL
     */
    private function requestWithCurl(string $method, string $url, array $headers, ?array $payload): string|false
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new PrestaShopException('FOTOhub API: cURL error — ' . $error);
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($response, true);
            $msg = $decoded['error']['message'] ?? $decoded['error'] ?? "HTTP $httpCode";
            throw new PrestaShopException('FOTOhub API error (' . $httpCode . '): ' . $msg);
        }

        return $response;
    }

    /**
     * Make request using PHP stream context (fallback)
     */
    private function requestWithStream(string $method, string $url, array $headers, ?array $payload): string|false
    {
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];

        if ($method === 'POST' && $payload !== null) {
            $opts['http']['content'] = json_encode($payload);
        }

        $context = stream_context_create($opts);
        $response = Tools::file_get_contents($url, false, $context);

        return $response;
    }
}
