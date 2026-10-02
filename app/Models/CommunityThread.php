<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunityThread extends Model
{
    use BelongsToTenant, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'group_id', 'user_id', 'title', 'body', 'type', 'tags',
        'status', 'is_pinned', 'views_count', 'comments_count', 'answers_count',
        'likes_count', 'last_activity_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_pinned' => 'boolean',
        'views_count' => 'integer',
        'comments_count' => 'integer',
        'answers_count' => 'integer',
        'likes_count' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function scopeVisibleTo(Builder $query, ?string $userId, bool $isTeacher = false): Builder
    {
        $query->where('status', 'open');

        if ($isTeacher) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($userId) {
            $q->whereNull('group_id')
                ->orWhereHas('group', fn (Builder $g) => $g->where('status', 'active')->where('visibility', 'public'))
                ->orWhereHas('group.members', fn (Builder $m) => $m->where('user_id', $userId)->where('status', 'active'));
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CommunityGroup::class, 'group_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CommunityComment::class, 'thread_id');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(CommunityLike::class, 'likeable');
    }
}
