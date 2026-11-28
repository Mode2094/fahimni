<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `LiveSessionController::cancel()` has always written `cancelled_at`, but the
 * column never existed and the attribute was not fillable, so the value was
 * silently dropped. This adds the column so a cancelled session records when it
 * was cancelled (shown in the teacher's session list and the API payload).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->timestampTz('cancelled_at')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
