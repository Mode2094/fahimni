<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * صلابة قاعدة البيانات:
     *  - event_hash فريد لكل مزود (حماية idempotency على مستوى DB).
     *  - foreign key إلزامي media_assets → tenants.
     * متوافق مع Postgres وSQLite.
     */
    public function up(): void
    {
        Schema::table('media_webhook_events', function (Blueprint $table) {
            $table->dropIndex('media_webhook_events_provider_event_hash_index');
            $table->unique(['provider', 'event_hash'], 'media_webhook_events_provider_event_hash_unique');
        });

        // إضافة FK إن لم تكن موجودة (الجداول القديمة قد لا تملكها).
        if (! Schema::hasTable('media_assets')) {
            return;
        }

        try {
            Schema::table('media_assets', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        } catch (\Throwable) {
            // موجودة بالفعل — نتجاهل.
        }
    }

    public function down(): void
    {
        Schema::table('media_webhook_events', function (Blueprint $table) {
            $table->dropUnique('media_webhook_events_provider_event_hash_unique');
            $table->index(['provider', 'event_hash'], 'media_webhook_events_provider_event_hash_index');
        });
    }
};
