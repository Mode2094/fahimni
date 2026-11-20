<?php

namespace App\Support;

/**
 * Derives per-skill performance from REAL graded answers — never invents a
 * number. A skill is any string a teacher attached to a question via
 * `metadata.skills` (one or more per question). For every skill we aggregate
 * the points the student earned against the points the questions were worth,
 * which is honest even when only some questions of a skill were reached.
 *
 * Levels are a deterministic banding of that measured accuracy, so the same
 * answers always classify the same way:
 *   >= 90%  advanced
 *   >= 75%  proficient
 *   >= 50%  developing
 *   <  50%  needs_support
 */
final class SkillPerformance
{
    public const LEVEL_ADVANCED = 'advanced';
    public const LEVEL_PROFICIENT = 'proficient';
    public const LEVEL_DEVELOPING = 'developing';
    public const LEVEL_NEEDS_SUPPORT = 'needs_support';

    /**
     * Normalise the `metadata.skills` value into a clean list of skill names.
     *
     * @return string[]
     */
    public static function skillsOf(?array $metadata): array
    {
        $raw = $metadata['skills'] ?? [];

        if (is_string($raw)) {
            $raw = preg_split('/[,،]/u', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $skill) {
            if (! is_string($skill)) {
                continue;
            }
            $skill = trim($skill);
            if ($skill !== '' && ! in_array($skill, $out, true)) {
                $out[] = $skill;
            }
        }

        return $out;
    }

    public static function levelFor(float $accuracy): string
    {
        return match (true) {
            $accuracy >= 90 => self::LEVEL_ADVANCED,
            $accuracy >= 75 => self::LEVEL_PROFICIENT,
            $accuracy >= 50 => self::LEVEL_DEVELOPING,
            default => self::LEVEL_NEEDS_SUPPORT,
        };
    }

    /**
     * @param  array<string, array{points_earned: float, points_max: float}>  $buckets
     * @return list<array{name: string, points_earned: float, points_max: float, accuracy: float, level: string}>
     */
    public static function summarise(array $buckets): array
    {
        $out = [];

        foreach ($buckets as $name => $bucket) {
            $max = (float) $bucket['points_max'];
            if ($max <= 0) {
                continue;
            }
            $earned = (float) $bucket['points_earned'];
            $accuracy = round(($earned / $max) * 100, 2);

            $out[] = [
                'name' => (string) $name,
                'points_earned' => round($earned, 2),
                'points_max' => round($max, 2),
                'accuracy' => $accuracy,
                'level' => self::levelFor($accuracy),
            ];
        }

        usort($out, fn ($a, $b) => $b['accuracy'] <=> $a['accuracy'] ?: strcmp($a['name'], $b['name']));

        return $out;
    }
}
