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
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public const PROVIDER_LEMON_SQUEEZY = 'lemonsqueezy';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    /**
     * Atomically claim a webhook event. Returns the model when this is the
     * first time the (provider, event_id) pair is seen, or null when the
     * event was already recorded (duplicate delivery — caller must ack
     * without reprocessing).
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
            'processing_status' => self::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted !== 1) {
            return null;
        }

        return static::where('provider', $provider)
            ->where('event_id', $eventId)
            ->first();
    }

    public function markProcessed(): void
    {
        $this->update([
            'processing_status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'failure_reason' => null,
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'processing_status' => self::STATUS_FAILED,
            'failure_reason' => substr($reason, 0, 490),
        ]);
    }
}
