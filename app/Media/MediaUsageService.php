<?php

namespace App\Media;

use App\Models\MediaUsageAggregate;
use App\Models\MediaUsageLedger;
use App\Models\MediaUsageMonthly;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * محرك الاستخدام — Ledger (مصدر الحقيقة) + Aggregates (عدادات حالية) +
 * Monthly (للتقارير والتكلفة). كل تعديل يمر عبر دالة واحدة applyDelta حتى
 * يبقى الـ Ledger والعدادات متزامنين دائماً، مع إبطال كاش الاستخدام فوراً.
 */
class MediaUsageService
{
    public const RESOURCES = [
        'storage_bytes', 'video_count', 'live_sessions_count', 'live_minutes',
        'recording_count', 'files_count', 'delivery_minutes',
    ];

    private const AGG_CACHE_KEY = 'media:usage:agg:';

    /**
     * طبّق تغييراً على استخدام معلم/مؤسسة — داخل معاملة واحدة مع الـ Ledger.
     *
     * @param  array<string, mixed>  $metadata
     * @param  string|null  $idempotencyKey  مفتاح يمنع احتساب نفس الحدث مرتين
     *                                       (إيقاف بث + webhook يصلان معاً).
     */
    public function applyDelta(
        string $tenantId,
        string $resource,
        int $delta,
        string $action,
        ?string $userId = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        int $bytesDelta = 0,
        array $metadata = [],
        ?string $idempotencyKey = null,
    ): void {
        DB::transaction(function () use ($tenantId, $resource, $delta, $action, $userId, $referenceType, $referenceId, $bytesDelta, $metadata, $idempotencyKey) {
            // الحماية من الاحتساب المزدوج: نفس المفتاح = نفس الحدث = تجاهل.
            if ($idempotencyKey !== null && MediaUsageLedger::withoutTenant()
                ->where('idempotency_key', $idempotencyKey)
                ->exists()) {
                return;
            }

            // كل سجل Ledger يحمل المزود النشط + الطابع الزمني — قابل للتدقيق.
            $ledgerMetadata = array_merge($metadata, [
                'provider' => app(MediaProviderManager::class)->activeProvider()?->name() ?? 'unknown',
                'recorded_at' => now()->toIso8601String(),
            ]);

            MediaUsageLedger::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'resource' => $resource,
                'delta' => $delta,
                'bytes_delta' => $bytesDelta,
                'action' => $action,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $ledgerMetadata,
                'created_at' => now(),
            ]);

            // increment() يصدر UPDATE ذرياً (value = value + ?) على مستوى
            // قاعدة البيانات — آمن ضد التحديثات المتوازية.
            $agg = MediaUsageAggregate::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->where('resource', $resource)
                ->first();

            if ($agg) {
                $agg->increment('value', $delta);
            } else {
                MediaUsageAggregate::withoutTenant()->create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'resource' => $resource,
                    'value' => max(0, $delta),
                ]);
            }

            $month = now()->format('Y-m');
            $monthly = MediaUsageMonthly::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->where('year_month', $month)
                ->where('resource', $resource)
                ->first();

            if ($monthly) {
                $monthly->increment('value', $delta);
            } else {
                MediaUsageMonthly::withoutTenant()->create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'year_month' => $month,
                    'resource' => $resource,
                    'value' => max(0, $delta),
                ]);
            }
        });

        $this->invalidateCache($tenantId);
    }

    /**
     * عدادات الاستخدام الحالية لكل مورد (سلبية = خطأ في البيانات، نعود لصفر).
     *
     * @return array<string, int>
     */
    public function current(string $tenantId, bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = Cache::get(self::AGG_CACHE_KEY.$tenantId);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $values = MediaUsageAggregate::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->pluck('value', 'resource')
            ->map(fn ($v) => max(0, (int) $v))
            ->all();

        foreach (self::RESOURCES as $resource) {
            $values[$resource] ??= 0;
        }

        Cache::put(self::AGG_CACHE_KEY.$tenantId, $values, 60);

        return $values;
    }

    /**
     * استخدام شهري لمورد معين.
     */
    public function monthly(string $tenantId, string $yearMonth, string $resource): int
    {
        return (int) MediaUsageMonthly::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('year_month', $yearMonth)
            ->where('resource', $resource)
            ->value('value');
    }

    /**
     * الاستخدام الشهري الكامل لمعلم.
     *
     * @return array<string, int>
     */
    public function monthlyAll(string $tenantId, string $yearMonth): array
    {
        return MediaUsageMonthly::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('year_month', $yearMonth)
            ->pluck('value', 'resource')
            ->map(fn ($v) => max(0, (int) $v))
            ->all();
    }

    public function invalidateCache(string $tenantId): void
    {
        Cache::forget(self::AGG_CACHE_KEY.$tenantId);
        Cache::forget('media:usage:all:'.$tenantId);
    }

    /**
     * أعد بناء العدادات من الـ Ledger (يُستخدم في المزامنة/المصالحة).
     */
    public function rebuild(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId) {
            MediaUsageAggregate::withoutTenant()->where('tenant_id', $tenantId)->delete();

            $rows = MediaUsageLedger::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->select('resource', DB::raw('SUM(delta) as total'), DB::raw('SUM(bytes_delta) as bytes'))
                ->groupBy('resource')
                ->get();

            foreach ($rows as $row) {
                MediaUsageAggregate::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'resource' => $row->resource,
                    'value' => max(0, (int) $row->total),
                ]);
            }
        });

        $this->invalidateCache($tenantId);
    }

    public function reset(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId) {
            MediaUsageAggregate::withoutTenant()->where('tenant_id', $tenantId)->delete();
            MediaUsageLedger::withoutTenant()->where('tenant_id', $tenantId)->delete();
        });

        $this->invalidateCache($tenantId);
    }
}
