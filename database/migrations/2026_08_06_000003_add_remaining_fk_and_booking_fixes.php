<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production hardening pass:
 *  - bookings: partial unique index so cancelled/soft-deleted bookings don't
 *    permanently burn a slot; FK to payments.
 *  - user_devices / student_device_limits: enforce FKs (trust boundary).
 *  - support_tickets.assigned_to: FK to users.
 *  - conversation_participants.last_read_message_id: FK + index.
 *  - coupons / questions / daily_stats: hot-path indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // ── Bookings ───────────────────────────────────────────────
        // Replace the full unique index with a partial one that only
        // guards live slots, so cancelled/soft-deleted bookings can be
        // rebooked. Safe to run on fresh installs and upgrades alike.
        if ($driver === 'pgsql') {
            // The index was created by Laravel's $table->unique() in an
            // earlier migration, so PostgreSQL registered it as a constraint.
            DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS uniq_bookings_teacher_starts_at');
            DB::statement('DROP INDEX IF EXISTS uniq_bookings_teacher_starts_at');
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ux_bookings_teacher_starts_at_live
                ON bookings (teacher_id, starts_at)
                WHERE deleted_at IS NULL AND status IN ('pending', 'confirmed')");
        } else {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropUnique('uniq_bookings_teacher_starts_at');
                $table->index(['teacher_id', 'starts_at'], 'idx_bookings_teacher_starts_at');
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
        });

        // ── User devices ───────────────────────────────────────────
        Schema::table('user_devices', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('student_device_limits', function (Blueprint $table) {
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── Support tickets ────────────────────────────────────────
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });

        // ── Conversation participants ──────────────────────────────
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->foreign('last_read_message_id')->references('id')->on('conversation_messages')->nullOnDelete();
            $table->index('last_read_message_id');
        });

        // ── Hot-path indexes ───────────────────────────────────────
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['tenant_id', 'last_message_at'], 'idx_conversations_tenant_last_msg');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->index(['is_active', 'expires_at'], 'idx_coupons_active_expiry');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_active'], 'idx_questions_tenant_active');
        });

        Schema::table('daily_stats', function (Blueprint $table) {
            $table->index(['stat_date', 'stat_type'], 'idx_daily_stats_date_type');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ux_bookings_teacher_starts_at_live');
        } else {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropIndex('idx_bookings_teacher_starts_at');
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });

        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('student_device_limits', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['student_id']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropForeign(['last_read_message_id']);
            $table->dropIndex(['last_read_message_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_tenant_last_msg');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex('idx_coupons_active_expiry');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex('idx_questions_tenant_active');
        });

        Schema::table('daily_stats', function (Blueprint $table) {
            $table->dropIndex('idx_daily_stats_date_type');
        });
    }
};
