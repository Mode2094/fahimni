<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tenant_subscription_id')->nullable();
            $table->string('transaction_id');
            $table->string('gateway', 50);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('JOD');
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 50)->nullable();
            $table->jsonb('gateway_data')->default('{}');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->unique(['gateway', 'transaction_id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('status');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tenant_subscription_id')->references('id')->on('tenant_subscriptions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
