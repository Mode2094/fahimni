<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lesson_content_id')->unique();
            $table->uuid('tenant_id');
            $table->string('original_name');
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->string('extension', 20);
            $table->boolean('is_downloadable')->default(false);
            $table->string('thumbnail_url', 500)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['tenant_id', 'mime_type']);
            $table->foreign('lesson_content_id')->references('id')->on('lesson_contents')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_contents');
    }
};
