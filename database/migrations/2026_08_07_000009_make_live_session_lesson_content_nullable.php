<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جلسات البث المباشر أصبحت بثاً مستقلاً (Bunny Stream) — لا يشترط
     * ارتباطها بمحتوى درس. العمود lesson_content_id أصبح اختيارياً.
     */
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->uuid('lesson_content_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->uuid('lesson_content_id')->nullable(false)->change();
        });
    }
};
