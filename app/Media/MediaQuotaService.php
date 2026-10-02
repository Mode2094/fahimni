<?php

namespace App\Media;

use App\Models\LiveSession;
use App\Models\MediaAsset;
use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\PlanEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * محرك الحصص — التحقق النهائي في الـ Backend مع حجز ذري.
 *
 * لماذا هذا ضروري؟ عند رفع عدة فيديوهات بالتوازي، "العد ثم الفحص" يفشل:
 * معلمان/طلبان يقرآن نفس القيمة ثم يتجاوز كلاهما الحد. الحل:
 *   1. Redis Lock لكل معلم (نفس العملية كلها داخل القفل).
 *   2. حجز في العداد الحالي (reservation) فوراً.
 *   3. commit عند النجاح / release عند الفشل / تنظيف عند انتهاء الصلاحية.
 *
 * كل الحدود تُقرأ من خطط الاشتراك عبر PlanEngine (لا شيء Hardcoded).
 */
class MediaQuotaService
{
    private const RESERVATION_KEY = 'media:reservation:';

    private const RESERVATION_TTL = 3600; // صلاحية الحجز بالثواني

    public function __construct(
        private readonly PlanEngine $engine,
        private readonly MediaUsageService $usage,
    ) {}

    /**
     * التحقق من إمكانية الرفع + حجز المساحة.
     * يرمي FeatureUnavailableException أو QuotaExceededException عند الرفض.
     *
     * @return array{reservation_id: string, size_bytes: int}
     */
    public function reserveStorage(string $tenantId, int $bytes, ?string $userId = null, string $reference = 'media_asset'): array
    {
        if ($bytes <= 0) {
            throw new QuotaExceededException('المساحة', 0, 0);
        }

        return $this->withTenantLock($tenantId, function () use ($tenantId, $bytes) {
            // 1) بوابة الميزة.
            $this->assertFeatureEnabled($tenantId, 'video_upload_enabled');

            // 2) حد التخزين من الخطة (MB).
            $limitMb = $this->engine->value($tenantId, 'storage_limit_mb', null);
            if ($limitMb === null || $limitMb === 'unlimited' || (float) $limitMb <= 0) {
                $this->reserve($tenantId, $bytes);

                return $this->reservationPayload($tenantId, $bytes);
            }

            $limitBytes = (int) round(((float) $limitMb) * 1024 * 1024);
            $usedBytes = (int) $this->usage->current($tenantId, true)['storage_bytes'];
            $pending = (int) Cache::get(self::RESERVATION_KEY.'pending:'.$tenantId, 0);

            if ($usedBytes + $pending + $bytes > $limitBytes) {
                throw new QuotaExceededException(
                    'مساحة التخزين',
                    (int) round(($usedBytes + $pending) / (1024 * 1024)),
                    (int) round($limitBytes / (1024 * 1024)),
                    'MB',
                    'MB',
                );
            }

            $this->reserve($tenantId, $bytes);

            return $this->reservationPayload($tenantId, $bytes);
        });
    }

    /**
     * التحقق من عدد الفيديوهات (videos_limit).
     */
    public function assertCanUploadVideo(string $tenantId): void
    {
        $this->withTenantLock($tenantId, function () use ($tenantId) {
            $this->assertFeatureEnabled($tenantId, 'video_upload_enabled');

            $limit = $this->engine->limit($tenantId, 'videos_limit');

            if ($limit === null) {
                return;
            }

            $used = (int) $this->usage->current($tenantId, true)['video_count'];

            if ($used >= $limit) {
                throw new QuotaExceededException('عدد الفيديوهات', $used, $limit);
            }
        });
    }

    /**
     * التحقق من إنشاء بث مباشر (live_stream_enabled + live_sessions_limit).
     *
     * المصدر موحّد: أصول البث (media_assets) + جلسات البث (live_sessions) —
     * أي بث يُحتسب مرة واحدة فقط بغض النظر عن مساره القديم.
     */
    public function assertCanCreateLive(string $tenantId): void
    {
        $this->assertFeatureEnabled($tenantId, 'live_stream_enabled');

        $limit = $this->engine->limit($tenantId, 'live_sessions_limit');

        if ($limit === null) {
            return;
        }

        $used = $this->liveStreamCount($tenantId);

        if ($used >= $limit) {
            throw new QuotaExceededException('البث المباشر', $used, $limit);
        }
    }

    /**
     * عدد البث الحية النشطة للمستأجر (أصل وسائط + جلسات بلا أصل).
     */
    public function liveStreamCount(string $tenantId): int
    {
        $assetCount = MediaAsset::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'live')
            ->count();

        $assetIds = MediaAsset::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'live')
            ->pluck('id');

        $sessionCount = LiveSession::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['cancelled', 'ended'])
            ->get()
            ->filter(fn (LiveSession $s) => ! in_array((string) ($s->metadata['media_asset_id'] ?? ''), $assetIds->all(), true))
            ->count();

        return $assetCount + $sessionCount;
    }

    /**
     * إنشاء جلسة بث داخل قفل ذري: الفحص + الإنشاء معاً داخل القفل،
     * فلا يتجاوز معلمان متزامنان حصة live_sessions_limit أبداً.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLiveCreationLock(string $tenantId, callable $callback): mixed
    {
        return $this->withTenantLock($tenantId, function () use ($tenantId, $callback) {
            $this->assertCanCreateLive($tenantId);

            return $callback();
        });
    }

    /**
     * التحقق من حد دقائق البث الشهري (live_minutes_limit إن وُجد).
     */
    public function assertLiveMinutes(string $tenantId, int $minutes): void
    {
        $limit = $this->engine->limit($tenantId, 'live_minutes_limit');

        if ($limit === null) {
            return;
        }

        $used = (int) $this->usage->monthly($tenantId, now()->format('Y-m'), 'live_minutes');

        if ($used + $minutes > $limit) {
            throw new QuotaExceededException('دقائق البث', $used, $limit);
        }
    }

    /**
     * حد دقائق المشاهدة الشهرية — يُطبق عند كل طلب تشغيل.
     * المصدر: video_watch_minutes_limit (أو delivery_minutes_limit القديم).
     */
    public function assertWatchMinutes(string $tenantId, int $minutes = 1): void
    {
        $limit = $this->engine->limit($tenantId, 'video_watch_minutes_limit');

        if ($limit === null) {
            $limit = $this->engine->limit($tenantId, 'delivery_minutes_limit');
        }

        if ($limit === null) {
            return;
        }

        $used = (int) $this->usage->monthly($tenantId, now()->format('Y-m'), 'delivery_minutes');

        if ($used + $minutes > $limit) {
            throw new QuotaExceededException('دقائق المشاهدة', $used, $limit);
        }
    }

    /**
     * تنفيذ الحجز فعلياً (تسجيل pending في Redis).
     */
    private function reserve(string $tenantId, int $bytes): void
    {
        $pending = (int) Cache::get(self::RESERVATION_KEY.'pending:'.$tenantId, 0);
        Cache::put(self::RESERVATION_KEY.'pending:'.$tenantId, $pending + $bytes, self::RESERVATION_TTL);
    }

    /**
     * إتمام الحجز: تحويل المساحة المحجوزة إلى استخدام فعلي.
     */
    public function commitStorage(string $tenantId, string $reservationId, int $bytes, ?string $userId = null, string $action = 'upload', ?string $referenceType = null, ?string $referenceId = null): void
    {
        $this->withTenantLock($tenantId, function () use ($tenantId, $reservationId, $bytes, $userId, $action, $referenceType, $referenceId) {
            // تأكد من وجود الحجز قبل الإتمام (لا يمكن commit لحجز غير موجود).
            if (! $this->reservationExists($tenantId, $reservationId)) {
                // عند غياب الحجز (مثلاً انتهت صلاحيته ثم اكتمل الرفع):
                // نتحقق من الحد مرة أخرى قبل إضافة الاستخدام.
                $this->assertStorageWithinLimit($tenantId, $bytes);
            }

            $this->pendingDelta($tenantId, -$bytes);
            $this->usage->applyDelta($tenantId, 'storage_bytes', $bytes, $action, $userId, $referenceType, $referenceId, $bytes);
        });
    }

    /**
     * تحرير الحجز عند الفشل أو انتهاء الصلاحية.
     */
    public function releaseStorage(string $tenantId, string $reservationId, int $bytes): void
    {
        $this->withTenantLock($tenantId, function () use ($tenantId, $reservationId, $bytes) {
            if (! $this->reservationExists($tenantId, $reservationId)) {
                return;
            }

            $this->pendingDelta($tenantId, -$bytes);
            $this->forgetReservation($tenantId, $reservationId);
        });
    }

    /**
     * إنقاص/زيادة الحجوزات المعلقة.
     */
    private function pendingDelta(string $tenantId, int $bytes): void
    {
        $pending = max(0, (int) Cache::get(self::RESERVATION_KEY.'pending:'.$tenantId, 0) + $bytes);
        Cache::put(self::RESERVATION_KEY.'pending:'.$tenantId, $pending, self::RESERVATION_TTL);
    }

    private function reservationExists(string $tenantId, string $reservationId): bool
    {
        return (bool) Cache::get(self::RESERVATION_KEY.$tenantId.':'.$reservationId);
    }

    private function forgetReservation(string $tenantId, string $reservationId): void
    {
        Cache::forget(self::RESERVATION_KEY.$tenantId.':'.$reservationId);
    }

    private function reservationPayload(string $tenantId, int $bytes): array
    {
        $id = 'res_'.Str::uuid();
        Cache::put(self::RESERVATION_KEY.$tenantId.':'.$id, $bytes, self::RESERVATION_TTL);

        return ['reservation_id' => $id, 'size_bytes' => $bytes];
    }

    private function assertFeatureEnabled(string $tenantId, string $feature): void
    {
        if (! $this->engine->hasFeature($tenantId, $feature)) {
            throw new FeatureUnavailableException($feature);
        }
    }

    private function assertStorageWithinLimit(string $tenantId, int $bytes): void
    {
        $limitMb = $this->engine->value($tenantId, 'storage_limit_mb', null);

        if ($limitMb === null || $limitMb === 'unlimited' || (float) $limitMb <= 0) {
            return;
        }

        $limitBytes = (int) round(((float) $limitMb) * 1024 * 1024);
        $used = (int) $this->usage->current($tenantId, true)['storage_bytes'];
        $pending = (int) Cache::get(self::RESERVATION_KEY.'pending:'.$tenantId, 0);

        if ($used + $pending + $bytes > $limitBytes) {
            throw new QuotaExceededException(
                'مساحة التخزين',
                (int) round(($used + $pending) / (1024 * 1024)),
                (int) round($limitBytes / (1024 * 1024)),
                'MB',
                'MB',
            );
        }
    }

    /**
     * Redis lock لكل معلم — يضمن تسلسل عمليات الحجز/الإتمام/التحرير.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withTenantLock(string $tenantId, callable $callback): mixed
    {
        $lock = Cache::lock('media:quota:lock:'.$tenantId, 10);

        try {
            $lock->block(8);

            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * حالة الاستخدام مقابل حدود الخطة — للوحات المعلم والأدمن.
     */
    public function usageStatus(string $tenantId): array
    {
        $current = $this->usage->current($tenantId);
        $month = now()->format('Y-m');

        $bytes = $current['storage_bytes'];
        $limitMb = $this->engine->value($tenantId, 'storage_limit_mb', null);
        $storageLimitBytes = ($limitMb === null || $limitMb === 'unlimited' || (float) $limitMb <= 0)
            ? null
            : (int) round(((float) $limitMb) * 1024 * 1024);

        $videoLimit = $this->engine->limit($tenantId, 'videos_limit');
        $liveLimit = $this->engine->limit($tenantId, 'live_sessions_limit');
        $liveMinutesLimit = $this->engine->limit($tenantId, 'live_minutes_limit');
        $recordingsLimit = $this->engine->limit($tenantId, 'recordings_limit');

        $liveMinutes = $this->usage->monthly($tenantId, $month, 'live_minutes');
        $liveSessionsCount = $this->liveStreamCount($tenantId);

        $entry = fn (int $used, ?int $limit, string $unit = 'count') => [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'pct' => $limit === null ? null : ($limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 100),
            'unlimited' => $limit === null,
            'unit' => $unit,
        ];

        return [
            'storage' => [
                ...$entry($bytes, $storageLimitBytes, 'bytes'),
                'used_gb' => round($bytes / (1024 ** 3), 2),
                'limit_gb' => $storageLimitBytes !== null ? round($storageLimitBytes / (1024 ** 3), 2) : null,
                'exceeded' => $storageLimitBytes !== null && $bytes > $storageLimitBytes,
            ],
            'videos' => $entry($current['video_count'], $videoLimit),
            'live_sessions' => $entry($liveSessionsCount, $liveLimit),
            'live_minutes' => $entry($liveMinutes, $liveMinutesLimit),
            'recordings' => $entry($current['recording_count'], $recordingsLimit),
            'files' => $entry($current['files_count'], $this->engine->limit($tenantId, 'files_limit')),
            'current' => $current,
        ];
    }

    /**
     * تنظيف الحجوزات المعلقة (يُستدعى دورياً): عند انتهاء TTL يزول pending
     * تلقائياً من المخزن، ونزيل الحجوزات الفردية منتهية الصلاحية.
     */
    public function cleanupExpiredReservations(): int
    {
        $cleaned = 0;
        $prefix = (string) config('cache.prefix', '');
        $pattern = $prefix.'_'.self::RESERVATION_KEY.'*';

        $keys = method_exists(Cache::getStore(), 'keys')
            ? (array) Cache::getStore()->keys($pattern)
            : [];

        foreach ($keys as $key) {
            $value = Cache::get($key);

            if ($value === null) {
                Cache::forget($key);
                $cleaned++;
            }
        }

        return $cleaned;
    }
}
