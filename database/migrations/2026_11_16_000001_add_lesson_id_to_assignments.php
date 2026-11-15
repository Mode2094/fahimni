<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worksheets/assignments can now be attached to a specific lesson (not only to
 * a course/section). Nullable so every existing row stays valid: an assignment
 * without a lesson is still a course-level assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->uuid('lesson_id')->nullable()->after('section_id');
            $table->index(['lesson_id', 'sort_order']);
            $table->foreign('lesson_id')->references('id')->on('lessons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropForeign(['lesson_id']);
            $table->dropIndex(['lesson_id', 'sort_order']);
            $table->dropColumn('lesson_id');
        });
    }
};
