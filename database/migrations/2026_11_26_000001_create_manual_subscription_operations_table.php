<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super-Admin MANUAL subscription operations — the audit-grade history of
 * every activation / renewal / upgrade / downgrade / extension / free grant
 * performed by hand from the teacher's admin page.
 *
 * This table is additive and never rewrites the financial archive:
 *  - Paid operations also create a `payments` row (gateway `manual`) linked
 *    back here via `payment_id`, plus the matching ledger entry and invoice.
 *  - Free grants create NO payment/revenue row at all — a comped plan must
 *    never show up as collected money.
 *  - Historical payment/invoice/subscription rows are never mutated; a new
 *    operation is always a new row.
 *
 * `request_id` is unique so a retried submit (double-click / network retry)
 * is idempotent instead of double-activating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_subscription_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tenant_subscription_id')->nullable();
            $table->uuid('payment_id')->nullable();

            $table->string('operation_type', 30);
            $table->string('status', 20)->default('applied');

            $table->uuid('previous_plan_id')->nullable();
            $table->uuid('new_plan_id')->nullable();

            $table->string('billing_cycle', 10)->nullable();
            $table->integer('duration_days')->nullable();

            $table->timestampTz('previous_ends_at')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->timestampTz('payment_date')->nullable();

            // Private storage path (local disk). Never exposed publicly; the
            // proof is streamed only through the super-admin download route.
            $table->string('proof_path', 500)->nullable();

            $table->text('reason')->nullable();

            $table->uuid('performed_by')->nullable();
            $table->string('performed_by_name', 255)->nullable();

            $table->string('request_id', 80)->nullable()->unique();

            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_subscription_id', 'created_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tenant_subscription_id')->references('id')->on('tenant_subscriptions')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_subscription_operations');
    }
};
