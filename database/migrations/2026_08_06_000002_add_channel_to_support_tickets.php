<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Support channel per ticket, derived from the tenant's plan:
        // 'chat'  → dedicated support (live in-site chat with the admin)
        // 'email' → email / priority support (replies sent by email)
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('channel', 10)->default('email')->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
