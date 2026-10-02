<?php

namespace App\Support;

use App\Models\ErrorLog;
use Illuminate\Support\Facades\Log;

class ErrorLogger
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'secret', 'token', 'authorization',
        'credential', 'server_key', 'api_key', 'apikey', 'access_token',
        'refresh_token', 'client_secret', 'private_key', 'cookie', 'card_number', 'cvc',
    ];

    public static function log(\Throwable $e, array $extra = []): void
    {
        try {
            ErrorLog::create([
                'level' => $extra['level'] ?? 'error',
                'source' => $extra['source'] ?? null,
                'message' => substr($e->getMessage() ?: get_class($e), 0, 5000),
                'context' => array_merge([
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    // Stack traces carry exception args which can contain
                    // tokens/passwords — keep frame names only, no args.
                    'trace' => collect($e->getTrace())
                        ->take(20)
                        ->map(fn ($frame) => [
                            'file' => $frame['file'] ?? null,
                            'line' => $frame['line'] ?? null,
                            'function' => $frame['function'] ?? null,
                            'class' => $frame['class'] ?? null,
                        ])
                        ->values()
                        ->all(),
                ], $extra['context'] ?? []),
                'url' => $extra['url'] ?? (request()?->fullUrl()),
                'method' => $extra['method'] ?? (request()?->method()),
                'ip' => request()?->ip(),
                'user_id' => $extra['user_id'] ?? (auth()->id()),
                'created_at' => now(),
            ]);
        } catch (\Throwable $inner) {
            Log::error('Failed to persist error log: '.$inner->getMessage());
        }
    }

    public static function logMessage(string $message, array $context = [], string $level = 'error'): void
    {
        try {
            ErrorLog::create([
                'level' => $level,
                'message' => substr($message, 0, 5000),
                'context' => self::redact($context),
                'url' => request()?->fullUrl(),
                'method' => request()?->method(),
                'ip' => request()?->ip(),
                'user_id' => auth()->id(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $inner) {
            Log::error('Failed to persist error log: '.$inner->getMessage());
        }
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redact($value);

                continue;
            }

            foreach (self::SENSITIVE_KEYS as $needle) {
                if (stripos((string) $key, $needle) !== false) {
                    $data[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $data;
    }
}
