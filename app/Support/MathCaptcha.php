<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class MathCaptcha
{
    public static function create(): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $ops = ['+', '-', '×'];
        $op = $ops[array_rand($ops)];
        $answer = match ($op) {
            '+' => $a + $b,
            '-' => $a - $b,
            '×' => $a * $b,
        };

        $id = bin2hex(random_bytes(16));
        Cache::put('captcha:'.$id, (string) $answer, now()->addMinutes(5));

        return [
            'captcha_id' => $id,
            'question' => "{$a} {$op} {$b} = ?",
        ];
    }

    public static function verify(string $id, string $answer): bool
    {
        $expected = Cache::pull('captcha:'.$id);
        if ($expected === null) {
            return false;
        }

        return hash_equals($expected, trim($answer));
    }
}
