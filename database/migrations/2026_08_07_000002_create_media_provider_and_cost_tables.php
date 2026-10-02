<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Media Providers: إدارة مزودي الوسائط من لوحة الأدمن ───
        Schema::create('media_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50)->unique();        // cloudflare|mux|bunny|aws...
            $table->string('label')->nullable();
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();         // مشفرة (JSON)
            $table->string('webhook_secret')->nullable();    // مشفر
            $table->jsonb('config')->default('{}');          // environment, account_id مخزنة داخل credentials
            $table->string('environment', 20)->default('production'); // sandbox|production
            $table->string('connection_status', 20)->default('unknown'); // unknown|healthy|degraded|down|not_configured
            $table->timestampTz('last_health_check_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
        });

        // ─── Provider Pricing: أسعار البنية التحتية ديناميكية ───
        Schema::create('provider_pricing', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('resource', 50);      // storage_gb|delivery_gb|live_minutes|video_minutes|encoding_minutes
            $table->decimal('unit_price', 12, 6)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->date('effective_from')->default(now()->toDateString());
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->unique(['provider', 'resource', 'effective_from']);
            $table->index(['provider', 'resource']);
        });

        // ─── Media Webhook Events: Idempotency + Replay Protection ───
        Schema::create('media_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('event_type', 100);
            $table->string('provider_event_id', 500)->nullable(); // معرّف الحدث من البوابة
            $table->string('event_hash', 64);                    // sha256 لمعالجة إعادة الإرسال
            $table->jsonb('payload')->default('{}');
            $table->string('status', 20)->default('received');   // received|processed|failed|skipped
            $table->string('error', 1000)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->index(['provider', 'event_hash']);
            $table->index(['provider', 'provider_event_id']);
            $table->index(['status', 'received_at']);
        });

        // ─── Media Costs: لقطات التكلفة الشهرية لكل معلم ───
        Schema::create('media_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('year_month', 7);
            $table->string('provider', 50)->default('cloudflare');
            $table->string('resource', 50);
            $table->decimal('amount', 14, 6)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('calculated_at');
            $table->unique(['tenant_id', 'year_month', 'provider', 'resource']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_costs');
        Schema::dropIfExists('media_webhook_events');
        Schema::dropIfExists('provider_pricing');
        Schema::dropIfExists('media_providers');
    }
};
