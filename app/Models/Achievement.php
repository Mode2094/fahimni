<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Achievement extends Model
{
    use BelongsToTenant, HasFactory, HasUuid;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id', 'key', 'title', 'title_ar', 'description',
        'description_ar', 'icon', 'points', 'criteria', 'sort_order',
    ];

    protected $casts = [
        'points' => 'integer',
        'criteria' => 'array',
        'sort_order' => 'integer',
    ];

    public function earnedBy(): HasMany
    {
        return $this->hasMany(StudentAchievement::class, 'achievement_id');
    }
}
