<?php

namespace App\Console\Commands;

use App\Media\MediaProviderManager;
use App\Models\MediaAsset;
use App\Models\MediaProvider;
use App\Models\MediaWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class MediaDiagnose extends Command
{
    protected $signature = 'media:diagnose';

    protected $description = 'Media infrastructure health check: DB, Redis, Queue, providers, credentials, webhooks, pending assets.';

    public function handle(MediaProviderManager $manager): int
    {
        $this->info('=== MEDIA DIAGNOSTICS ===');

        // ── Database ────────────────────────────────
        try {
            $videos = (int) DB::table('media_assets')->count();
            $this->line("[PASS] Database reachable — media_assets rows: {$videos}");
        } catch (\Throwable $e) {
            $this->error("[FAIL] Database: {$e->getMessage()}");
        }

        // ── Redis / Queue ───────────────────────────
        try {
            Redis::connection()->ping();
            $this->line('[PASS] Redis reachable');
        } catch (\Throwable $e) {
            $this->warn("[WARN] Redis: {$e->getMessage()}");
        }

        try {
            $failed = (int) DB::table('failed_jobs')->count();
            $this->line($failed > 0 ? "[WARN] {$failed} failed_jobs — review queue" : '[PASS] No failed jobs');
        } catch (\Throwable $e) {
            $this->warn("[WARN] failed_jobs: {$e->getMessage()}");
        }

        // ── Providers ───────────────────────────────
        foreach (MediaProvider::all() as $model) {
            $state = $model->enabled ? ($model->connection_status ?? 'unknown') : 'disabled';
            $creds = $model->credentialArray();
            $hasCreds = filled($creds['library_id'] ?? '') && filled($creds['api_key'] ?? '');
            $hasKey = filled($creds['security_key'] ?? '');

            $status = $model->enabled && $hasCreds ? 'PASS' : 'WARN';
            $this->{$status === 'PASS' ? 'line' : 'warn'}(
                "[{$status}] {$model->provider}: enabled={$model->enabled} connection={$state} creds=".($hasCreds ? 'yes' : 'no').' security_key='.($hasKey ? 'yes' : 'no').' webhook_secret='.(filled($model->webhook_secret) ? 'yes' : 'no')
            );

            if ($model->enabled && $hasCreds) {
                try {
                    $driver = $manager->provider($model->provider);
                    $videos = $driver->listVideos(1);
                    $total = (int) ($videos['totalItems'] ?? 0);
                    $this->line("[PASS] {$model->provider} API reachable — videos in library: {$total}");
                } catch (\Throwable $e) {
                    $this->error("[FAIL] {$model->provider} API: {$e->getMessage()}");
                }
            }

            // اختبار حقيقي للتوقيع: جرب رابط HLS موقّعاً على أول فيديو جاهز.
            if ($model->enabled && $hasCreds && $hasKey) {
                try {
                    $driver = $manager->provider($model->provider);
                    $videos = $driver->listVideos(5);
                    $items = $videos['items'] ?? [];
                    $candidate = collect($items)->firstWhere('status', 4) ?? ($items[0] ?? null);
                    $guid = (string) ($candidate['guid'] ?? '');

                    if ($guid === '') {
                        $this->warn("[WARN] {$model->provider}: no videos to verify signing against");
                    } else {
                        $url = $driver->signedPlaybackUrl($guid);
                        $code = Http::withHeaders([
                            'Referer' => 'http://localhost:3000',
                        ])->get($url)->status();
                        if ($code === 200) {
                            $this->line("[PASS] {$model->provider} playback URL verified (HTTP {$code})");
                        } else {
                            $this->warn("[FAIL] {$model->provider} playback URL rejected (HTTP {$code}) — check Referer allowed-domains in Bunny → Library → Security (allow http://localhost:3000 and https://app.lmsplatform.com).");
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn("[WARN] {$model->provider} signing check: {$e->getMessage()}");
                }
            }
        }

        // ── Webhook events ──────────────────────────
        $stats = [
            'total' => MediaWebhookEvent::count(),
            'processed' => MediaWebhookEvent::where('status', 'processed')->count(),
            'failed' => MediaWebhookEvent::where('status', 'failed')->count(),
        ];
        $this->line("[INFO] Webhook events: total={$stats['total']} processed={$stats['processed']} failed={$stats['failed']}");
        if ($stats['failed'] > 0) {
            $this->warn("[WARN] {$stats['failed']} failed webhook events — check recentEvents in admin");
        }

        // ── Pending assets ──────────────────────────
        $pending = MediaAsset::whereIn('status', ['processing', 'uploading'])->count();
        $ready = MediaAsset::where('status', 'ready')->count();
        $errors = MediaAsset::where('status', 'error')->count();
        $this->line("[INFO] Assets: ready={$ready} processing={$pending} error={$errors}");

        if ($pending > 0) {
            $this->warn("[WARN] {$pending} assets stuck in processing — run media:sync-video-statuses (scheduled every minute) or fix webhooks");
        }

        if ($errors > 0) {
            $this->warn("[WARN] {$errors} assets in error state — review them in admin media panel");
        }

        $this->info('=== DIAGNOSTICS COMPLETE ===');

        return self::SUCCESS;
    }
}
