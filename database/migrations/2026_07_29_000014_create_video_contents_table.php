<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lesson_content_id')->unique();
            $table->uuid('tenant_id');
            $table->string('provider', 50);
            $table->string('provider_video_id', 500);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->integer('duration')->default(0);
            $table->string('status', 20)->default('processing');
            $table->bigInteger('file_size_bytes')->nullable();
            $table->jsonb('config')->default('{}');
            $table->timestampsTz();

            $table->index(['provider', 'provider_video_id']);
            $table->index(['tenant_id', 'status']);
            $table->foreign('lesson_content_id')->references('id')->on('lesson_contents')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_contents');
    }
};
