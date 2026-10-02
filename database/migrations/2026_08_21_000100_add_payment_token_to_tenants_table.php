<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // PayTabs payment token captured from the first successful
            // subscription checkout — enables charging the saved card
            // directly at renewal time (merchant-initiated transaction).
            $table->string('payment_token')->nullable();
            $table->string('payment_gateway', 20)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('card_last_four', 4)->nullable();

            $table->index('payment_token');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['payment_token']);
            $table->dropColumn(['payment_token', 'payment_gateway', 'card_brand', 'card_last_four']);
        });
    }
};
