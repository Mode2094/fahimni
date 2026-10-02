<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lesson_content_id')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('provider', 50)->default('livekit');
            $table->string('room_name');
            $table->string('room_sid', 500)->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->integer('max_participants')->default(0);
            $table->boolean('record_enabled')->default(true);
            $table->string('record_url', 500)->nullable();
            $table->jsonb('config')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->foreign('lesson_content_id')->references('id')->on('lesson_contents')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_sessions');
    }
};
