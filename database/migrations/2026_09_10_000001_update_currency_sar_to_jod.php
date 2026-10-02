<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')
            ->where('key', 'currency')
            ->update(['value' => json_encode('JOD')]);

        DB::table('subscription_plans')
            ->where('currency', 'SAR')
            ->update(['currency' => 'JOD']);

        DB::table('payments')
            ->where('currency', 'SAR')
            ->update(['currency' => 'JOD']);

        DB::table('invoices')
            ->where('currency', 'SAR')
            ->update(['currency' => 'JOD']);

        DB::table('paid_services')
            ->where('currency', 'SAR')
            ->update(['currency' => 'JOD']);

        DB::table('bookings')
            ->where('currency', 'SAR')
            ->update(['currency' => 'JOD']);
    }

    public function down(): void
    {
        DB::table('platform_settings')
            ->where('key', 'currency')
            ->update(['value' => json_encode('SAR')]);

        DB::table('subscription_plans')
            ->where('currency', 'JOD')
            ->update(['currency' => 'SAR']);

        DB::table('payments')
            ->where('currency', 'JOD')
            ->update(['currency' => 'SAR']);

        DB::table('invoices')
            ->where('currency', 'JOD')
            ->update(['currency' => 'SAR']);

        DB::table('paid_services')
            ->where('currency', 'JOD')
            ->update(['currency' => 'SAR']);

        DB::table('bookings')
            ->where('currency', 'JOD')
            ->update(['currency' => 'SAR']);
    }
};
