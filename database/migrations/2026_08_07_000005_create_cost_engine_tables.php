<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── قواعد تكلفة المزودين (Database = مصدر الحقيقة الوحيد) ───
        Schema::create('provider_cost_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);        // bunny | cloudflare | email | sms
            $table->string('resource', 50);        // storage | delivery | encoding | live | email | sms
            $table->string('unit', 20)->default('unit'); // GB | hour | video | email | sms
            $table->decimal('cost', 12, 6);        // تكلفة الوحدة
            $table->string('currency', 10)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->default('{}'); // delivery_factor, expected_emails_month...
            $table->timestampsTz();

            $table->unique(['provider', 'resource']);
        });

        // ─── تكلفة الاستخدام الفعلية لكل معلم/شهر ───
        Schema::create('provider_usage_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('provider', 50);
            $table->string('resource', 50);
            $table->decimal('quantity', 14, 4);
            $table->decimal('cost', 12, 6);
            $table->string('currency', 10)->default('USD');
            $table->string('year_month', 7);       // 2026-08
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['tenant_id', 'year_month']);
            $table->unique(['tenant_id', 'provider', 'resource', 'year_month']);
        });

        // ─── ربحية الخطط (لقطة محسوبة) ───
        Schema::create('plan_profitability', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->string('billing_cycle', 10)->default('monthly'); // monthly | yearly
            $table->decimal('subscription_price', 12, 2);
            $table->decimal('estimated_cost', 12, 2)->default(0);   // أقصى تكلفة متوقعة
            $table->decimal('expected_profit', 12, 2)->default(0);
            $table->decimal('margin_percentage', 8, 2)->default(0);
            $table->unsignedInteger('subscribers_count')->default(0);
            $table->timestampTz('recalculated_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['plan_id', 'billing_cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_cost_rules');
        Schema::dropIfExists('provider_usage_costs');
        Schema::dropIfExists('plan_profitability');
    }
};
