<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds term scoping + archiving to enrollments and replaces the global
 * (course_id, user_id) unique constraint with a PARTIAL unique index that
 * only guards the states which actually grant access.
 *
 * Why: a student who finished a course in Term 1 must be able to re-join the
 * SAME course in Term 2 without overwriting their Term 1 history (grades and
 * attempts reference the enrollment row). With a single global unique this
 * is impossible — the second insert collides. Scoping the guard to
 * (active, completed) lets archived rows coexist as immutable history while
 * still preventing two live enrollments for the same course + student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->uuid('academic_term_id')->nullable()->after('course_id');
            $table->timestampTz('archived_at')->nullable()->after('expires_at');
            $table->index('academic_term_id');
            $table->foreign('academic_term_id')->references('id')->on('academic_terms')->nullOnDelete();
        });

        // Drop the global unique. Laravel emits the platform-correct DDL
        // (DROP CONSTRAINT on Postgres, table rebuild on SQLite).
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_course_id_user_id_unique');
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            // Partial unique: one live enrollment per (course, user); any
            // number of archived history rows.
            DB::statement(
                "CREATE UNIQUE INDEX enrollments_effective_course_user_unique "
                ."ON enrollments (course_id, user_id) "
                ."WHERE status IN ('active', 'completed')"
            );
        } else {
            // Fallback for engines without partial indexes (not used in
            // production or CI, kept so the migration never hard-fails).
            Schema::table('enrollments', function (Blueprint $table) {
                $table->unique(['course_id', 'user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS enrollments_effective_course_user_unique');
        }

        Schema::table('enrollments', function (Blueprint $table) {
            // Restore the original constraint. Best-effort: if a term renewal
            // already produced duplicate (course, user) history rows this may
            // fail — that is expected and only matters for a manual rollback.
            $table->unique(['course_id', 'user_id']);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['academic_term_id']);
            $table->dropIndex(['academic_term_id']);
            $table->dropColumn(['academic_term_id', 'archived_at']);
        });
    }
};
