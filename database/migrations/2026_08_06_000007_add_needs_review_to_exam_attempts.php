<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->after('passed');
            $table->timestampTz('reviewed_at')->nullable()->after('needs_review');
            $table->uuid('reviewed_by')->nullable()->after('reviewed_at');
            $table->index(['exam_id', 'needs_review'], 'idx_exam_attempts_review');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_exam_attempts_review');
            $table->dropColumn(['needs_review', 'reviewed_at', 'reviewed_by']);
        });
    }
};
