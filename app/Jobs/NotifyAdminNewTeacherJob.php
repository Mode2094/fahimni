<?php

namespace App\Jobs;

use App\Mail\NewTeacherAdminMail;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\TeacherPlatformStatsService;
use App\Support\PlatformBrand;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the Super-Admin "a new teacher joined" email — once per teacher, ever.
 *
 * Runs on the queue (dispatched after the registration transaction commits) so
 * a slow or failing mail transport never delays the teacher's own signup.
 *
 * The per-teacher marker is claimed atomically inside a row-locked
 * transaction before anything is sent, so a refreshed request, a duplicated
 * dispatch, or a re-delivered queue message can never produce a second email
 * for the same registration. The teacher's own profile fields (email, phone)
 * are never written to the log — only the opaque user id.
 */
class NotifyAdminNewTeacherJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $teacherId,
        public readonly ?string $tenantId,
        public readonly bool $trialStarted,
    ) {}

    public function handle(TeacherPlatformStatsService $stats): void
    {
        if (! PlatformSetting::bool('notify_new_teacher', true)) {
            return;
        }

        // Claim the one-shot marker. Returning null means "already notified"
        // (or the teacher is gone) — send nothing.
        $teacher = DB::transaction(function () {
            $teacher = User::withTrashed()->whereKey($this->teacherId)->lockForUpdate()->first();

            if (! $teacher) {
                return null;
            }

            $metadata = $teacher->metadata ?? [];

            if (! empty($metadata['admin_new_teacher_notified_at'])) {
                return null;
            }

            $metadata['admin_new_teacher_notified_at'] = now()->toIso8601String();
            $teacher->update(['metadata' => $metadata]);

            return $teacher;
        });

        if (! $teacher) {
            return;
        }

        $recipients = $this->adminRecipients();

        if ($recipients->isEmpty()) {
            Log::warning('New-teacher admin alert skipped: no super-admin recipient configured', [
                'teacher' => $this->teacherId,
            ]);

            return;
        }

        $mail = new NewTeacherAdminMail(
            platformNameFull: PlatformBrand::normalize((string) PlatformSetting::get('platform_name', PlatformBrand::NAME)),
            teacherName: (string) $teacher->name,
            teacherEmail: (string) $teacher->email,
            teacherPlatformName: $this->teacherPlatformName(),
            subscriptionType: $this->subscriptionType(),
            registeredAt: $this->registeredAt($teacher),
            stats: $stats->snapshot(),
            logoUrl: $this->logoUrl(),
            actionUrl: $this->actionUrl(),
        );

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(clone $mail);
            } catch (\Throwable $e) {
                Log::warning('New-teacher admin alert failed to send', [
                    'error' => $e->getMessage(),
                    'teacher' => $this->teacherId,
                ]);
            }
        }
    }

    /**
     * The platform's Super-Admin inbox(es) from the real database, with the
     * configured fallback address. Never a hardcoded literal.
     *
     * @return Collection<int,string>
     */
    private function adminRecipients(): Collection
    {
        $emails = User::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->pluck('email');

        $configured = trim((string) config('app.super_admin_email', ''));

        if ($configured !== '') {
            $emails->push($configured);
        }

        return $emails
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values();
    }

    private function teacherPlatformName(): ?string
    {
        if (! $this->tenantId) {
            return null;
        }

        $academy = TenantSetting::withoutTenant()->where('tenant_id', $this->tenantId)->value('academy_name');

        if (is_string($academy) && trim($academy) !== '') {
            return trim($academy);
        }

        $name = Tenant::withoutGlobalScopes()->whereKey($this->tenantId)->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    private function subscriptionType(): string
    {
        if ($this->trialStarted) {
            return 'تجربة مجانية';
        }

        if (! $this->tenantId) {
            return 'بانتظار الدفع';
        }

        $status = TenantSubscription::withoutTenant()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('ends_at')
            ->value('status');

        return match ($status) {
            'active' => 'اشتراك مدفوع',
            'trialing' => 'تجربة مجانية',
            default => 'بانتظار الدفع',
        };
    }

    private function registeredAt(User $teacher): string
    {
        $created = $teacher->created_at
            ? CarbonImmutable::instance($teacher->created_at)
            : CarbonImmutable::now();

        return $created->setTimezone(TeacherPlatformStatsService::TIMEZONE)
            ->format('Y/m/d — h:i A').' (بتوقيت الأردن)';
    }

    private function logoUrl(): ?string
    {
        $logo = trim((string) PlatformSetting::get('logo_url', ''));

        if ($logo !== '') {
            return $logo;
        }

        $frontend = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return $frontend !== '' ? $frontend.'/logo.png' : null;
    }

    private function actionUrl(): ?string
    {
        $frontend = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return $frontend !== '' ? $frontend.'/admin/teachers' : null;
    }
}
