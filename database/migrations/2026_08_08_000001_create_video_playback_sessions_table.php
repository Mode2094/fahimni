<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_playback_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('course_id')->nullable();
            $table->uuid('lesson_id')->nullable();
            $table->uuid('video_id');
            $table->string('device_id', 100)->nullable();
            $table->string('device_hash', 64)->nullable();
            $table->boolean('watermark_enabled')->default(false);
            $table->string('watermark_text', 255)->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'video_id'], 'vps_user_video_idx');
            $table->index(['tenant_id', 'video_id'], 'vps_tenant_video_idx');
            $table->index(['expires_at'], 'vps_expires_idx');
            $table->index(['user_id', 'device_hash'], 'vps_user_device_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_playback_sessions');
    }
};
