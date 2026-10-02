<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code', 50)->unique();
            $table->string('type', 20)->default('percent');
            $table->decimal('value', 10, 2);
            $table->string('currency', 10)->default('JOD');
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'is_active']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('subject', 200);
            $table->string('category', 50)->default('general');
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->uuid('assigned_to')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->timestampTz('resolved_at')->nullable();

            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('user_id')->nullable();
            $table->text('body');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index('ticket_id');
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('error_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('level', 20)->default('error');
            $table->string('source', 100)->nullable();
            $table->text('message');
            $table->jsonb('context')->default('{}');
            $table->string('url', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_id', 36)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->index('level');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('coupons');
    }
};
