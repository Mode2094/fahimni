<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // idempotency_key يمنع احتساب نفس الحدث (إيقاف بث/إنهاء رفع) مرتين
        // في الـ Ledger — الحماية من المزامنة المزدوجة (تحكم يدوي + webhook).
        Schema::table('media_usage_ledger', function (Blueprint $table) {
            $table->string('idempotency_key', 190)->nullable()->after('reference_type');
            $table->unique('idempotency_key');
        });

        // مؤشر زمني لإشارات WebRTC — تنظيف دوري للإشارات القديمة.
        Schema::table('live_webrtc_signals', function (Blueprint $table) {
            $table->index(['created_at']);
        });

        // مالك الجلسة (المعلم الذي أنشأها) — لأغراض الصلاحيات والتتبع.
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('tenant_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_usage_ledger', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('live_webrtc_signals', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
