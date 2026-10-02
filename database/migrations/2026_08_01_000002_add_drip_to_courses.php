<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('drip_enabled')->default(false)->after('certificate_enabled');
            $table->integer('drip_interval_days')->default(1)->after('drip_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['drip_enabled', 'drip_interval_days']);
        });
    }
};
