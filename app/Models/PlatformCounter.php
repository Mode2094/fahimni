<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Named integer counter with row-level locking. Read/increment through the
 * helpers below so callers never forget the lock.
 */
class PlatformCounter extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = ['value' => 'integer'];

    /** Current value (0 when the counter has never been touched). */
    public static function valueOf(string $key): int
    {
        return (int) (static::query()->where('key', $key)->value('value') ?? 0);
    }

    /**
     * Reserve one unit under a row lock. Returns false when `$limit` is already
     * reached (no increment happens). The lock makes concurrent reservations
     * serial, so the limit can never be exceeded.
     *
     * @param  callable(int $used): bool  $alreadyCounted  optional pre-check
     */
    public static function tryReserve(string $key, int $limit): bool
    {
        static::query()->firstOrCreate(['key' => $key], ['value' => 0]);

        $row = static::query()->where('key', $key)->lockForUpdate()->first();

        if ($row->value >= $limit) {
            return false;
        }

        $row->increment('value');

        return true;
    }

    /** Release a previously reserved unit (floor 0). */
    public static function release(string $key): void
    {
        $row = static::query()->where('key', $key)->lockForUpdate()->first();

        if ($row && $row->value > 0) {
            $row->decrement('value');
        }
    }
}
