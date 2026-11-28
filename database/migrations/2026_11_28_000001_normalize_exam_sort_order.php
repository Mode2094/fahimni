<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Examinations existed before per-section ordering was wired up, so every row
 * sits at the default `sort_order = 0`. That makes the student list fall back
 * to an unstable database order. This migration assigns a deterministic
 * sequence (1..n) per section — but ONLY for sections whose exams do not
 * already have a unique order, so a deliberate order is never overwritten.
 *
 * Ties are broken by `created_at` then `id`, preserving the historical relative
 * order as faithfully as the stored data allows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('exams')
            ->whereNull('deleted_at')
            ->whereNotNull('course_id')
            ->select('course_id', 'section_id')
            ->distinct()
            ->orderBy('course_id')
            ->orderBy('section_id')
            ->get();

        foreach ($groups as $group) {
            $query = DB::table('exams')
                ->where('course_id', $group->course_id)
                ->whereNull('deleted_at');

            $group->section_id === null
                ? $query->whereNull('section_id')
                : $query->where('section_id', $group->section_id);

            $exams = $query
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'sort_order']);

            // Already uniquely ordered → leave the teacher's order untouched.
            if ($exams->pluck('sort_order')->unique()->count() >= $exams->count()) {
                continue;
            }

            $index = 1;
            foreach ($exams as $exam) {
                if ((int) $exam->sort_order !== $index) {
                    DB::table('exams')->where('id', $exam->id)->update(['sort_order' => $index]);
                }
                $index++;
            }
        }
    }

    public function down(): void
    {
        // Ordering is data, not schema — nothing to reverse safely.
    }
};
