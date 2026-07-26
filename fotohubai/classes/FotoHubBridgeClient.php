<?php
/**
 * FOTOhub Commerce-Bridge Client for PrestaShop
 *
 * Implements the commerce-bridge REST contract
 * (base: https://apis.fotohub.app/v1/commerce, Bearer fh_live_* auth):
 *
 *   POST   /connections            register store connection
 *   GET    /connections            list connections
 *   DELETE /connections/{id}       remove connection
 *   POST   /jobs                   submit bulk job (402 on insufficient credits)
 *   GET    /jobs/{id}              job status
 *   GET    /jobs/{id}/items        per-item results (paginated)
 *   POST   /jobs/{id}/retry-failed requeue failed items
 *   POST   /jobs/{id}/cancel       cancel job
 *   GET    /presets                preset gallery
 *   POST   /estimate               cost preflight
 *   GET    /health                 bridge health check
 *
 * Webhook callbacks are signed with X-FotoHub-Signature =
 * HMAC-SHA256 hex of raw body using the connection callback_secret.
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/FotoHubApiClient.php';

class FotoHubBridgeClient extends FotoHubApiClient
{
    /** Configuration keys used for connection persistence */
    public const CONFIG_CONNECTION_ID = 'FOTOHUBAI_BRIDGE_CONNECTION_ID';
    public const CONFIG_CALLBACK_SECRET = 'FOTOHUBAI_BRIDGE_CALLBACK_SECRET';
    public const CONFIG_DEFAULT_PRESET = 'FOTOHUBAI_DEFAULT_PRESET';

    /** Job kinds accepted by POST /jobs */
    public const JOB_KINDS = [
        'image_generate',
        'image_edit',
        'bg_remove',
        'bg_replace',
        'upscale',
        'recolor',
        'description',
        'alt_text',
        'complete_listing',
    ];

    /** Description tones accepted in options.tone */
    public const TONES = ['professional', 'casual', 'luxury', 'playful', 'technical', 'minimal'];

    /** Languages accepted in options.language */
    public const LANGUAGES = ['en', 'pl', 'de'];

    /** Text fields selectable in options.fields */
    public const TEXT_FIELDS = [
        'title',
        'short_description',
        'description',
        'meta_title',
        'meta_description',
        'alt_text',
        'faq',
        'json_ld',
    ];

    /**
     * Preset category display order — bundles are featured first, then the
     * image-shaping categories, then channel/tone presets.
     */
    public const PRESET_CATEGORY_ORDER = [
        'bundle',
        'background',
        'scene',
        'lighting',
        'composition',
        'channel',
        'vertical',
        'description_tone',
    ];

    /** Configuration key holding the cached preset payload */
    public const CONFIG_PRESET_CACHE = 'FOTOHUBAI_PRESET_CACHE';

    /** Preset cache lifetime in seconds (presets are seeded server-side and rarely change) */
    public const PRESET_CACHE_TTL = 21600; // 6 hours

    /** Image models exposed in plugin UI: id => [label, credits per image] */
    public const IMAGE_MODELS = [
        'seedream-5-0-260128' => ['name' => 'SeedDream 5.0 (Recommended)', 'credits' => 2.0],
        'dola-seedream-5-0-pro-260628' => ['name' => 'SeedDream 5.0 Pro', 'credits' => 3.0],
        'gpt-image-2' => ['name' => 'GPT Image 2', 'credits' => 2.0],
        'nano-banana-pro' => ['name' => 'Nano Banana Pro', 'credits' => 5.3],
        'nano-banana-fast' => ['name' => 'Nano Banana Fast', 'credits' => 2.0],
        'imagen-4-standard' => ['name' => 'Imagen 4 Standard', 'credits' => 3.0],
        'imagen-4-ultra' => ['name' => 'Imagen 4 Ultra', 'credits' => 5.0],
        'imagen-4-fast' => ['name' => 'Imagen 4 Fast', 'credits' => 2.0],
    ];

    /** @var string Commerce-bridge base path (relative to apis.fotohub.app) */
    private string $bridgeBase = '/v1/commerce';

    // ──────────────────────────────────────────────────────────────────────────
    // Connections
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Register this PrestaShop store as a bridge connection.
     *
     * @param string $storeUrl Public URL of the shop
     * @param string $storeName Shop display name
     * @param string|null $callbackUrl Webhook callback URL (module front controller)
     * @param array $settings Optional platform settings blob
     * @return array {id, status, callback_secret}
     * @throws PrestaShopException
     */
    public function registerConnection(string $storeUrl, string $storeName, ?string $callbackUrl = null, array $settings = []): array
    {
        $payload = [
            'platform' => 'prestashop',
            'store_url' => $storeUrl,
            'store_name' => $storeName,
        ];

        if ($callbackUrl !== null) {
            $payload['callback_url'] = $callbackUrl;
        }

        if (!empty($settings)) {
            $payload['settings'] = $settings;
        }

        return $this->request('POST', $this->bridgeBase . '/connections', $payload);
    }

    /**
     * Register the connection AND persist connection_id + callback_secret
     * in PrestaShop Configuration. Idempotent from the module's perspective:
     * an existing stored connection_id is returned without re-registering.
     *
     * @param string $storeUrl Public URL of the shop
     * @param string $storeName Shop display name
     * @param string|null $callbackUrl Webhook callback URL
     * @return string The connection ID
     * @throws PrestaShopException
     */
    public function ensureConnection(string $storeUrl, string $storeName, ?string $callbackUrl = null): string
    {
        $existing = (string) Configuration::get(self::CONFIG_CONNECTION_ID);

        if (!empty($existing)) {
            return $existing;
        }

        $result = $this->registerConnection($storeUrl, $storeName, $callbackUrl);

        if (empty($result['id'])) {
            throw new PrestaShopException('FOTOhub Bridge: Connection registration did not return an id');
        }

        Configuration::updateValue(self::CONFIG_CONNECTION_ID, $result['id']);

        if (!empty($result['callback_secret'])) {
            Configuration::updateValue(self::CONFIG_CALLBACK_SECRET, $result['callback_secret']);
        }

        return (string) $result['id'];
    }

    /**
     * List registered connections for this account
     *
     * @return array Connections response
     * @throws PrestaShopException
     */
    public function listConnections(): array
    {
        return $this->request('GET', $this->bridgeBase . '/connections');
    }

    /**
     * Delete a bridge connection (and clear stored config if it matches)
     *
     * @param string $connectionId Connection ID
     * @return array API response
     * @throws PrestaShopException
     */
    public function deleteConnection(string $connectionId): array
    {
        $result = $this->request('DELETE', $this->bridgeBase . '/connections/' . urlencode($connectionId));

        if ((string) Configuration::get(self::CONFIG_CONNECTION_ID) === $connectionId) {
            Configuration::deleteByName(self::CONFIG_CONNECTION_ID);
            Configuration::deleteByName(self::CONFIG_CALLBACK_SECRET);
        }

        return $result;
    }

    /**
     * Get the stored connection ID (empty string when not registered)
     */
    public static function getStoredConnectionId(): string
    {
        return (string) Configuration::get(self::CONFIG_CONNECTION_ID);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Jobs
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Submit a bulk job to the bridge.
     *
     * Items: [{external_id, sku?, variant_id?, source_image_url?, product_context?}]
     * product_context: {title, category?, attributes?, price?, current_description?}
     * options: {language, tone, brand_rules?, aspect_ratio?, num_images?,
     *           output_format?, background?, recolor_prompt?, target_object?}
     *
     * @param string $kind One of self::JOB_KINDS
     * @param array $items Items array
     * @param array $options Job options
     * @param string|null $model Image model ID (for image kinds)
     * @param string|null $presetSlug Preset slug
     * @param string|null $idempotencyKey Optional idempotency key
     * @return array {job_id, status, total_items, estimated_credits}
     * @throws FotoHubInsufficientCreditsException On HTTP 402
     * @throws PrestaShopException On invalid kind or API errors
     */
    public function createJob(
        string $kind,
        array $items,
        array $options = [],
        ?string $model = null,
        ?string $presetSlug = null,
        ?string $idempotencyKey = null
    ): array {
        if (!in_array($kind, self::JOB_KINDS, true)) {
            throw new PrestaShopException(
                'FOTOhub Bridge: Invalid job kind "' . $kind . '". Allowed: ' . implode(', ', self::JOB_KINDS)
            );
        }

        $connectionId = self::getStoredConnectionId();

        if (empty($connectionId)) {
            throw new PrestaShopException('FOTOhub Bridge: No connection registered. Run the connection wizard first.');
        }

        if (empty($items)) {
            throw new PrestaShopException('FOTOhub Bridge: Job must contain at least one item.');
        }

        $payload = [
            'connection_id' => $connectionId,
            'kind' => $kind,
            'items' => array_values($items),
        ];

        if ($model !== null) {
            $payload['model'] = $model;
        }

        if ($presetSlug !== null && $presetSlug !== '') {
            $payload['preset_slug'] = $presetSlug;
        }

        if (!empty($options)) {
            $payload['options'] = $options;
        }

        if ($idempotencyKey !== null) {
            $payload['idempotency_key'] = $idempotencyKey;
        }

        $response = $this->requestRaw('POST', $this->bridgeBase . '/jobs', $payload);

        if ($response['http_code'] === 402) {
            $body = $response['body'] ?? [];
            throw new FotoHubInsufficientCreditsException(
                (float) ($body['required_credits'] ?? 0),
                (float) ($body['available_credits'] ?? 0)
            );
        }

        if ($response['http_code'] >= 400 || !is_array($response['body'])) {
            $msg = is_array($response['body'])
                ? ($response['body']['error'] ?? $response['body']['detail'] ?? ('HTTP ' . $response['http_code']))
                : ('HTTP ' . $response['http_code']);
            throw new PrestaShopException('FOTOhub Bridge: Job submission failed — ' . (is_string($msg) ? $msg : json_encode($msg)));
        }

        return $response['body'];
    }

    /**
     * Get job status
     *
     * @param string $jobId Job ID
     * @return array {id, status, kind, total_items, done_items, failed_items, spent_credits, estimated_credits}
     * @throws PrestaShopException
     */
    public function getJob(string $jobId): array
    {
        return $this->request('GET', $this->bridgeBase . '/jobs/' . urlencode($jobId));
    }

    /**
     * List items of a job (paginated)
     *
     * @param string $jobId Job ID
     * @param string|null $status Optional status filter
     * @param int $limit Page size
     * @param int $offset Offset
     * @return array {items: [{id, external_id, sku, status, attempts, result, error_message, credits_used}]}
     * @throws PrestaShopException
     */
    public function getJobItems(string $jobId, ?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $query = ['limit' => $limit, 'offset' => $offset];

        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }

        return $this->request(
            'GET',
            $this->bridgeBase . '/jobs/' . urlencode($jobId) . '/items?' . http_build_query($query)
        );
    }

    /**
     * Requeue failed items of a job
     *
     * @param string $jobId Job ID
     * @return array {requeued}
     * @throws PrestaShopException
     */
    public function retryFailed(string $jobId): array
    {
        return $this->request('POST', $this->bridgeBase . '/jobs/' . urlencode($jobId) . '/retry-failed', []);
    }

    /**
     * Cancel a job
     *
     * @param string $jobId Job ID
     * @return array API response
     * @throws PrestaShopException
     */
    public function cancelJob(string $jobId): array
    {
        return $this->request('POST', $this->bridgeBase . '/jobs/' . urlencode($jobId) . '/cancel', []);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Presets & Estimation
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Fetch preset gallery
     *
     * Categories: background, scene, lighting, composition, channel,
     * vertical, description_tone, bundle.
     *
     * @param string|null $category Filter by category
     * @param string|null $vertical Filter by vertical
     * @param string|null $platform Filter by platform
     * @return array {presets: [{slug, name, name_pl, category, description, fragments, thumbnail_url, is_system}]}
     * @throws PrestaShopException
     */
    public function getPresets(?string $category = null, ?string $vertical = null, ?string $platform = null): array
    {
        $query = [];

        if ($category !== null) {
            $query['category'] = $category;
        }

        if ($vertical !== null) {
            $query['vertical'] = $vertical;
        }

        if ($platform !== null) {
            $query['platform'] = $platform;
        }

        $endpoint = $this->bridgeBase . '/presets';

        if (!empty($query)) {
            $endpoint .= '?' . http_build_query($query);
        }

        return $this->request('GET', $endpoint);
    }

    /**
     * Fetch the full preset list through a Configuration-backed cache.
     *
     * The gallery renders on every Bulk page load; without a cache that would
     * be one blocking HTTPS round trip per page view.
     *
     * @param bool $forceRefresh Bypass and repopulate the cache
     * @return array The raw {presets: [...]} response
     * @throws PrestaShopException When the cache is empty and the fetch fails
     */
    public function getPresetsCached(bool $forceRefresh = false): array
    {
        $cached = Configuration::get(self::CONFIG_PRESET_CACHE);

        if (!$forceRefresh && !empty($cached)) {
            $decoded = json_decode($cached, true);

            if (
                is_array($decoded)
                && isset($decoded['fetched_at'], $decoded['response'])
                && (time() - (int) $decoded['fetched_at']) < self::PRESET_CACHE_TTL
            ) {
                return $decoded['response'];
            }
        }

        $response = $this->getPresets(null, null, 'prestashop');

        Configuration::updateValue(self::CONFIG_PRESET_CACHE, json_encode([
            'fetched_at' => time(),
            'response' => $response,
        ]));

        return $response;
    }

    /**
     * Fetch presets grouped by category, with bundle presets first and
     * Polish names substituted when the store locale is Polish.
     *
     * @param string $isoLang Two-letter ISO of the context language ('pl' switches names)
     * @param bool $forceRefresh Bypass the preset cache
     * @return array category => presets[]
     */
    public function getPresetsGrouped(string $isoLang = 'en', bool $forceRefresh = false): array
    {
        $response = $this->getPresetsCached($forceRefresh);
        $presets = $response['presets'] ?? [];
        $grouped = [];

        foreach ($presets as $preset) {
            if (!is_array($preset) || empty($preset['slug'])) {
                continue;
            }

            if ($isoLang === 'pl' && !empty($preset['name_pl'])) {
                $preset['display_name'] = $preset['name_pl'];
            } else {
                $preset['display_name'] = $preset['name'] ?? $preset['slug'];
            }

            $category = $preset['category'] ?? 'other';
            $grouped[$category][] = $preset;
        }

        // Order categories: bundles featured first, then the documented order,
        // then anything the API adds later.
        $ordered = [];

        foreach (self::PRESET_CATEGORY_ORDER as $category) {
            if (!empty($grouped[$category])) {
                $ordered[$category] = $grouped[$category];
                unset($grouped[$category]);
            }
        }

        return $ordered + $grouped;
    }

    /**
     * Cost preflight for a bulk job
     *
     * @param string $kind Job kind
     * @param int $numItems Number of items
     * @param array $options Job options (num_images affects the estimate)
     * @param string|null $model Image model
     * @return array {credits_per_item, total_credits, available_credits, sufficient}
     * @throws PrestaShopException
     */
    public function estimate(string $kind, int $numItems, array $options = [], ?string $model = null): array
    {
        $payload = [
            'kind' => $kind,
            'num_items' => max(1, $numItems),
        ];

        if ($model !== null) {
            $payload['model'] = $model;
        }

        if (!empty($options)) {
            $payload['options'] = $options;
        }

        return $this->request('POST', $this->bridgeBase . '/estimate', $payload);
    }

    /**
     * Bridge health check (GET /health) combined with a balance call.
     *
     * @return array {healthy: bool, bridge: array|null, balance: array|null, error: string|null}
     */
    public function healthCheck(): array
    {
        $status = ['healthy' => false, 'bridge' => null, 'balance' => null, 'error' => null];

        try {
            $status['bridge'] = $this->request('GET', $this->bridgeBase . '/health');
        } catch (Exception $e) {
            $status['error'] = 'Bridge: ' . $e->getMessage();
            return $status;
        }

        try {
            $status['balance'] = $this->getBalance();
        } catch (Exception $e) {
            $status['error'] = 'Balance: ' . $e->getMessage();
            return $status;
        }

        $status['healthy'] = true;

        return $status;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Webhooks
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Verify an incoming webhook signature.
     *
     * X-FotoHub-Signature = HMAC-SHA256 hex of the raw request body,
     * keyed with the connection callback_secret.
     *
     * Events: commerce.item.completed, commerce.job.completed,
     * commerce.job.failed, commerce.job.awaiting_credits.
     *
     * @param string $rawBody Raw request body
     * @param string $signature Value of the X-FotoHub-Signature header
     * @return bool True when the signature is valid
     */
    public static function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) Configuration::get(self::CONFIG_CALLBACK_SECRET);

        if (empty($secret) || empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * Map a plugin bulk action name to a bridge job kind.
     *
     * @param string $action Legacy processor action
     * @return string|null Bridge kind or null when there is no bridge equivalent
     */
    public static function kindForAction(string $action): ?string
    {
        $map = [
            'generate' => 'image_generate',
            'image_generate' => 'image_generate',
            'image_edit' => 'image_edit',
            'remove_background' => 'bg_remove',
            'bg_remove' => 'bg_remove',
            'replace_background' => 'bg_replace',
            'bg_replace' => 'bg_replace',
            'upscale' => 'upscale',
            'recolor' => 'recolor',
            'copywrite' => 'description',
            'description' => 'description',
            'alt_text' => 'alt_text',
            'pipeline' => 'complete_listing',
            'complete_listing' => 'complete_listing',
        ];

        return $map[$action] ?? null;
    }
}

/**
 * Thrown when the bridge rejects a job with HTTP 402 insufficient_credits.
 * Carries the structured amounts so the UI can show a precise message.
 */
class FotoHubInsufficientCreditsException extends PrestaShopException
{
    public float $requiredCredits;
    public float $availableCredits;

    public function __construct(float $requiredCredits, float $availableCredits)
    {
        $this->requiredCredits = $requiredCredits;
        $this->availableCredits = $availableCredits;

        parent::__construct(sprintf(
            'Insufficient credits: %.1f required, %.1f available.',
            $requiredCredits,
            $availableCredits
        ));
    }
}
