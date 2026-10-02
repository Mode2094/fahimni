<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Periodic full snapshots collected by `monitoring:collect`.
        Schema::create('monitoring_snapshots', function (Blueprint $table) {
            $table->id();
            // host | app | database | redis | queue | backup | storage | streaming | security
            $table->string('kind', 32)->index();
            $table->jsonb('payload');
            $table->timestampTz('collected_at')->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampsTz();

            $table->index(['kind', 'collected_at']);
        });

        // Deduplicated alert ledger. One row per (key) with status open/resolved.
        Schema::create('monitoring_alerts', function (Blueprint $table) {
            $table->id();
            // warning | critical
            $table->string('level', 16)->index();
            $table->string('source', 64)->index();
            $table->string('key', 190)->unique();
            $table->string('message', 2000);
            $table->jsonb('context')->default('{}');
            // open | resolved
            $table->string('status', 16)->default('open')->index();
            $table->timestampTz('first_seen_at')->index();
            $table->timestampTz('last_seen_at')->index();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'level', 'last_seen_at']);
        });

        // Slow HTTP requests persisted for later analysis (path, status,
        // duration only — never bodies or headers).
        Schema::create('monitoring_slow_requests', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10)->nullable();
            $table->string('path', 500)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('route', 190)->nullable();
            $table->string('source', 16)->default('api'); // api | frontend
            $table->timestampTz('occurred_at')->index();
            $table->index(['status', 'occurred_at']);
        });

        // Structured client-side error events reported by the Next.js app.
        // Intentionally minimal: no tokens, cookies, bodies or user PII.
        Schema::create('frontend_errors', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('app', 32)->default('web'); // web | mobile
            $table->string('url', 1000)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('type', 100)->nullable(); // error | unhandledrejection | http
            $table->string('message', 2000)->nullable();
            $table->string('stack_hash', 64)->nullable()->index(); // sha256 of stack
            $table->string('user_agent', 400)->nullable();
            $table->string('locale', 10)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_snapshots');
        Schema::dropIfExists('monitoring_alerts');
        Schema::dropIfExists('monitoring_slow_requests');
        Schema::dropIfExists('frontend_errors');
    }
};