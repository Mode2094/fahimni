<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\FreemiusGateway;
use App\Services\FsEntitlementSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Freemius webhook endpoint — the ONLY authoritative activation signal for
 * platform (teacher) subscriptions.
 *
 * Design (mirrors Freemius' official SaaS integration guidance):
 *  - Public route (Freemius sends no Sanctum token). The ONLY protection is
 *    the `x-signature` header verified as HMAC-SHA256 over the RAW request
 *    body with the product secret key, using hash_equals (constant time) —
 *    performed BEFORE parsing or mutating anything.
 *  - Idempotency / replay protection: every event is claimed in
 *    `webhook_events` under the unique (provider, event_id) index before any
 *    side effect. A duplicate or out-of-order redelivery is acknowledged
 *    without reprocessing.
 *  - We never trust the payload's entitlement fields. For every license
 *    event we RE-FETCH the license (and its latest subscription) from the
 *    Freemius API and apply that — this also self-heals out-of-order events.
 *  - `subscription.*` events are intentionally NOT used for entitlements
 *    (per Freemius docs); they are acknowledged and ignored. Only `license.*`
 *    events drive entitlement changes.
 *  - The checkout redirect never activates anything — only a verified webhook
 *    (or a server-side re-fetch) can.
 */
class FreemiusWebhookController extends Controller
{
    public function __construct(
        private readonly FsEntitlementSyncService $sync,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('x-signature', '');

        // 1. Signature FIRST — before parsing or mutating anything.
        if (! FreemiusGateway::verifySignature($rawBody, $signature)) {
            // Do not leak why. 200 is intentionally NOT returned so genuine
            // senders get a clear failure while attackers learn nothing.
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event)) {
            return response()->json(['message' => 'Malformed event.'], 422);
        }

        $eventType = (string) ($event['type'] ?? '');
        $eventId = (string) ($event['id'] ?? '');

        if ($eventType === '' || $eventId === '') {
            return response()->json(['message' => 'Malformed event.'], 422);
        }

        // 2. Idempotency / replay claim before any side effect.
        $recorded = WebhookEvent::claim(
            WebhookEvent::PROVIDER_FREEMIUS,
            $eventId,
            $eventType,
            $rawBody
        );

        if (! $recorded) {
            return response()->json(['message' => 'Duplicate event.']);
        }

        try {
            if (in_array($eventType, FreemiusGateway::ENTITLEMENT_EVENTS, true)) {
                $licenseId = $this->extractLicenseId($event);

                if ($licenseId) {
                    // Re-fetch from the API — never trust the payload body.
                    // syncByLicenseId THROWS on a transient API failure; only
                    // a genuine success (or a definitive not-found) lets us
                    // mark the event processed. This prevents losing a real
                    // payment event on a timeout.
                    $result = $this->sync->syncByLicenseId($licenseId);

                    $recorded->markProcessed();

                    return response()->json(['message' => 'OK', 'result' => $result['status']]);
                }
            } elseif ($eventType === FreemiusGateway::DELETE_EVENT) {
                $licenseId = (string) ($event['data']['license_id'] ?? '');

                if ($licenseId !== '') {
                    $this->sync->deleteByLicenseId($licenseId);
                }
            }
            // All other event types (incl. subscription.*) are acknowledged
            // and ignored for entitlements.

            $recorded->markProcessed();
        } catch (\Throwable $e) {
            // Never leak gateway internals. Leave the event un-processed so
            // Freemius retries — safer than dropping a billing signal. The
            // lock is released so a redelivery / reconciliation can retry.
            $recorded->markFailed($e->getMessage());

            Log::warning('freemius.webhook.processing_error', [
                'payment.provider' => 'freemius',
                'payment.event' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing error.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * Best-effort license id extraction from either payload shape:
     *   license.* → objects.license.id
     *   fallback  → data.license_id  /  data.license.id
     */
    private function extractLicenseId(array $event): ?string
    {
        $id = $event['objects']['license']['id']
            ?? $event['data']['license']['id']
            ?? $event['data']['license_id']
            ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }
}
