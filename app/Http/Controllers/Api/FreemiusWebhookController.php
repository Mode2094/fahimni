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

                if (! $licenseId) {
                    // A license.* event with no resolvable license id is
                    // malformed for our purposes: keep it for manual review
                    // instead of silently marking it processed.
                    return $this->holdForReview(
                        $recorded,
                        'missing_license_id',
                        422,
                    );
                }

                // Re-fetch from the API — never trust the payload body.
                // syncByLicenseId THROWS on a transient API failure.
                $result = $this->sync->syncByLicenseId($licenseId);

                return match ($result['status']) {
                    // Fully applied — safe to mark processed.
                    'synced' => $this->acknowledge($recorded, $result['status']),

                    // Definitive: Freemius has no such license (404). Nothing
                    // to apply and nothing to retry → mark processed.
                    'not_found' => $this->acknowledge($recorded, $result['status']),

                    // License exists but no local tenant matches (unknown
                    // customer, or a customer with no/mismatched local
                    // account). This needs MANUAL REVIEW — never mark
                    // processed, never lose the event.
                    'no_tenant' => $this->holdForReview($recorded, 'no_tenant', 202),

                    default => $this->holdForReview($recorded, 'unresolved', 202),
                };
            }

            if ($eventType === FreemiusGateway::DELETE_EVENT) {
                $licenseId = (string) ($event['data']['license_id'] ?? '');

                if ($licenseId !== '') {
                    $this->sync->deleteByLicenseId($licenseId);
                }

                return $this->acknowledge($recorded, 'deleted');
            }

            // All other event types (incl. subscription.*) are acknowledged
            // and ignored for entitlements.
            return $this->acknowledge($recorded, 'ignored');
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
    }

    /**
     * Mark an event fully resolved and acknowledge it to the sender.
     */
    private function acknowledge(WebhookEvent $event, string $result): JsonResponse
    {
        $event->markProcessed();

        return response()->json(['message' => 'OK', 'result' => $result]);
    }

    /**
     * Keep an event in an unresolved state for reconciliation/manual review.
     *
     * It is NOT marked processed (so it is never treated as a success), the
     * lock is released so the reconciliation job can retry it a bounded number
     * of times, and it is surfaced via `failed` + a recognizable reason for
     * operator review. We still return 2xx so Freemius does not spam-retry a
     * situation that only a human can resolve.
     */
    private function holdForReview(WebhookEvent $event, string $reason, int $status): JsonResponse
    {
        $event->markFailed($reason);

        Log::warning('freemius.webhook.needs_review', [
            'payment.provider' => 'freemius',
            'payment.event' => $event->event_type,
            'event_id' => $event->event_id,
            'reason' => $reason,
        ]);

        return response()->json(['message' => 'Pending review.', 'result' => $reason], $status);
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
