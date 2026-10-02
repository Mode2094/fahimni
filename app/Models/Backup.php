<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Backup extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_VERIFYING = 'verifying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'backup_id', 'status', 'stage', 'progress', 'reason', 'created_by',
        'database_dump', 'files_archive', 'config_archive', 'manifest',
        'checksums', 'system_info',
        'manifest_data', 'checksums_data', 'verification', 'tables', 'file_list',
        'size_bytes', 'database_size_bytes', 'file_count', 'duration_ms',
        'error_message', 'started_at', 'finished_at', 'verified_at', 'deleted_at',
    ];

    protected $casts = [
        'manifest_data' => 'array',
        'checksums_data' => 'array',
        'verification' => 'array',
        'tables' => 'array',
        'file_list' => 'array',
        'progress' => 'integer',
        'size_bytes' => 'integer',
        'database_size_bytes' => 'integer',
        'file_count' => 'integer',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'verified_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_DELETED);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_DELETED,
        ], true);
    }

    /**
     * Absolute path of the backup directory inside the private backup disk.
     */
    public function directoryPath(): string
    {
        return $this->backup_id;
    }

    /**
     * Whether the physical package directory still exists on disk.
     */
    public function packageExists(): bool
    {
        return Storage::disk(config('backup.disk'))->directoryExists($this->directoryPath());
    }

    public function packageSizeBytes(): int
    {
        $disk = Storage::disk(config('backup.disk'));

        return (int) array_sum(array_map(
            fn ($file) => $disk->size($file),
            $disk->allFiles($this->directoryPath())
        ));
    }

    /**
     * Human-readable size, e.g. "1.2 GB".
     */
    public function humanSize(): string
    {
        return static::human($this->size_bytes);
    }

    public static function human(int|float|null $bytes): string
    {
        $bytes = max(0, (int) $bytes);

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    public function artifactFilenames(): array
    {
        $names = [];

        foreach ([
            'database_dump', 'files_archive', 'config_archive',
            'manifest', 'checksums', 'system_info',
        ] as $field) {
            if (! empty($this->{$field})) {
                $names[] = $this->{$field};
            }
        }

        return $names;
    }

    /**
     * Short-lived signed token for a download URL. No secrets in the token.
     */
    public function signedDownloadToken(string $artifact, int $expires): string
    {
        return hash_hmac(
            'sha256',
            $this->id.'|'.$artifact.'|'.$expires,
            (string) config('app.key')
        );
    }
}