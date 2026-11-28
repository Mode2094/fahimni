<?php

namespace App\Jobs;

use App\Models\AcademicTerm;
use App\Models\Tenant;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Warn teachers a few days before an academic term ends.
 *
 * This NEVER archives students automatically — it only notifies, so ending a
 * term always stays an explicit teacher decision.
 */
class AcademicTermReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(NotificationService $notifier): void
    {
        $terms = AcademicTerm::withoutTenant()
            ->where('status', AcademicTerm::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(3)])
            ->get();

        foreach ($terms as $term) {
            try {
                $owner = Tenant::find($term->tenant_id)?->owner;

                if (! $owner) {
                    continue;
                }

                $meta = $term->metadata ?? [];
                $guardKey = 'reminded_'.$term->ends_at->toDateString();

                if (! empty($meta[$guardKey])) {
                    continue;
                }

                $days = max(0, (int) now()->startOfDay()->diffInDays($term->ends_at->startOfDay(), false));

                $notifier->notify(
                    notifiable: $owner,
                    type: 'academic_term.ending_soon',
                    title: "الفصل «{$term->name}» ينتهي قريبًا",
                    body: "ينتهي الفصل الدراسي «{$term->name}» خلال {$days} يوم. يمكنك إنهاء الفصل وتجديد مقاعد الطلاب من صفحة الفصول.",
                    tenantId: $term->tenant_id,
                    data: ['term_id' => $term->id, 'ends_at' => $term->ends_at->toIso8601String()],
                );

                $term->update(['metadata' => array_merge($meta, [$guardKey => true])]);
            } catch (\Throwable $e) {
                Log::warning('AcademicTermReminderJob failed for term '.$term->id, ['error' => $e->getMessage()]);
            }
        }
    }
}
