<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic named counters for platform-wide quotas that must survive concurrent
 * writes (currently: consumed limited-offer trial seats).
 *
 * A single locked row is the only reliable way to prevent over-granting when
 * two teacher registrations race for the last seat: a bare `COUNT(*)` locks no
 * rows and two transactions can both read "under limit" and both insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_counters', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->bigInteger('value')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_counters');
    }
};
