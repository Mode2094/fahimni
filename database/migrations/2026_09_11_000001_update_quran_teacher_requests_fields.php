<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quran_teacher_requests', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('city');
            $table->unsignedTinyInteger('memorized_juz')->nullable()->after('country');
            $table->string('tajweed_level', 50)->nullable()->after('memorized_juz');
            $table->dropColumn('mosque_or_school');
        });
    }

    public function down(): void
    {
        Schema::table('quran_teacher_requests', function (Blueprint $table) {
            $table->string('mosque_or_school')->nullable()->after('city');
            $table->dropColumn(['country', 'memorized_juz', 'tajweed_level']);
        });
    }
};