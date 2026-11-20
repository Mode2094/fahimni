<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level admin → teacher communication center.
 *
 * This is deliberately SEPARATE from teacher announcements
 * (`announcements` + `notifications`, both tenant-scoped):
 *
 *  - The Super Admin broadcasts news / updates / alerts to teachers across
 *    the whole platform (every tenant), not to one institution's students.
 *  - `admin_notifications` is the single central source of truth. Editing or
 *    retracting it while it is `sent` is reflected at every recipient because
 *    the teacher feed reads from here — never from a per-teacher copy.
 *  - `admin_notification_receipts` is the per-recipient delivery + read
 *    receipt (one row per teacher), which powers "كم قرأ" and never
 *    duplicates a teacher who belongs to more than one institution.
 *  - `admin_notification_revisions` is the protected admin audit trail; it is
 *    intentionally NOT foreign-keyed to the notification so history survives
 *    a hard delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('category', 30)->default('news');
            $table->string('title', 255);
            $table->text('body');
            $table->string('audience', 20)->default('all');
            $table->string('status', 20)->default('draft');
            $table->jsonb('channels')->default('["database"]');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retracted_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
            $table->index('category');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('admin_notification_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('admin_notification_id');
            $table->uuid('user_id');
            $table->timestampsTz();

            $table->unique(['admin_notification_id', 'user_id']);
            $table->foreign('admin_notification_id')->references('id')->on('admin_notifications')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('admin_notification_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('admin_notification_id');
            $table->uuid('user_id');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['admin_notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->foreign('admin_notification_id')->references('id')->on('admin_notifications')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('admin_notification_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('admin_notification_id');
            $table->string('action', 30);
            $table->jsonb('changes')->default('{}');
            $table->uuid('performed_by')->nullable();
            $table->timestampsTz();

            $table->index(['admin_notification_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_revisions');
        Schema::dropIfExists('admin_notification_receipts');
        Schema::dropIfExists('admin_notification_targets');
        Schema::dropIfExists('admin_notifications');
    }
};
