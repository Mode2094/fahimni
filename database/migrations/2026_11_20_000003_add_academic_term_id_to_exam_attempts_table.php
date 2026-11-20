<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag exam attempts with the academic term of the enrollment they belong to.
 *
 * ExamAttempt has no enrollment_id, and the attempt limit was historically
 * global per (exam, user). Adding a nullable academic_term_id lets the limit
 * be enforced PER TERM without touching historical rows:
 *   - legacy attempts keep NULL → they belong to the "no term" scope only;
 *   - a student re-enrolled in a new term gets a fresh attempt budget;
 *   - results from different terms never count against each other.
 * The global (exam_id, user_id, attempt_number) unique key is intentionally
 * left intact: numbering stays monotonic across terms (so no collisions),
 * while only the LIMIT and the displayed scope are term-aware.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->uuid('academic_term_id')->nullable()->after('user_id');
            $table->index(['exam_id', 'user_id', 'academic_term_id'], 'exam_attempts_scope_index');
            $table->foreign('academic_term_id')->references('id')->on('academic_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropForeign(['academic_term_id']);
            $table->dropIndex('exam_attempts_scope_index');
            $table->dropColumn('academic_term_id');
        });
    }
};
