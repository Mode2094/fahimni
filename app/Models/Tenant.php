<?php

namespace App\Models;

use App\Services\TeacherCodeService;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name', 'type', 'slug', 'email', 'phone', 'status', 'owner_id',
        'locale', 'timezone', 'max_students', 'storage_limit_mb', 'metadata',
        'teacher_code', 'teacher_code_generated_at',
        'payment_token', 'payment_gateway', 'card_brand', 'card_last_four',
    ];

    /**
     * Every teacher tenant automatically receives a unique, stable
     * Student Mobile App join code on creation. No code is ever derived
     * from the (exposed) UUID, and it never changes between sessions.
     */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->teacher_code)) {
                $tenant->teacher_code = app(TeacherCodeService::class)->generate();
                $tenant->teacher_code_generated_at = now();
            }
        });
    }

    protected $casts = [
        'metadata' => 'array',
        'max_students' => 'integer',
        'storage_limit_mb' => 'integer',
    ];

    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot('role', 'status', 'joined_at')
            ->withTimestamps();
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(TenantSubscription::class)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('trial_ends_at')->orWhere('trial_ends_at', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            // latestOfMany() compiles to MAX(uuid) which breaks on Postgres
            // (uuid vs timestamp comparison). Order by ends_at explicitly.
            ->orderByDesc('ends_at')
            ->limit(1);
    }
}
