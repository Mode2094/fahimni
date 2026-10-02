<?php

namespace App\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

/**
 * المصالحة بين قاعدة البيانات المحلية والمزود:
 *  - يكشف الأصول المفقودة/المحذوفة/المختلفة.
 *  - يسجل الأصول الموجود فقط عند المزود كـ orphan (لا يحذف مباشرة).
 *  - يعيد بناء عدادات الاستخدام من السجل عند الاختلاف.
 */
class MediaReconciliationService
{
    public function __construct(
        private readonly MediaProviderManager $manager,
        private readonly MediaUsageService $usage,
        private readonly ?\App\Media\MediaService $media = null,
    ) {}

    /**
     * @return array{assets_checked: int, orphans_detected: int, deleted_detected: int, size_fixed: int, usage_rebuilt: int}
     */
    public function reconcile(?string $provider = null, ?string $tenantId = null): array
    {
        $provider ??= $this->manager->activeProvider()?->name() ?? 'bunny';
        $driver = $this->manager->provider($provider);

        if (! $driver->isConfigured()) {
            return ['assets_checked' => 0, 'orphans_detected' => 0, 'deleted_detected' => 0, 'size_fixed' => 0, 'usage_rebuilt' => 0];
        }

        $result = ['assets_checked' => 0, 'orphans_detected' => 0, 'deleted_detected' => 0, 'size_fixed' => 0, 'usage_rebuilt' => 0];

        try {
            $videos = $driver->listVideos(50);
            // توافق المزودين: Cloudflare يعيد ['videos'] وBunny يعيد ['items'].
            $videoList = $videos['videos'] ?? $videos['items'] ?? [];
            $providerIds = collect($videoList)
                ->pluck('uid', 'guid')
                ->map(fn ($v) => (string) $v)
                ->flip();
            // Bunny: المعرّف في حقل guid — نجمعه مع uid.
            $providerIds = collect($videoList)
                ->map(fn ($v) => (string) ($v['uid'] ?? $v['guid'] ?? ''))
                ->filter()
                ->flip();
            $result['assets_checked'] = $providerIds->count();

            // 1) أصول محلية: لا وجود لها عند المزود → deleted؛ أو ما تزال
            //    قيد المعالجة → نسأل المزود ونحدّث الحالة (ready/error).
            $local = MediaAsset::withoutTenant()
                ->where('provider', $provider)
                ->where('status', '!=', MediaAsset::STATUS_DELETED)
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->get();

            foreach ($local as $asset) {
                $pid = $asset->provider_asset_id;

                if (! $providerIds->has($pid)) {
                    Log::info('media.reconcile.deleted_detected', ['asset' => $asset->id, 'provider_asset' => $pid]);
                    $result['deleted_detected']++;

                    // لا نحذف فوراً — نوسمها deleted ونحرر الاستخدام (Webhook
                    // أحياناً لا يصل).
                    $this->markGone($asset);

                    continue;
                }

                // تحديث الحالة من المزود (في حال عدم وصول الويب هوك).
                if (in_array($asset->status, [MediaAsset::STATUS_PROCESSING], true)) {
                    try {
                        $info = $driver->getVideo($pid);

                        if (($info['status'] ?? '') === 'ready') {
                            $this->media->markAssetReady($driver, $pid, 'reconcile');
                            Log::info('media.reconcile.marked_ready', ['asset' => $asset->id]);
                        } elseif (($info['status'] ?? '') === 'error') {
                            $this->media->markAssetError($driver, $pid, 'reconcile');
                        }
                    } catch (\Throwable $e) {
                        Log::warning('media.reconcile.status_check_failed', ['asset' => $asset->id, 'error' => $e->getMessage()]);
                    }
                }
            }

            // 2) أصول عند المزود بلا سجل محلي → orphan.
            $localIds = $local->pluck('provider_asset_id')->flip();

            foreach ($videoList as $video) {
                $uid = (string) ($video['uid'] ?? $video['guid'] ?? '');

                if ($uid === '' || $localIds->has($uid)) {
                    continue;
                }

                $exists = MediaAsset::withoutTenant()
                    ->where('provider', $provider)
                    ->where('provider_asset_id', $uid)
                    ->where('status', MediaAsset::STATUS_ORPHAN)
                    ->exists();

                if (! $exists) {
                    MediaAsset::withoutTenant()->create([
                        'id' => UuidV7::generate(),
                        'tenant_id' => $tenantId ?? '00000000-0000-0000-0000-000000000000',
                        'user_id' => null,
                        'provider' => $provider,
                        'provider_asset_id' => $uid,
                        'kind' => 'video',
                        'status' => MediaAsset::STATUS_ORPHAN,
                        'title' => $video['meta']['name'] ?? $video['title'] ?? 'Orphan asset',
                        'duration_seconds' => (int) ($video['duration'] ?? $video['length'] ?? 0),
                        'thumbnail_url' => $video['thumbnail'] ?? null,
                        'metadata' => ['detected_at' => now()->toIso8601String(), 'provider_status' => $video['status'] ?? ''],
                    ]);

                    Log::info('media.reconcile.orphan_detected', ['provider_asset' => $uid]);
                    $result['orphans_detected']++;
                }
            }
        } catch (\Throwable $e) {
            Log::error('media.reconcile.failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            $model = $this->manager->model($provider);
            $model?->update(['last_error' => substr($e->getMessage(), 0, 1000), 'connection_status' => 'down']);
        }

        // 3) إصلاح الاستخدام: مقارنة العدادات مع المصدر.
        $result['usage_rebuilt'] = $this->repairUsage($tenantId);
        $result['size_fixed'] = $this->repairSizes($provider);

        $this->manager->model($provider)?->update(['last_synced_at' => now(), 'connection_status' => 'healthy']);

        return $result;
    }

    private function markGone(MediaAsset $asset): void
    {
        $storage = $asset->storage_bytes;

        DB::transaction(function () use ($asset, $storage) {
            $asset->update(['status' => MediaAsset::STATUS_DELETED, 'metadata' => array_merge($asset->metadata ?? [], ['reconciled_deleted_at' => now()->toIso8601String()])]);
            $asset->delete();

            if ($storage > 0 && $asset->kind !== 'live') {
                $this->usage->applyDelta($asset->tenant_id, 'storage_bytes', -$storage, 'reconcile', $asset->user_id, 'media_asset', $asset->id, -$storage);
            }
        });
    }

    private function repairUsage(?string $tenantId): int
    {
        $rebuilt = 0;

        $tenants = $tenantId
            ? [$tenantId]
            : MediaAsset::withoutTenant()->distinct()->pluck('tenant_id')->all();

        foreach ($tenants as $tid) {
            $videos = MediaAsset::withoutTenant()
                ->where('tenant_id', $tid)
                ->where('kind', 'video')
                ->whereNull('deleted_at')
                ->count();

            $recordings = MediaAsset::withoutTenant()
                ->where('tenant_id', $tid)
                ->where('kind', 'live_recording')
                ->whereNull('deleted_at')
                ->count();

            $storage = (int) MediaAsset::withoutTenant()
                ->where('tenant_id', $tid)
                ->whereIn('kind', ['video', 'live_recording'])
                ->whereNull('deleted_at')
                ->sum('storage_bytes');

            $current = $this->usage->current($tid, true);

            $this->usage->applyDelta($tid, 'video_count', $videos - $current['video_count'], 'reconcile');
            $this->usage->applyDelta($tid, 'recording_count', $recordings - $current['recording_count'], 'reconcile');
            $this->usage->applyDelta($tid, 'storage_bytes', $storage - $current['storage_bytes'], 'reconcile', bytesDelta: $storage - $current['storage_bytes']);

            $rebuilt++;
        }

        return $rebuilt;
    }

    private function repairSizes(string $provider): int
    {
        $fixed = 0;

        $assets = MediaAsset::withoutTenant()
            ->where('provider', $provider)
            ->where('status', MediaAsset::STATUS_READY)
            ->where('storage_bytes', 0)
            ->limit(100)
            ->get();

        foreach ($assets as $asset) {
            if ($asset->size_bytes > 0) {
                $asset->update(['storage_bytes' => $asset->size_bytes]);
                $fixed++;
            }
        }

        return $fixed;
    }
}
