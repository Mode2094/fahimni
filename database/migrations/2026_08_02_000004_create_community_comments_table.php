<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('thread_id');
            $table->uuid('parent_id')->nullable();
            $table->uuid('user_id');
            $table->longText('body');
            $table->boolean('is_answer')->default(false);
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['thread_id', 'created_at']);
            $table->index(['thread_id', 'is_answer']);
            $table->index('parent_id');
            $table->index('user_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('thread_id')->references('id')->on('community_threads')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Self-referencing FK must be added after the table + primary key exist.
        Schema::table('community_comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('community_comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_comments');
    }
};
