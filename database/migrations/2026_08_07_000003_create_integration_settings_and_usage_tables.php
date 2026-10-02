<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── إعدادات التكاملات (key-value، قيم مشفرة عند الطلب) ───
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('integration_id');
            $table->uuid('tenant_id')->nullable();      // null = منصة
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestampsTz();
            $table->unique(['integration_id', 'tenant_id', 'key']);
            $table->foreign('integration_id')->references('id')->on('integrations')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // ─── استخدام التكاملات (عدادات شهرية) ─────────────
        Schema::create('integration_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('integration_id');
            $table->uuid('tenant_id')->nullable();
            $table->string('resource', 60);             // meetings_created|messages_sent|videos_uploaded|files_imported
            $table->string('year_month', 7);
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestampsTz();
            $table->unique(['integration_id', 'tenant_id', 'resource', 'year_month']);
            $table->foreign('integration_id')->references('id')->on('integrations')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_usage');
        Schema::dropIfExists('integration_settings');
    }
};
