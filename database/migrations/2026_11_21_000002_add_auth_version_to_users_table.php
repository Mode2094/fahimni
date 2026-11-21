<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web sessions must be revocable server-side without waiting for the cookie
 * to expire. Laravel's session store is keyed by session id, so a password
 * change / account deactivation / device reset cannot address "all sessions
 * of user X" on its own. Every session records the account's `auth_version`;
 * bumping the column invalidates every session that still carries the old
 * value on its next request (see EnsureAccountActive).
 *
 * Additive and nullable-free with a default, so an old release keeps working
 * if the deploy is rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('auth_version')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auth_version');
        });
    }
};
