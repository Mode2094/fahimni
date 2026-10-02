<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quran_teacher_requests', function (Blueprint $table) {
            $table->uuid('plan_id')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('quran_teacher_requests', function (Blueprint $table) {
            $table->dropColumn('plan_id');
        });
    }
};
