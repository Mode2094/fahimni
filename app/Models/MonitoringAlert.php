<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringAlert extends Model
{
    protected $fillable = [
        'level', 'source', 'key', 'message', 'context',
        'status', 'first_seen_at', 'last_seen_at', 'resolved_at', 'occurrences',
    ];

    protected $casts = [
        'context' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'occurrences' => 'integer',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    public function resolve(): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);
    }
}