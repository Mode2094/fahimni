<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ربط VideoContent بدرسه مباشرة (بالإضافة إلى lesson_content_id).
     */
    public function up(): void
    {
        Schema::table('video_contents', function (Blueprint $table) {
            $table->uuid('lesson_id')->nullable()->after('lesson_content_id');
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::table('video_contents', function (Blueprint $table) {
            $table->dropIndex(['lesson_id']);
            $table->dropColumn('lesson_id');
        });
    }
};
