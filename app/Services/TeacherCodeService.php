<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Generates the unique, non-sequential join code every teacher uses to
 * bind the Student Mobile App to their tenant (teacher context).
 *
 * Design goals:
 *  - Unique: checked against the tenants table, retried on collision.
 *  - Hard to guess: random_bytes source (never derived from the tenant UUID).
 *  - Easy to copy/type: unambiguous alphabet (no 0/O/1/I/L) + dash separator.
 *  - Stable: generated once and persisted; the same code is returned forever.
 *
 * Format: SHA-XXXXXX (6 chars from a 31-symbol alphabet ≈ 887M combinations).
 */
class TeacherCodeService
{
    /** Alphabet without look-alike characters: no 0/O, 1/I/L. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    public const PREFIX = 'SHA-';

    public const MAX_ATTEMPTS = 20;

    public function generate(?string $current = null): string
    {
        if (is_string($current) && $current !== '' && $this->isValidFormat($current)) {
            return $current;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::PREFIX.$this->randomPart();

            $exists = DB::table('tenants')->where('teacher_code', $code)->exists();

            if (! $exists) {
                return $code;
            }
        }

        // Practically unreachable (31^6 combos), but fail loudly rather than
        // silently reusing a code or degrading to a predictable one.
        throw new \RuntimeException('Unable to generate a unique teacher code.');
    }

    /**
     * Resolve a tenant by its teacher code. Returns null when no active,
     * code-bearing teacher matches.
     */
    public function findTenantByCode(string $code): ?Tenant
    {
        return Tenant::query()
            ->where('teacher_code', strtoupper(trim($code)))
            ->first();
    }

    public function isValidFormat(string $code): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::PREFIX, '/').'['.self::ALPHABET.']{'.self::LENGTH.'}$/', strtoupper(trim($code)));
    }

    private function randomPart(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $part = '';

        while (strlen($part) < self::LENGTH) {
            // random_int gives a strict CSPRNG source; bytes map evenly onto
            // the alphabet because we reject out-of-range values.
            $byte = random_int(0, 255);

            if ($byte >= $alphabetLength * 8) {
                continue;
            }

            $part .= self::ALPHABET[$byte % $alphabetLength];
        }

        return $part;
    }
}
