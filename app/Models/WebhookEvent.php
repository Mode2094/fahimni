<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Provider webhook events (idempotency ledger).
 *
 * One row per (provider, event_id) — the unique index makes concurrent or
 * replayed deliveries of the same event a no-op. Rows are written inside the
 * webhook HTTP request (after signature verification) and the processing
 * outcome is recorded afterwards.
 */
class WebhookEvent extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'provider', 'event_id', 'event_type', 'payload_hash',
        'payload', 'received_at', 'processed_at',
        'processing_status', 'failure_reason',
        'attempts', 'locked_until', 'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'locked_until' => 'datetime',
        'attempts' => 'integer',
    ];

    public const PROVIDER_LEMON_SQUEEZY = 'lemonsqueezy';

    public const PROVIDER_FREEMIUS = 'freemius';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /** How long an in-flight processing lock is held before it can be stolen. */
    public const LOCK_SECONDS = 120;

    /**
     * Atomically claim a webhook event for processing.
     *
     * Returns the model when this delivery may proceed, or null when it must
     * be acknowledged without reprocessing:
     *  - already PROCESSED            → duplicate, ack
     *  - currently locked (PROCESSING) → concurrent, ack
     *  - first delivery                → claim
     *  - previously FAILED / stale     → re-claim for a safe retry
     *
     * The lock prevents two concurrent deliveries of the same event from
     * processing at once; the unique (provider, event_id) index still guards
     * first-seen inserts under concurrency.
     */
    public static function claim(string $provider, string $eventId, string $eventType, string $rawBody): ?self
    {
        $inserted = static::query()->insertOrIgnore([
            'id' => UuidV7::generate(),
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload_hash' => hash('sha256', $rawBody),
            'payload' => json_encode(json_decode($rawBody, true)),
            'received_at' => now(),
            'processing_status' => self::STATUS_PROCESSING,
            'attempts' => 1,
            'locked_until' => now()->addSeconds(self::LOCK_SECONDS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 1) {
            return static::where('provider', $provider)->where('event_id', $eventId)->first();
        }

        return static::reclaim($provider, $eventId);
    }

    /**
     * Attempt to take ownership of an already-recorded event whose previous
     * attempt failed or whose lock went stale. Returns null when the event is
     * already processed or is currently locked (concurrent delivery).
     */
    public static function reclaim(string $provider, string $eventId): ?self
    {
        // Only re-claim when NOT currently locked and NOT already processed.
        $claimed = static::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->where('processing_status', '!=', self::STATUS_PROCESSED)
            ->where(function ($q) {
                $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->update([
                'processing_status' => self::STATUS_PROCESSING,
                'locked_until' => now()->addSeconds(self::LOCK_SECONDS),
                'attempts' => \Illuminate\Support\Facades\DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        return static::where('provider', $provider)->where('event_id', $eventId)->first();
    }

    public function markProcessed(): void
    {
        $this->update([
            'processing_status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'failure_reason' => null,
            'last_error' => null,
            'locked_until' => null,
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'processing_status' => self::STATUS_FAILED,
            'failure_reason' => substr($reason, 0, 490),
            'last_error' => substr($reason, 0, 490),
            // Release the lock so a redelivery / reconciliation can retry.
            'locked_until' => null,
        ]);
    }

    /**
     * Events that still need processing (failed, or stuck processing past
     * their lock) — used by the reconciliation job.
     */
    public static function scopeUnresolved($query)
    {
        return $query->where('processing_status', '!=', self::STATUS_PROCESSED)
            ->where(function ($q) {
                $q->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            });
    }
}
