<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Media Assets: النسخة المرجعية لحالة كل أصل وسائط ───
        Schema::create('media_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();            // المعلم/المالك
            $table->uuid('course_id')->nullable();
            $table->uuid('lesson_content_id')->nullable();  // الربط بنظام المحتوى الحالي
            $table->string('provider', 50);
            $table->string('provider_asset_id', 500);       // uid عند Cloudflare
            $table->string('kind', 30)->default('video');   // video | live_recording | file
            $table->string('status', 30)->default('processing'); // processing|ready|error|deleted|orphan
            $table->string('title')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);        // حجم الملف المرفوع
            $table->unsignedBigInteger('storage_bytes')->default(0);     // ما يستهلكه فعلياً من الخطة
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('playback_id', 500)->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'kind']);
            $table->index(['user_id']);
            $table->index(['provider', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
            $table->foreign('lesson_content_id')->references('id')->on('lesson_contents')->nullOnDelete();
        });

        // نفس الأصل في الـ Provider لا يُسجل مرتين.
        Schema::table('media_assets', function (Blueprint $table) {
            $table->unique(['provider', 'provider_asset_id']);
        });

        // ─── Usage Ledger: سجل حركات الاستخدام (مصدر الحقيقة) ───
        Schema::create('media_usage_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('resource', 50);      // storage_bytes|video_count|live_sessions_count|live_minutes|recording_count|files_count|delivery_minutes
            $table->bigInteger('delta')->default(0);   // موجب/سالب
            $table->unsignedBigInteger('bytes_delta')->default(0); // دلتا التخزين بالبايت
            $table->string('action', 50);        // upload|delete|live_started|live_ended|recording_ready|file_upload|sync|reconcile
            $table->uuid('reference_id')->nullable();
            $table->string('reference_type', 60)->nullable(); // media_asset|live_session|video_content|file_content
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'resource', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        // ─── Usage Aggregates: العدادات الحالية لكل معلم ───
        Schema::create('media_usage_aggregates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('resource', 50);
            $table->bigInteger('value')->default(0);
            $table->timestampTz('updated_at');
            $table->unique(['tenant_id', 'resource']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // ─── Monthly rollup: استخدام شهري (للتقارير والتكلفة) ───
        Schema::create('media_usage_monthly', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('year_month', 7);     // 2026-08
            $table->string('resource', 50);
            $table->bigInteger('value')->default(0);
            $table->timestampTz('updated_at');
            $table->unique(['tenant_id', 'year_month', 'resource']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_usage_monthly');
        Schema::dropIfExists('media_usage_aggregates');
        Schema::dropIfExists('media_usage_ledger');
        Schema::dropIfExists('media_assets');
    }
};
