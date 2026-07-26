<?php
/**
 * FOTOhub AI Webhook Front Controller
 *
 * Receives commerce-bridge callbacks at /module/fotohubai/webhook.
 * Every request must carry X-FotoHub-Signature = HMAC-SHA256 hex of the
 * raw body signed with the connection callback_secret.
 *
 * Events handled:
 *   commerce.item.completed        → item result stored as pending draft
 *   commerce.job.completed         → full result ingest + stop tracking
 *   commerce.job.failed            → log + stop tracking
 *   commerce.job.awaiting_credits  → log warning, keep tracking
 *
 * @author    FOTOhub <support@fotohub.app>
 * @copyright 2026 FOTOhub
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubBridgeClient.php';
require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubBulkProcessor.php';
require_once _PS_MODULE_DIR_ . 'fotohubai/classes/FotoHubDraft.php';

class FotohubaiWebhookModuleFrontController extends ModuleFrontController
{
    /** @var bool No shop context needed */
    public $ssl = true;

    public function initContent(): void
    {
        $this->respond();
    }

    /**
     * Validate signature, dispatch event, emit JSON response
     */
    private function respond(): void
    {
        header('Content-Type: application/json');

        $rawBody = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_FOTOHUB_SIGNATURE'] ?? '';

        if (!is_string($rawBody) || $rawBody === '') {
            http_response_code(400);
            echo json_encode(['error' => 'empty body']);
            exit;
        }

        if (!FotoHubBridgeClient::verifyWebhookSignature($rawBody, (string) $signature)) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid signature']);
            exit;
        }

        $event = json_decode($rawBody, true);

        if (!is_array($event) || empty($event['event'])) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid payload']);
            exit;
        }

        try {
            $this->handleEvent((string) $event['event'], $event);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('FOTOhub webhook error: ' . $e->getMessage(), 3);
            http_response_code(500);
            echo json_encode(['error' => 'processing failed']);
            exit;
        }

        echo json_encode(['received' => true]);
        exit;
    }

    /**
     * Route a verified event
     */
    private function handleEvent(string $eventName, array $event): void
    {
        $jobId = (string) ($event['job_id'] ?? ($event['data']['job_id'] ?? ''));

        switch ($eventName) {
            case 'commerce.item.completed':
                $this->ingestItem($event['data'] ?? $event, $jobId);
                break;

            case 'commerce.job.completed':
                $this->ingestFullJob($jobId);
                break;

            case 'commerce.job.failed':
                PrestaShopLogger::addLog('FOTOhub bridge job failed: ' . $jobId, 3);
                if (!empty($jobId)) {
                    FotoHubBulkProcessor::forgetJob($jobId);
                }
                break;

            case 'commerce.job.awaiting_credits':
                PrestaShopLogger::addLog(
                    'FOTOhub bridge job ' . $jobId . ' is awaiting credits — top up your FOTOhub account to resume.',
                    2
                );
                break;

            default:
                // Unknown events are acknowledged silently (forward compatible)
                break;
        }
    }

    /**
     * Store a single completed item result as a pending draft.
     *
     * Delegates to the shared ingest so external_id parsing (product vs
     * product:variant) and bridge_item_id deduplication behave exactly as they
     * do on the cron poll path — a webhook and a poll for the same item must
     * not create two drafts.
     */
    private function ingestItem(array $data, string $jobId): void
    {
        $item = $data['item'] ?? $data;

        if (!is_array($item)) {
            return;
        }

        $kind = (string) ($data['kind'] ?? ($item['kind'] ?? ''));

        FotoHubBulkProcessor::ingestItemResult($item, $jobId ?: null, $kind ?: null);
    }

    /**
     * On job completion: run the same poll/ingest path used by the cron so
     * item pagination and job forgetting stay in one place.
     */
    private function ingestFullJob(string $jobId): void
    {
        if (empty($jobId)) {
            return;
        }

        $module = Module::getInstanceByName('fotohubai');

        if (!$module) {
            return;
        }

        $apiKey = $module->getDecryptedApiKey();

        if (empty($apiKey)) {
            return;
        }

        $bridge = new FotoHubBridgeClient($apiKey);
        FotoHubBulkProcessor::pollBridgeJobs($bridge);
    }
}
