<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A provider video discovered by the media reconcile job that has no local
 * record and no known owner. Deliberately has NO tenant relationship: assigning
 * a fake tenant (or a zero UUID) is exactly what broke the sync before.
 */
class MediaOrphanAsset extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'provider', 'provider_asset_id', 'title',
        'duration_seconds', 'thumbnail_url', 'metadata',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
