<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-teacher UI preferences (never per device): stored on the user row so a
 * teacher who switches browser or machine still gets their own editor setup,
 * and keyed by tenant so switching accounts/tenants never leaks settings
 * across them.
 *
 * Only a strict ALLOWLIST of non-secret editor fields is accepted and kept —
 * an answer key, a grading secret or any unknown key is silently dropped, so
 * this endpoint can never become a place to stash sensitive data.
 */
class UserPreferenceController extends Controller
{
    /** Each key maps to the exact set of scalar fields allowed under it. */
    private const ALLOWED = [
        'html_editor' => [
            'height' => 'string',
            'grading_mode' => 'string',
            'auto_capture' => 'boolean',
            'grading_rule' => 'string',
        ],
    ];

    public function show(Request $request): JsonResponse
    {
        $tenantId = (string) ($request->header('X-Tenant-Id') ?? '');
        $stored = $request->user()->metadata['ui_preferences'][$tenantId] ?? [];

        return response()->json([
            'preferences' => $this->onlyAllowed(is_array($stored) ? $stored : []),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = (string) ($request->header('X-Tenant-Id') ?? '');
        $incoming = $request->input('preferences', []);

        if (! is_array($incoming)) {
            return response()->json(['message' => 'Invalid preferences payload.'], 422);
        }

        $user = $request->user();
        $metadata = $user->metadata ?? [];
        $existing = $metadata['ui_preferences'][$tenantId] ?? [];
        $existing = is_array($existing) ? $existing : [];

        // Merge the allowlisted incoming groups over the existing ones.
        foreach ($this->onlyAllowed($incoming) as $group => $values) {
            $existing[$group] = array_merge($existing[$group] ?? [], $values);
        }

        $metadata['ui_preferences'][$tenantId] = $this->onlyAllowed($existing);
        $user->update(['metadata' => $metadata]);

        return response()->json([
            'preferences' => $metadata['ui_preferences'][$tenantId],
        ]);
    }

    /**
     * Keep only the allowlisted groups and scalar fields with the right type.
     * Anything unknown is dropped rather than persisted.
     *
     * @param  array<string, mixed>  $prefs
     * @return array<string, array<string, mixed>>
     */
    private function onlyAllowed(array $prefs): array
    {
        $out = [];

        foreach (self::ALLOWED as $group => $fields) {
            if (! isset($prefs[$group]) || ! is_array($prefs[$group])) {
                continue;
            }

            $clean = [];
            foreach ($fields as $field => $type) {
                if (! array_key_exists($field, $prefs[$group])) {
                    continue;
                }
                $value = $prefs[$group][$field];
                if (! is_scalar($value)) {
                    continue;
                }
                // Coerce to the declared type; keep only simple, safe values.
                $clean[$field] = match ($type) {
                    'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
                    default => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                };
            }

            if ($clean !== []) {
                $out[$group] = $clean;
            }
        }

        return $out;
    }
}
