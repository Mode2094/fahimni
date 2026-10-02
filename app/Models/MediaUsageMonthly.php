<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaUsageMonthly extends Model
{
    protected $table = 'media_usage_monthly';

    use BelongsToTenant, HasFactory, HasUuid;

    public const CREATED_AT = null;

    public const UPDATED_AT = 'updated_at';

    protected $fillable = ['tenant_id', 'year_month', 'resource', 'value'];

    protected $casts = ['value' => 'integer'];

    public $timestamps = true;
}
