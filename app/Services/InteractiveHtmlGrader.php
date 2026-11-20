<?php

namespace App\Services;

use App\Models\Question;

/**
 * Server-side grader for `interactive_html` questions.
 *
 * The teacher's HTML/CSS/JS never decides a score: the sandboxed browser frame
 * only reports the value the student produced. This service compares that
 * value against the hidden `metadata.grading` rule, so an answer is never
 * "correct" merely because something was submitted. A question without a
 * decisive rule is flagged for manual review (`grade()` returns null) and can
 * never auto-pass.
 *
 * Grading config lives under `questions.metadata.grading`:
 *   mode      => 'auto' | 'manual'      (default: manual)
 *   rule      => single_choice | numeric | exact_set | ordered | json_equals | contains_all
 *   key       => the hidden expected value (any JSON shape)
 *   tolerance => numeric tolerance for the `numeric` rule
 *
 * The display payload (html/css/js) lives under `metadata.interactive` and is
 * the only part ever sent to a student — see
 * ExamAttemptController::studentSafeMetadata().
 */
class InteractiveHtmlGrader
{
    public const MODE_AUTO = 'auto';

    public const MODE_MANUAL = 'manual';

    public const RULES = [
        'single_choice', 'numeric', 'exact_set', 'ordered', 'json_equals', 'contains_all',
    ];

    /** Largest answer we will store/compare, measured in bytes of its JSON form. */
    public const MAX_ANSWER_BYTES = 65535;

    /** @return array<string, mixed> */
    public function config(Question $question): array
    {
        $grading = $question->metadata['grading'] ?? null;

        return is_array($grading) ? $grading : [];
    }

    public function isAutoGraded(Question $question): bool
    {
        $config = $this->config($question);

        return ($config['mode'] ?? self::MODE_MANUAL) === self::MODE_AUTO
            && array_key_exists('key', $config)
            && $config['key'] !== null
            && in_array($config['rule'] ?? 'single_choice', self::RULES, true);
    }

    public function requiresManualReview(Question $question): bool
    {
        return ! $this->isAutoGraded($question);
    }

    /**
     * @return bool|null true/false when auto-gradable; null when a teacher must
     *                   review the answer (never silently marked correct).
     */
    public function grade(Question $question, mixed $given): ?bool
    {
        if (! $this->isAutoGraded($question)) {
            return null;
        }

        $config = $this->config($question);
        $key = $config['key'];
        $given = $this->decode($given);

        return match ($config['rule'] ?? 'single_choice') {
            'numeric' => $this->gradeNumeric($given, $key, (float) ($config['tolerance'] ?? 0)),
            'exact_set' => $this->gradeSet($given, $key),
            'ordered' => $this->gradeOrdered($given, $key),
            'json_equals' => $this->gradeJsonEquals($given, $key),
            'contains_all' => $this->gradeContainsAll($given, $key),
            default => $this->scalarEquals($this->unwrap($given), $this->unwrap($key)),
        };
    }

    /** Reject absurd payloads before they are compared or stored. */
    public function isWithinSizeLimit(mixed $value): bool
    {
        $encoded = is_string($value) ? $value : json_encode($value ?? null);

        return $encoded === false || strlen($encoded) <= self::MAX_ANSWER_BYTES;
    }

    private function decode(mixed $given): mixed
    {
        if (is_string($given)) {
            $trimmed = trim($given);
            if ($trimmed !== '' && in_array($trimmed[0], ['{', '[', '"'], true)) {
                $decoded = json_decode($trimmed, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            return $trimmed;
        }

        return $given;
    }

    private function gradeNumeric(mixed $given, mixed $key, float $tolerance): bool
    {
        $g = $this->unwrap($given);
        $k = $this->unwrap($key);

        if (! is_numeric($g) || ! is_numeric($k)) {
            return false;
        }

        return abs((float) $g - (float) $k) <= max(0.0, $tolerance);
    }

    /** Order-insensitive multiset equality using loose scalar comparison. */
    private function gradeSet(mixed $given, mixed $key): bool
    {
        $remaining = $this->toList($given);
        $expected = $this->toList($key);

        if ($remaining === null || $expected === null || count($remaining) !== count($expected)) {
            return false;
        }

        foreach ($expected as $want) {
            $matched = false;
            foreach ($remaining as $i => $have) {
                if ($this->scalarEquals($have, $want)) {
                    unset($remaining[$i]);
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private function gradeOrdered(mixed $given, mixed $key): bool
    {
        $given = $this->toList($given);
        $key = $this->toList($key);

        if ($given === null || $key === null || count($given) !== count($key)) {
            return false;
        }

        foreach (array_values($key) as $i => $expected) {
            if (! $this->scalarEquals($given[$i] ?? null, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function gradeJsonEquals(mixed $given, mixed $key): bool
    {
        return $this->canonical($given) === $this->canonical($key);
    }

    private function gradeContainsAll(mixed $given, mixed $key): bool
    {
        $given = $this->toList($given);
        $key = $this->toList($key);

        if ($given === null || $key === null) {
            return false;
        }

        foreach ($key as $expected) {
            $found = false;
            foreach ($given as $have) {
                if ($this->scalarEquals($have, $expected)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** Allow `{ "value": x }` envelopes so components can send richer objects. */
    private function unwrap(mixed $value): mixed
    {
        if (is_array($value) && count($value) === 1 && array_key_exists('value', $value)) {
            return $value['value'];
        }

        return $value;
    }

    private function scalarEquals(mixed $a, mixed $b): bool
    {
        $a = $this->unwrap($a);
        $b = $this->unwrap($b);

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $this->scalarString($a) === $this->scalarString($b);
    }

    private function scalarString(mixed $value): string
    {
        $value = $this->unwrap($value);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }
        if (is_scalar($value)) {
            return mb_strtolower(trim((string) $value));
        }

        return (string) json_encode($value);
    }

    private function toList(mixed $value): ?array
    {
        $value = $this->unwrap($value);

        if (is_array($value)) {
            return array_values($value);
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }
        }

        return null;
    }

    private function canonical(mixed $value): string
    {
        return (string) json_encode($this->sortRecursive($this->unwrap($value)));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->sortRecursive($v);
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
        }

        return $value;
    }
}
