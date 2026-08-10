<?php
/**
 * FOTOhub API Client for PrestaShop
 *
 * Handles all communication with the FOTOhub AI API (apis.fotohub.app).
 * Uses PrestaShop's native HTTP methods — no external dependencies.
 *
 * Endpoint map verified against api-server routes (2026-07-26):
 *   image generate  POST /v1/ai/generate/image
 *   image edit      POST /v1/ai/edit/image
 *   image analyze   POST /v1/ai/analyze/image
 *   bg remove       POST /v1/images/remove-background
 *   bg replace      POST /v1/images/replace-background
 *   upscale         POST /stability/fast-upscale (base64 contract)
 *   video generate  POST /v1/ai/generate/video
 *   video status    GET  /v1/ai/generate/video/{id}
 *   music           POST /v1/ai/generate/music
 *   sfx             POST /v1/ai/generate/sfx
 *   speech          POST /v1/ai/generate/speech
 *   transcribe      POST /v1/ai/transcribe
 *   chat            POST /v1/ai/chat/completions
 *   enhance prompt  POST /v1/ai/enhance-prompt
 *   balance         GET  /v1/billing/balance
 *   transactions    GET  /v1/billing/transactions
 *   pricing         GET  /v1/billing/pricing
 *   estimate        POST /v1/billing/estimate
 *   models          GET  /v1/models
 *   stability tools POST /stability/{tool_id}
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
    /** Chat models accepted by /v1/ai/chat/completions — anything else is rejected with 400 */
    public const CHAT_MODELS = ['gemini-flash', 'gemini-pro', 'gpt-4o', 'claude-sonnet'];

    protected string $apiKey;
    protected string $baseUrl = 'https://apis.fotohub.app';
    protected int $timeout = 120;

    /**
     * @param string $apiKey FOTOhub API key (fh_live_* / fh_test_*)
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
     *   - aspect_ratio (string): '1:1' | '16:9' | '9:16' | '4:3' | '3:4'
     *   - negative_prompt (string): What to avoid in the image
     *   - num_images (int): Number of images to generate (1-4)
     *   - seed (int): Optional deterministic seed
     * @return array Response with 'images' array; 'image_url' normalized to first image
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

        if (!empty($options['aspect_ratio'])) {
            $payload['aspect_ratio'] = $options['aspect_ratio'];
        }

        if (!empty($options['negative_prompt'])) {
            $payload['negative_prompt'] = $options['negative_prompt'];
        }

        if (!empty($options['num_images'])) {
            $payload['num_images'] = min(4, max(1, (int) $options['num_images']));
        }

        if (isset($options['seed'])) {
            $payload['seed'] = (int) $options['seed'];
        }

        $result = $this->request('POST', '/v1/ai/generate/image', $payload);

        return $this->normalizeImageResult($result);
    }

    /**
     * Remove background from an image (2 credits)
     *
     * @param string $imageUrl URL of the image to process
     * @return array Response with 'output_url'; 'image_url' normalized
     * @throws PrestaShopException
     */
    public function removeBackground(string $imageUrl): array
    {
        $result = $this->request('POST', '/v1/images/remove-background', [
            'image_url' => $imageUrl,
        ]);

        if (empty($result['image_url']) && !empty($result['output_url'])) {
            $result['image_url'] = $result['output_url'];
        }

        return $result;
    }

    /**
     * Remove background and composite the subject onto a new background (4 credits)
     *
     * @param string $imageUrl URL of the source image
     * @param string $background Color (#hex), gradient, image URL, or text prompt
     * @param string $backgroundType 'auto' | 'color' | 'gradient' | 'image' | 'prompt'
     * @return array Response with 'output_url'; 'image_url' normalized
     * @throws PrestaShopException
     */
    public function replaceBackground(string $imageUrl, string $background, string $backgroundType = 'auto'): array
    {
        $result = $this->request('POST', '/v1/images/replace-background', [
            'image_url' => $imageUrl,
            'background' => $background,
            'background_type' => $backgroundType,
        ]);

        if (empty($result['image_url']) && !empty($result['output_url'])) {
            $result['image_url'] = $result['output_url'];
        }

        return $result;
    }

    /**
     * Upscale an image via Stability fast-upscale (base64 contract)
     *
     * The /stability/fast-upscale endpoint takes a base64 image body and returns
     * base64 output. This method downloads the source URL, encodes it, and
     * normalizes the response into a data URI in 'image_url' so downstream
     * save-to-product logic works unchanged.
     *
     * @param string $imageUrl URL of the image to upscale
     * @param int $scale Kept for backward compatibility (fast-upscale output is fixed)
     * @return array Response with 'image' (base64) and normalized 'image_url' data URI
     * @throws PrestaShopException
     */
    public function upscaleImage(string $imageUrl, int $scale = 2): array
    {
        $imageContent = Tools::file_get_contents($imageUrl);

        if (empty($imageContent)) {
            throw new PrestaShopException('FOTOhub API: Could not download source image for upscale');
        }

        $result = $this->stabilityTool('fast-upscale', base64_encode($imageContent), [
            'output_format' => 'png',
        ]);

        if (!empty($result['image'])) {
            $result['image_url'] = 'data:image/png;base64,' . $result['image'];
        }

        return $result;
    }

    /**
     * Get account credit balance
     *
     * Response shape: {tier, credits: {remaining_period, ...}, wallet: {...}, overage: {...}}
     *
     * @return array Balance response
     * @throws PrestaShopException
     */
    public function getBalance(): array
    {
        return $this->request('GET', '/v1/billing/balance');
    }

    /**
     * Convenience: extract available credits as a float from getBalance()
     *
     * @return float Available credits for the current period
     */
    public function getCreditsAvailable(): float
    {
        $balance = $this->getBalance();

        if (isset($balance['credits_available'])) {
            return (float) $balance['credits_available'];
        }

        if (isset($balance['credits']['remaining_period'])) {
            return (float) $balance['credits']['remaining_period'];
        }

        if (isset($balance['credits']) && is_numeric($balance['credits'])) {
            return (float) $balance['credits'];
        }

        return 0.0;
    }

    /**
     * List available AI models
     *
     * @param string|null $category Optional filter (e.g. 'image', 'video', 'audio', 'text')
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
            return isset($result['credits']) || isset($result['tier']) || isset($result['balance']);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Edit an existing image using AI
     *
     * @param string $imageUrl URL of the source image
     * @param string $prompt Edit instructions
     * @param string $mode Edit mode: 'inpaint' | 'outpaint' | 'bgswap' | 'remove'
     * @param string|null $maskUrl Optional mask image URL for inpainting
     * @return array Response with 'images' array; 'image_url' normalized
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

        $result = $this->request('POST', '/v1/ai/edit/image', $payload);

        return $this->normalizeImageResult($result);
    }

    /**
     * Generate a video from a text prompt
     *
     * @param string $prompt Text description of the video to generate
     * @param array $options Generation options:
     *   - model (string): Model ID (default: veo-3.1-fast-generate-001)
     *   - duration (int): Video duration in seconds
     *   - aspect_ratio (string): '16:9' | '9:16' | '1:1'
     *   - image_url (string): Optional reference image (img2vid)
     *   - resolution (string): '720p' | '1080p' | '4k'
     * @return array Response with 'video_url' and/or 'job_id' for status polling
     * @throws PrestaShopException
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        $payload = [
            'prompt' => $prompt,
            'model' => $options['model'] ?? 'veo-3.1-fast-generate-001',
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

        if (!empty($options['resolution'])) {
            $payload['resolution'] = $options['resolution'];
        }

        return $this->request('POST', '/v1/ai/generate/video', $payload);
    }

    /**
     * Check the status of a video generation job.
     *
     * IMPORTANT: POST /v1/ai/generate/video is synchronous — it blocks and
     * returns 'video_url' in the response. There is currently NO polling route
     * on api-server (a GET on /v1/ai/generate/video/{id} returns 404), so this
     * method reports 'unknown' rather than throwing on a phantom endpoint.
     * Callers should treat a populated video_url from generateVideo() as final.
     *
     * @param string $jobId The job ID returned by generateVideo
     * @return array {status, job_id, message}
     */
    public function checkVideoStatus(string $jobId): array
    {
        return [
            'status' => 'unknown',
            'job_id' => $jobId,
            'message' => 'Video generation is synchronous; no status endpoint is available.',
        ];
    }

    /**
     * Generate music from a text prompt
     *
     * @param string $prompt Text description of the music to generate
     * @param array $options Generation options:
     *   - duration (int): Duration in seconds
     *   - model (string): 'minimax' | 'elevenlabs'
     *   - instrumental (bool): Instrumental only
     *   - genre / mood (string): Optional style hints
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

        if (!empty($options['genre'])) {
            $payload['genre'] = $options['genre'];
        }

        if (!empty($options['mood'])) {
            $payload['mood'] = $options['mood'];
        }

        return $this->request('POST', '/v1/ai/generate/music', $payload);
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
        return $this->request('POST', '/v1/ai/generate/sfx', [
            'prompt' => $prompt,
            'duration' => $duration,
        ]);
    }

    /**
     * Generate speech from text (text-to-speech)
     *
     * @param string $text Text to convert to speech
     * @param array $options TTS options:
     *   - voice (string): Voice ID (sent as voice_id)
     *   - model (string): 'google' | 'elevenlabs'
     *   - language (string): 'pl' | 'en' | 'de'
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
            $payload['voice_id'] = $options['voice'];
        }

        if (!empty($options['voice_id'])) {
            $payload['voice_id'] = $options['voice_id'];
        }

        if (!empty($options['model'])) {
            $payload['model'] = $options['model'];
        }

        if (!empty($options['language'])) {
            $payload['language'] = $options['language'];
        }

        if (!empty($options['speed'])) {
            $payload['speed'] = (float) $options['speed'];
        }

        return $this->request('POST', '/v1/ai/generate/speech', $payload);
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
        return $this->request('POST', '/v1/ai/transcribe', [
            'audio_url' => $audioUrl,
            'language' => $language,
        ]);
    }

    /**
     * Send a chat completion request (OpenAI-compatible format)
     *
     * Supported models ONLY: gemini-flash | gemini-pro | gpt-4o | claude-sonnet.
     * Unknown models are rejected by the API with HTTP 400, so anything else
     * is coerced to gemini-flash. A 'system' option is prepended as a system
     * message because the endpoint has no top-level system parameter.
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Chat options:
     *   - model (string): Model ID (default: gemini-flash)
     *   - temperature (float): Sampling temperature
     *   - max_tokens (int): Maximum response tokens
     *   - system (string): System prompt (prepended as a system message)
     * @return array Response with 'choices' array
     * @throws PrestaShopException
     */
    public function chat(array $messages, array $options = []): array
    {
        $model = $options['model'] ?? 'gemini-flash';

        if (!in_array($model, self::CHAT_MODELS, true)) {
            $model = 'gemini-flash';
        }

        if (!empty($options['system'])) {
            $hasSystem = false;
            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'system') {
                    $hasSystem = true;
                    break;
                }
            }
            if (!$hasSystem) {
                array_unshift($messages, ['role' => 'system', 'content' => $options['system']]);
            }
        }

        $payload = [
            'messages' => $messages,
            'model' => $model,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        if (!empty($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        return $this->request('POST', '/v1/ai/chat/completions', $payload);
    }

    /**
     * Analyze an image with AI vision
     *
     * Results come back at the top level keyed by feature name ('labels',
     * 'objects', 'faces', 'nsfw', 'ocr', 'colors', 'landmarks', 'logos') plus a
     * flat 'auto_tags' list. There is no 'analysis' wrapper. Only those feature
     * names are accepted; anything else is rejected with HTTP 400.
     *
     * @param string $imageUrl URL of the image to analyze
     * @param array $features Features: labels, objects, faces, nsfw, ocr, colors, landmarks, logos
     * @return array Response with per-feature keys and 'auto_tags'
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

        return $this->request('POST', '/v1/ai/analyze/image', $payload);
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
     * Use a Stability AI tool (e.g. search-replace, control-structure, control-sketch)
     *
     * Valid tool IDs: fast-upscale, creative-upscale, conservative-upscale,
     * remove-background, erase-object, inpaint, outpaint, search-replace,
     * search-recolor, style-transfer, style-guide, control-sketch, control-structure.
     *
     * @param string $toolId Stability tool identifier
     * @param string $imageBase64 Base64-encoded input image
     * @param array $options Tool-specific options (mask, prompt, search_prompt, reference, ...)
     * @return array Response with 'image' (base64), 'tool', 'credits_used'
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
     * List available Stability AI tools with pricing
     *
     * @return array Response with 'tools' array
     * @throws PrestaShopException
     */
    public function stabilityListTools(): array
    {
        return $this->request('GET', '/stability/tools');
    }

    /**
     * Upscale an image using Stability AI
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param string $type Upscale type: 'fast', 'creative' or 'conservative'
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityUpscale(string $imageBase64, string $type = 'fast'): array
    {
        $toolId = in_array($type, ['fast', 'creative', 'conservative'], true)
            ? $type . '-upscale'
            : 'fast-upscale';

        return $this->stabilityTool($toolId, $imageBase64);
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
     * The stability contract uses left/right/up/down directional padding keys.
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param array $padding Padding in pixels: ['left' => int, 'right' => int, 'up' => int, 'down' => int]
     *                       (legacy 'top'/'bottom' keys are mapped to up/down)
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityOutpaint(string $imageBase64, array $padding): array
    {
        return $this->stabilityTool('outpaint', $imageBase64, [
            'left' => (int) ($padding['left'] ?? 0),
            'right' => (int) ($padding['right'] ?? 0),
            'up' => (int) ($padding['up'] ?? $padding['top'] ?? 0),
            'down' => (int) ($padding['down'] ?? $padding['bottom'] ?? 0),
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
        return $this->stabilityTool('search-replace', $imageBase64, [
            'search_prompt' => $search,
            'prompt' => $replace,
        ]);
    }

    /**
     * Recolor a specific object in an image using Stability AI
     *
     * Tool id is 'search-recolor'; the target object goes in search_prompt
     * and the color in prompt (per stability route contract).
     *
     * @param string $imageBase64 Base64-encoded input image
     * @param string $search Description of the object to recolor
     * @param string $color Target color description (e.g. 'bright red', '#FF0000')
     * @return array Response with processed image data
     * @throws PrestaShopException
     */
    public function stabilityRecolor(string $imageBase64, string $search, string $color): array
    {
        return $this->stabilityTool('search-recolor', $imageBase64, [
            'search_prompt' => $search,
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
            'reference' => $referenceBase64,
        ]);
    }

    /**
     * Estimate costs for a set of operations before execution
     *
     * @param array $operations Operations to estimate, each:
     *   {"type": "generate_image", "model": "imagen-4-standard", "count": 5, "duration": 0}
     * @return array Response with 'total_credits', 'total_pln' and per-operation 'breakdown'
     * @throws PrestaShopException
     */
    public function estimateCost(array $operations): array
    {
        return $this->request('POST', '/v1/billing/estimate', [
            'operations' => $operations,
        ]);
    }

    /**
     * Get current pricing for all API operations
     *
     * @return array Response with 'pricing', 'credit_costs', 'currency'
     * @throws PrestaShopException
     */
    public function getPricing(): array
    {
        return $this->request('GET', '/v1/billing/pricing');
    }

    /**
     * Get account transaction history
     *
     * @param int $page Page number (default: 1)
     * @param int $pageSize Number of transactions per page (default: 50, max 200)
     * @return array Response with 'data' array and pagination info
     * @throws PrestaShopException
     */
    public function getTransactions(int $page = 1, int $pageSize = 50): array
    {
        $query = http_build_query(['page' => $page, 'pageSize' => $pageSize]);
        return $this->request('GET', '/v1/billing/transactions?' . $query);
    }

    /**
     * Normalize an image generation/edit result: expose 'image_url' from the
     * 'images' array so save-to-product code has a single key to read.
     * Base64 payloads are wrapped as data URIs.
     */
    protected function normalizeImageResult(array $result): array
    {
        if (!empty($result['image_url'])) {
            return $result;
        }

        $candidates = [];

        if (!empty($result['images']) && is_array($result['images'])) {
            $candidates = $result['images'];
        } elseif (!empty($result['urls']) && is_array($result['urls'])) {
            $candidates = $result['urls'];
        } elseif (!empty($result['url'])) {
            $candidates = [$result['url']];
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (strpos($candidate, 'http') === 0 || strpos($candidate, 'data:') === 0) {
                $result['image_url'] = $candidate;
            } else {
                // Raw base64 payload
                $result['image_url'] = 'data:image/png;base64,' . $candidate;
            }
            break;
        }

        return $result;
    }

    /**
     * Make an HTTP request to the FOTOhub API
     *
     * @param string $method HTTP method (GET, POST, DELETE)
     * @param string $endpoint API endpoint path
     * @param array|null $payload Request body (for POST)
     * @param array $extraHeaders Additional headers (e.g. Idempotency-Key)
     * @return array Decoded JSON response
     * @throws PrestaShopException
     */
    protected function request(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array
    {
        $response = $this->requestRaw($method, $endpoint, $payload, $extraHeaders);

        if ($response['http_code'] >= 400) {
            $decoded = $response['body'];
            $msg = 'HTTP ' . $response['http_code'];

            if (is_array($decoded)) {
                if (isset($decoded['detail'])) {
                    $msg = is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']);
                } elseif (isset($decoded['error'])) {
                    $msg = is_string($decoded['error']) ? $decoded['error'] : ($decoded['error']['message'] ?? $msg);
                } elseif (isset($decoded['message'])) {
                    $msg = $decoded['message'];
                }
            }

            throw new PrestaShopException('FOTOhub API error (' . $response['http_code'] . '): ' . $msg);
        }

        $decoded = $response['body'];

        if (!is_array($decoded)) {
            throw new PrestaShopException('FOTOhub API: Invalid JSON response');
        }

        if (isset($decoded['error']) && $decoded['error']) {
            $errorMsg = is_string($decoded['error']) ? $decoded['error'] : ($decoded['error']['message'] ?? 'Unknown error');
            throw new PrestaShopException('FOTOhub API: ' . $errorMsg);
        }

        return $decoded;
    }

    /**
     * Make an HTTP request and return status + decoded body without throwing
     * on 4xx (needed for structured 402 insufficient_credits handling).
     *
     * @return array{http_code: int, body: array|null}
     * @throws PrestaShopException On transport failure
     */
    protected function requestRaw(string $method, string $endpoint, ?array $payload = null, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $endpoint;

        $headers = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: FOTOhub-PrestaShop/2.1.0',
        ], $extraHeaders);

        if (function_exists('curl_init')) {
            $result = $this->requestWithCurl($method, $url, $headers, $payload);
        } else {
            $result = $this->requestWithStream($method, $url, $headers, $payload);
        }

        if ($result['response'] === false || $result['response'] === '') {
            throw new PrestaShopException('FOTOhub API: No response received from server');
        }

        $decoded = json_decode($result['response'], true);

        return [
            'http_code' => $result['http_code'],
            'body' => is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * Make request using cURL
     *
     * @return array{response: string|false, http_code: int}
     * @throws PrestaShopException
     */
    private function requestWithCurl(string $method, string $url, array $headers, ?array $payload): array
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
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new PrestaShopException('FOTOhub API: cURL error — ' . $error);
        }

        return ['response' => $response, 'http_code' => $httpCode];
    }

    /**
     * Make request using PHP stream context (fallback)
     *
     * $http_response_header is only populated in the scope that calls
     * file_get_contents(), so this method must call it directly rather than
     * going through Tools::file_get_contents() — otherwise the status line is
     * invisible here and every response looks like HTTP 200, which would make
     * the structured 402 insufficient_credits handling silently unreachable.
     *
     * @return array{response: string|false, http_code: int}
     */
    private function requestWithStream(string $method, string $url, array $headers, ?array $payload): array
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

        if ($method !== 'GET' && $payload !== null) {
            $opts['http']['content'] = json_encode($payload);
        }

        $context = stream_context_create($opts);

        // phpcs:ignore -- direct call required so $http_response_header lands in this scope
        $response = @file_get_contents($url, false, $context);

        $httpCode = 0;

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                    $httpCode = (int) $matches[1];
                }
            }
        }

        return ['response' => $response, 'http_code' => $httpCode ?: 200];
    }
}
