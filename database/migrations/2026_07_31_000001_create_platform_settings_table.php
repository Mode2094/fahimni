<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        $defaults = [
            'platform_name' => 'Tadreesly',
            'currency' => 'JOD',
            'currency_rate' => '1',
            'default_locale' => 'ar',
            'logo_url' => null,
            'primary_color' => '#2563eb',
            'notify_new_teacher' => true,
            'notify_payment' => true,
            'notify_exam_submitted' => true,
            'notify_low_storage' => false,
            'registration_enabled' => true,
            'maintenance_mode' => false,
            'two_factor_enabled' => false,
        ];

        foreach ($defaults as $key => $value) {
            DB::table('platform_settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
