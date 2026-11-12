<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\FreemiusGateway;
use App\Services\FsEntitlementSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles Freemius webhook events that are stuck in `failed` (or a stale
 * `processing` lock) — e.g. because the Freemius API was temporarily
 * unreachable when the webhook first arrived.
 *
 * Safety:
 *  - Only Freemius license events are reconciled here.
 *  - Each event is re-claimed under the same lock, so it can never be
 *    processed twice concurrently.
 *  - The entitlement write is idempotent (upsert by fs_license_id), so a
 *    retry never double-applies money or access.
 *  - A bounded number of attempts prevents an infinite loop; exhausted events
 *    stay `failed` and are surfaced via logs for alerting.
 */
class ReconcileFreemiusWebhooksJob implements ShouldQueue
{
    use Queueable;

    /** Max reconciliation attempts before an event is left for manual review. */
    public const MAX_ATTEMPTS = 12;

    public const BATCH = 50;

    public function __construct(
        private readonly ?int $limit = null,
    ) {}

    public function handle(FsEntitlementSyncService $sync): void
    {
        if (! FreemiusGateway::enabled() || ! FreemiusGateway::configured()) {
            return;
        }

        $limit = $this->limit ?? self::BATCH;

        $stuck = WebhookEvent::query()
            ->where('provider', WebhookEvent::PROVIDER_FREEMIUS)
            ->unresolved()
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('received_at')
            ->limit($limit)
            ->get();

        $reconciled = 0;
        $stillFailed = 0;

        foreach ($stuck as $event) {
            // Re-claim under the lock (skips events another worker holds).
            $claimed = WebhookEvent::reclaim(
                WebhookEvent::PROVIDER_FREEMIUS,
                $event->event_id,
            );

            if (! $claimed) {
                continue;
            }

            try {
                if (in_array($claimed->event_type, FreemiusGateway::ENTITLEMENT_EVENTS, true)) {
                    $licenseId = $this->licenseIdFromPayload($claimed->payload ?? []);

                    if ($licenseId) {
                        $sync->syncByLicenseId($licenseId);
                    }
                } elseif ($claimed->event_type === FreemiusGateway::DELETE_EVENT) {
                    $licenseId = (string) ($claimed->payload['data']['license_id'] ?? '');

                    if ($licenseId !== '') {
                        $sync->deleteByLicenseId($licenseId);
                    }
                }

                $claimed->markProcessed();
                $reconciled++;
            } catch (\Throwable $e) {
                $claimed->markFailed($e->getMessage());
                $stillFailed++;

                Log::warning('freemius.reconcile.retry_failed', [
                    'event_id' => $claimed->event_id,
                    'event_type' => $claimed->event_type,
                    'attempts' => $claimed->attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Surface events that exhausted their retries for alerting.
        $exhausted = WebhookEvent::query()
            ->where('provider', WebhookEvent::PROVIDER_FREEMIUS)
            ->where('processing_status', WebhookEvent::STATUS_FAILED)
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->count();

        Log::info('freemius.reconcile.done', [
            'reconciled' => $reconciled,
            'still_failed' => $stillFailed,
            'exhausted' => $exhausted,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function licenseIdFromPayload(array $payload): ?string
    {
        $id = $payload['objects']['license']['id']
            ?? $payload['data']['license']['id']
            ?? $payload['data']['license_id']
            ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }
}
