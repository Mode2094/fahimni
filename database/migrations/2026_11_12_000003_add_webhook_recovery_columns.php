<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook recovery support (additive, non-destructive).
 *
 * Adds retry/lock bookkeeping to `webhook_events` so a transient failure
 * (e.g. Freemius API timeout) can be safely retried on redelivery or by a
 * reconciliation job, without ever double-applying a successful event.
 *
 *  - attempts     : how many processing attempts were made.
 *  - locked_until : a short exclusive lock so two concurrent deliveries of
 *                   the same failed event cannot process it at once.
 *  - last_error   : the last failure reason (bounded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('webhook_events', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0)->after('processing_status');
            }
            if (! Schema::hasColumn('webhook_events', 'locked_until')) {
                $table->timestamp('locked_until', 0)->nullable()->after('attempts');
            }
            if (! Schema::hasColumn('webhook_events', 'last_error')) {
                $table->string('last_error', 500)->nullable()->after('failure_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            foreach (['attempts', 'locked_until', 'last_error'] as $col) {
                if (Schema::hasColumn('webhook_events', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
