<?php

use App\Jobs\ExpirePendingBookingsJob;
use App\Jobs\ExpireSubscriptionsJob;
use App\Jobs\ExpireTeacherInvitationsJob;
use App\Jobs\ExpiryFollowUpJob;
use App\Jobs\GenerateDailyStatsJob;
use App\Jobs\Media\CleanupExpiredReservationsJob;
use App\Jobs\Media\HealthCheckProviderJob;
use App\Jobs\Media\ReconcileMediaAssetsJob;
use App\Jobs\Media\RecalculateCostsJob;
use App\Jobs\Media\SyncProviderUsageJob;
use App\Jobs\ReconcileFreemiusWebhooksJob;
use App\Jobs\RenewalReminderJob;
use App\Jobs\ReverifyTenantDomainsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new GenerateDailyStatsJob)->daily();

Schedule::job(new ExpireSubscriptionsJob)->hourly();

// Reminders 3/2/1 days before subscription expiry (in-app + email).
Schedule::job(new RenewalReminderJob)->dailyAt('08:00');

// Daily post-expiry countdown inside the grace window.
Schedule::job(new ExpiryFollowUpJob)->dailyAt('08:30');

// NOTE: automatic deletion of tenant content on subscription expiry is
// DISABLED by policy. Teacher/student content is never destroyed because a
// payment lapsed. The PurgeExpiredTenantContentJob is intentionally no longer
// scheduled (kept as an inert class).

// Retry Freemius webhook events that failed transiently (e.g. API timeout) so
// a real payment/entitlement change is never lost. Idempotent + lock-guarded.
Schedule::job(new ReconcileFreemiusWebhooksJob)->everyFiveMinutes()->name('freemius-reconcile')->withoutOverlapping();

Schedule::job(new ExpireTeacherInvitationsJob)->hourly();

// Abandoned bookings hold calendar slots: expire them before users
// notice the slot is gone.
Schedule::job(new ExpirePendingBookingsJob)->everyFifteenMinutes();

// Custom-domain DNS is re-checked daily so moved/hijacked domains stop
// serving tenant sites.
Schedule::job(new ReverifyTenantDomainsJob)->dailyAt('03:30');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Token table hygiene: Sanctum tokens expire after 7 days but are never
// deleted on their own.
Schedule::command('sanctum:prune-expired')->dailyAt('04:00');

// Failed-job retention.
Schedule::command('queue:prune-failed --hours=168')->weekly();

// Automatic backups are admin-configured (enabled/frequency/retention from
// the admin panel). The command is a no-op unless the admin enables them and
// the schedule is due. withoutOverlapping prevents two runs racing.
Schedule::command('backup:run-scheduled')
    ->hourly()
    ->name('backup-scheduled')
    ->withoutOverlapping();

// ─── Media Infrastructure Jobs ─────────────────────────
// مزامنة استخدام المزود النشط (Bunny Stream) كل ساعة + مصالحة
// يومية للأصول والاستخدام + تحرير حجوزات المساحة المنتهية + فحص صحة
// اتصال المزود كل 6 ساعات.
Schedule::job(new SyncProviderUsageJob)->hourly()->name('media-sync-usage');
Schedule::job(new ReconcileMediaAssetsJob)->dailyAt('03:00')->name('media-reconcile');
Schedule::job(new CleanupExpiredReservationsJob)->hourly()->name('media-cleanup-reservations');

// حل محلي لغياب الوصول للـ webhook (بيئات التطوير/الخوادم الخاصة):
// يُحدَّث حالة الفيديوهات المنتظرة من المزود كل دقيقة.
Schedule::command('media:sync-video-statuses')->everyMinute()->name('media-sync-video-statuses')->withoutOverlapping();
Schedule::job(new HealthCheckProviderJob)->everySixHours()->name('media-health-check');
Schedule::job(new RecalculateCostsJob)->dailyAt('04:00')->name('costs-recalculate');

// ─── Monitoring ──────────────────────────────────────
// Heartbeat written by the scheduler container itself: proves schedule:work
// is alive. Written as a file (not cache) so it stays accurate even when
// Redis is down (Redis-down is its own critical alert).
Schedule::command('monitoring:heartbeat')->everyMinute()->name('monitoring-heartbeat');

// Full metrics snapshot + threshold evaluation.
Schedule::command('monitoring:collect')->everyFiveMinutes()->name('monitoring-collect')->withoutOverlapping();
Schedule::command('monitoring:check')->everyFiveMinutes()->name('monitoring-check')->withoutOverlapping();

// Prune old slow-request rows (retention from config).
Schedule::command('monitoring:prune-slow-requests')->daily()->name('monitoring-prune-slow');
