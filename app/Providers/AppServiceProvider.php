<?php

namespace App\Providers;

use App\Models\ActivationCard;
use App\Models\Backup;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LessonProgress;
use App\Models\Payment;
use App\Models\PlanFeature;
use App\Models\Question;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Observers\EnrollmentObserver;
use App\Observers\ExamAttemptObserver;
use App\Observers\LessonProgressObserver;
use App\Observers\PaymentObserver;
use App\Observers\PlanFeatureObserver;
use App\Observers\SubscriptionPlanObserver;
use App\Observers\TenantSubscriptionObserver;
use App\Policies\ActivationCardPolicy;
use App\Policies\BackupPolicy;
use App\Policies\CoursePolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\ExamPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\LessonContentPolicy;
use App\Policies\LessonPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PlanFeaturePolicy;
use App\Policies\QuestionPolicy;
use App\Policies\SectionPolicy;
use App\Policies\SubscriptionPlanPolicy;
use App\Policies\TenantPolicy;
use App\Policies\TenantSettingPolicy;
use App\Policies\TenantSubscriptionPolicy;
use App\Policies\TransactionPolicy;
use App\Services\FeatureService;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    protected $policies = [
        Tenant::class => TenantPolicy::class,
        Course::class => CoursePolicy::class,
        Section::class => SectionPolicy::class,
        Lesson::class => LessonPolicy::class,
        LessonContent::class => LessonContentPolicy::class,
        Enrollment::class => EnrollmentPolicy::class,
        Exam::class => ExamPolicy::class,
        Question::class => QuestionPolicy::class,
        ActivationCard::class => ActivationCardPolicy::class,
        SubscriptionPlan::class => SubscriptionPlanPolicy::class,
        PlanFeature::class => PlanFeaturePolicy::class,
        TenantSubscription::class => TenantSubscriptionPolicy::class,
        Payment::class => PaymentPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Transaction::class => TransactionPolicy::class,
        TenantSetting::class => TenantSettingPolicy::class,
        Backup::class => BackupPolicy::class,
    ];

    public function boot(): void
    {
        // Scoped (flushed between requests/jobs) so a tenant context set by
        // middleware can never leak into the next request (Octane) or a queue job.
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(FeatureService::class);

        // Fail-fast (في الإنتاج): مفتاح توقيع فارغ يجعل كل توكنات البث
        // قابلة للتزوير (HMAC بمفتاح فارغ). بيئة البناء (بلا .env) تمر
        // لأن APP_ENV فيها ليس production.
        if (app()->environment('production')) {
            $signingKey = config('stream.signing_key');
            if (! is_string($signingKey) || trim($signingKey) === '') {
                throw new \RuntimeException('VIDEO_SIGNING_KEY is not configured. Set a base64 key in .env before serving media.');
            }
        }

        // Production hygiene warnings: placeholder SMTP means verification
        // emails, invitations and renewal reminders never arrive.
        if (app()->environment('production')) {
            $mailHost = strtolower((string) config('mail.host', ''));
            if ($mailHost === '' || str_contains($mailHost, 'your-provider') || str_contains($mailHost, 'example.com')) {
                Log::warning(
                    'MAIL_HOST is not configured for production — transactional emails (verification, invitations, renewals) will fail. Set a real SMTP provider in .env.'
                );
            }

            if (app()->bound('sentry') && config('sentry.dsn') === '') {
                Log::warning('SENTRY_LARAVEL_DSN is empty — error monitoring is disabled.');
            }
        }

        // Rate limiters MUST be registered from a provider: defining them in
        // the routes "then" closure silently disables throttling (and 500s
        // every request) once routes are cached with `route:cache`.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // إنشاء النسخ الاحتياطية: عملية ثقيلة — حد ضيق لمنع تكدس الطلبات.
        RateLimiter::for('backups', fn (Request $request) => Limit::perMinute(2)->by($request->user()?->id ?: $request->ip()));

        // رفع الفيديو عبر الخادم: مدة أطول للرفعات الكبيرة.
        RateLimiter::for('video-upload', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // رفع الملفات العامة (صور/مستندات/إجابات): حد لكل مستخدم — يمنع
        // استخدام المنصة كمستضيف ملفات عام أو استنزاف التخزين.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        // طلبات تشغيل الفيديو: حد لكل مستخدم — يحبط محاولات استنزاف الحصص/الـ tokens.
        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // طلبات الدفق (Ranges للـ <video>): حد معقول للمشاهدة الفعلية.
        RateLimiter::for('stream', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', (string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        RateLimiter::for('forgot-password', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', (string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('reset-password', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', (string) $request->input('email')).'|'.$request->ip()));

        // twoFactorConfirm has no email input; the per-account lockout is
        // enforced inside the controller (Cache-keyed on the user).
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('captcha', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('bookings', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('announcements', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('email-verification', fn (Request $request) => Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('recommend', fn (Request $request) => Limit::perMinute(15)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('assistant-ask', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('mobile-code', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('monitoring', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('monitoring-ingest', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        RateLimiter::for('live-create', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('join-ticket', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        Enrollment::observe(EnrollmentObserver::class);
        LessonProgress::observe(LessonProgressObserver::class);
        ExamAttempt::observe(ExamAttemptObserver::class);
        TenantSubscription::observe(TenantSubscriptionObserver::class);
        Payment::observe(PaymentObserver::class);
        PlanFeature::observe(PlanFeatureObserver::class);
        SubscriptionPlan::observe(SubscriptionPlanObserver::class);
    }
}
