<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ungraded interactive skill practice.
 *
 * Fahimni has two kinds of assessment on the same engine:
 *   graded   — the classic examination: timer, points, pass mark, anti-cheat,
 *              score, ranking. Unchanged.
 *   practice — a skill exercise: no timer, no points, no pass/fail, no
 *              anti-cheat. Completion is recorded when a supported exercise
 *              reports done:true.
 *
 * `assessment_mode` lives on the exam (the teacher's choice) and is snapshotted
 * onto the attempt at start, so flipping an exam's mode mid-attempt can never
 * reclassify a live attempt. Both columns are additive with a default that
 * preserves every existing row as a formal graded assessment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (! Schema::hasColumn('exams', 'assessment_mode')) {
                $table->string('assessment_mode', 20)->default('graded')->after('anti_cheat_enabled');
            }
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_attempts', 'assessment_mode')) {
                $table->string('assessment_mode', 20)->nullable()->after('status');
            }

            if (! Schema::hasColumn('exam_attempts', 'completed_at')) {
                $table->timestampTz('completed_at')->nullable()->after('submitted_at');
            }
        });
    }

    public function down(): void
    {
        // القيمة 'graded' هي الافتراض الآمن لكل صف قائم؛ الحذف لا يغيّر سلوكاً.
        Schema::table('exams', function (Blueprint $table) {
            if (Schema::hasColumn('exams', 'assessment_mode')) {
                $table->dropColumn('assessment_mode');
            }
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            if (Schema::hasColumn('exam_attempts', 'assessment_mode')) {
                $table->dropColumn('assessment_mode');
            }

            if (Schema::hasColumn('exam_attempts', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
