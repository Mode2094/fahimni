<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('group_id')->nullable();
            $table->uuid('user_id');
            $table->string('title', 255);
            $table->longText('body');
            $table->string('type', 20)->default('discussion');
            $table->jsonb('tags')->nullable();
            $table->string('status', 20)->default('open');
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('answers_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'status', 'last_activity_at']);
            $table->index(['tenant_id', 'group_id', 'status']);
            $table->index(['tenant_id', 'type', 'status']);
            $table->index('user_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('community_groups')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_threads');
    }
};
