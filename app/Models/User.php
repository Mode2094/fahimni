<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
