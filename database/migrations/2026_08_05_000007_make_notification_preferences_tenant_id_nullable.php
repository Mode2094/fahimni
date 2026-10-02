<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preferences may be global (platform-wide types) or tenant-scoped;
        // the service layer already queries both branches but the column was
        // NOT NULL, making the global path dead code.
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable(false)->change();
        });
    }
};
