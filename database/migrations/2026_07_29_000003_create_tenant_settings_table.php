<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('academy_name')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->string('favicon_url', 500)->nullable();
            $table->string('primary_color', 7)->default('#3B82F6');
            $table->string('secondary_color', 7)->default('#1E40AF');
            $table->string('accent_color', 7)->nullable();
            $table->string('custom_domain', 255)->nullable()->unique();
            $table->text('custom_css')->nullable();
            $table->text('custom_js')->nullable();
            $table->string('email_from_name')->nullable();
            $table->string('email_logo_url', 500)->nullable();
            $table->string('terms_url', 500)->nullable();
            $table->string('privacy_url', 500)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
