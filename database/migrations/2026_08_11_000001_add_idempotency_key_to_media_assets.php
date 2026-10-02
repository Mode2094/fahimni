<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->after('metadata');
        });

        // مفتاح تفرد يمنع إنشاء أكثر من أصل/فيديو لنفس عملية الرفع —
        // حتى مع الطلبات المتزامنة (double click / retry / network replay).
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unique('idempotency_key', 'media_assets_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropUnique('media_assets_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
