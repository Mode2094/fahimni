<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * نظام التكاملات الاختياري (Drive/Dropbox/OneDrive/Telegram/...)
     * أُزيل من Core — الجداول الخاصة به لم تعد مستخدمة.
     */
    public function up(): void
    {
        Schema::dropIfExists('integration_usage');
        Schema::dropIfExists('integration_settings');
    }

    public function down(): void
    {
        // غير قابل للعكس — البيانات أرشيفت في النسخ الاحتياطية.
    }
};
