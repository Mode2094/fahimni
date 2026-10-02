<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // فهارس استعلامات البث المباشر (provider / owner / حالة):
        // الاستعلامات الأكثر شيوعاً هي: جلسات مستأجر بحالة معينة،
        // جلسات مزود معيّن، جلسات معلم معيّن.
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->index(['tenant_id', 'provider']);
            $table->index('user_id');
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'provider']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['provider', 'status']);
        });
    }
};
