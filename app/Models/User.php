<?php

namespace App\Models;

use App\Mail\PlatformNotification;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'avatar_url',
        'is_active', 'locale',
        'password',
        'last_login_at', 'last_login_ip', 'metadata',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'is_super_admin' => 'boolean',
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'phone' => 'encrypted',
        'metadata' => 'array',
    ];

    /**
     * The login page that matches this account's highest role. Used after a
     * successful password reset to send the user back to the right door.
     */
    public function loginPath(): string
    {
        if ($this->is_super_admin || $this->hasRole('Super Admin') || $this->hasRole('Admin')) {
            return '/admin/login';
        }

        if ($this->hasRole('Teacher') || $this->teacherTenants()->exists()) {
            return '/teacher/login';
        }

        return '/student/login';
    }

    /**
     * Normalize an email for storage and lookup: one canonical form, so an
     * account can never be duplicated under a case/whitespace variant.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Find an account by email INCLUDING soft-deleted rows.
     *
     * A soft-deleted row still holds its address in the `users_email_unique`
     * index, so a plain `where('email', ...)` misses it and the next INSERT
     * trips the unique constraint — the production `google.callback_unique_
     * collision` that dead-ended in «تعارض مؤقت — أعد المحاولة». Every auth
     * and provisioning path must resolve an address through here instead of a
     * raw `where('email')`.
     */
    public static function findByEmail(string $email): ?self
    {
        $normalized = static::normalizeEmail($email);

        if ($normalized === '') {
            return null;
        }

        return static::withTrashed()->where('email', $normalized)->first();
    }

    /**
     * Bring a soft-deleted account — and the workspaces it owns or belongs to —
     * back into view. Deletion on this platform is non-destructive: it hides an
     * account while preserving its data (enforcement is `is_active = false`), so
     * a flow that has PROVEN ownership of the address (a correct password, a
     * verified Google identity, an emailed reset token) may reactivate it.
     */
    public function restoreWithWorkspaces(): void
    {
        if (! $this->trashed()) {
            return;
        }

        $tenantIds = TenantUser::withoutGlobalScope('tenant')
            ->where('user_id', $this->id)
            ->pluck('tenant_id');

        Tenant::withTrashed()
            ->where(function ($query) use ($tenantIds) {
                $query->whereIn('id', $tenantIds)
                    ->orWhere('owner_id', $this->id);
            })
            ->restore();

        $this->restore();
    }

    /**
     * Send a branded, Arabic password-reset email pointing at the frontend.
     *
     * Laravel's default ResetPassword notification builds a URL from the
     * `password.reset` route, which this API-only app does not register — so it
     * throws RouteNotFoundException and no email is ever sent. We override it
     * with a link to FRONTEND_URL/reset-password (the page that POSTs back to
     * /auth/reset-password).
     */
    public function sendPasswordResetNotification($token): void
    {
        $frontend = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $url = $frontend.'/reset-password?token='.$token.'&email='.urlencode((string) $this->email);

        try {
            Mail::to($this->email)->queue(
                new PlatformNotification(
                    title: 'إعادة تعيين كلمة المرور',
                    body: "أهلاً {$this->name}،\n\nوصلنا طلب لإعادة تعيين كلمة المرور لحسابك في منصة فهمني.\n\nاضغط زر «تعيين كلمة مرور جديدة» لاختيار كلمة مرور جديدة. الرابط صالح لفترة محدودة، وإذا لم تكن أنت من طلبه فتجاهل هذه الرسالة ولن يتغيّر شيء.",
                    data: ['action_url' => $url, 'action_text' => 'تعيين كلمة مرور جديدة'],
                )
            );
        } catch (\Throwable $e) {
            // Never surface mailer failures to the client (they would leak
            // whether the account exists); log for operators instead.
            Log::warning('Password reset email failed to send', [
                'user_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot('role', 'tenant_role', 'status', 'joined_at')
            ->withTimestamps();
    }

    public function teacherTenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->wherePivot('role', 'teacher')
            ->withPivot('status', 'joined_at')
            ->withTimestamps();
    }

    public function studentTenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->wherePivot('role', 'student')
            ->withPivot('status', 'joined_at')
            ->withTimestamps();
    }

    public function tenantPivots(): HasMany
    {
        return $this->hasMany(TenantUser::class, 'user_id');
    }

    public function currentTenant(): ?Tenant
    {
        $tenant = request()->attributes->get('current_tenant');

        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        $tenantId = request()->header('X-Tenant-Id');

        if (! $tenantId) {
            return null;
        }

        return $this->tenants()
            ->where('tenant_id', $tenantId)
            ->wherePivot('status', 'active')
            ->first();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'user_id');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class, 'user_id');
    }

    public function examAttempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class, 'user_id');
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class, 'user_id');
    }

    public function teacherAvailabilities(): HasMany
    {
        return $this->hasMany(TeacherAvailability::class, 'user_id');
    }

    /**
     * Staff permission allowlist for a given tenant.
     * Returns null when no explicit list is set (full teacher access),
     * otherwise the allowlist stored in the tenant pivot metadata.
     */
    public function tenantPermissions(?string $tenantId): ?array
    {
        if (! $tenantId) {
            return null;
        }

        $pivot = $this->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (! $pivot || ! is_array($pivot->metadata['permissions'] ?? null)) {
            return null;
        }

        $list = $pivot->metadata['permissions'];

        return $list === [] ? null : $list;
    }

    public function canInTenant(?string $tenantId, string $permission): bool
    {
        $allowlist = $this->tenantPermissions($tenantId);

        return $allowlist === null || in_array($permission, $allowlist, true);
    }

    public function paidServices(): HasMany
    {
        return $this->hasMany(PaidService::class, 'user_id');
    }

    public function teacherBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'teacher_id');
    }

    public function studentBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'student_id');
    }
}
