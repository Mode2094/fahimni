<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('type');
            $table->string('notifiable_type');
            $table->uuid('notifiable_id');
            $table->uuid('sender_id')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->jsonb('data')->default('{}');
            $table->text('channels')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->boolean('read_in_app')->default(false);
            $table->timestampsTz();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['tenant_id', 'notifiable_type', 'notifiable_id']);
            $table->index(['type', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
