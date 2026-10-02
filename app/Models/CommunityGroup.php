<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunityGroup extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'cover_image',
        'visibility', 'join_policy', 'status', 'created_by',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommunityGroupMember::class, 'group_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(CommunityThread::class, 'group_id');
    }

    public function latestMember(): HasOne
    {
        return $this->hasOne(CommunityGroupMember::class, 'group_id')->latestOfMany();
    }

    public function scopeVisibleTo(Builder $query, string $tenantId, ?string $userId = null): Builder
    {
        $query->where('tenant_id', $tenantId)->where('status', 'active');

        if ($userId) {
            $query->where(function (Builder $q) use ($userId) {
                $q->where('visibility', 'public')
                    ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $userId)->where('status', 'active'));
            });
        } else {
            $query->where('visibility', 'public');
        }

        return $query;
    }

    public function userMembership(string $userId): ?CommunityGroupMember
    {
        return $this->members()->where('user_id', $userId)->first();
    }

    public function isMember(string $userId): bool
    {
        return $this->members()->where('user_id', $userId)->where('status', 'active')->exists();
    }

    public function isModerator(string $userId): bool
    {
        return $this->members()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereIn('role', ['moderator'])
            ->exists();
    }
}
