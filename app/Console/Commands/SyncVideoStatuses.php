<?php

namespace App\Console\Commands;

use App\Media\MediaProviderManager;
use App\Media\MediaService;
use App\Models\MediaAsset;
use Illuminate\Console\Command;

class SyncVideoStatuses extends Command
{
    protected $signature = 'media:sync-video-statuses';

    protected $description = 'Sync pending video assets with the provider (Bunny) so processing videos resolve even when webhooks cannot reach this server.';

    public function handle(MediaProviderManager $manager, MediaService $media): int
    {
        $assets = MediaAsset::where('kind', 'video')
            ->whereIn('status', [MediaAsset::STATUS_PROCESSING, 'uploading', MediaAsset::STATUS_ERROR])
            ->limit(25)
            ->get();

        $done = 0;

        foreach ($assets as $asset) {
            $driver = $manager->provider($asset->provider);

            try {
                $info = $driver->getVideo($asset->provider_asset_id);
                $status = (string) ($info['status'] ?? 'processing');

                if ($status === 'ready') {
                    $media->markAssetReady($driver, $asset->provider_asset_id, 'sync:ready');
                    $done++;
                } elseif ($status === 'error') {
                    $media->markAssetError($driver, $asset->provider_asset_id, 'sync:error');
                    $done++;
                }
            } catch (\Throwable $e) {
                $this->warn("  [{$asset->id}] {$e->getMessage()}");
            }
        }

        $this->info("Synced {$done} of {$assets->count()} pending video assets.");

        return self::SUCCESS;
    }
}
