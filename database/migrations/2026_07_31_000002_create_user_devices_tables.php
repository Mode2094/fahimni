<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('device_id', 100);
            $table->string('device_name', 190)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'device_id']);
            $table->index(['user_id', 'tenant_id']);
        });

        Schema::create('student_device_limits', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->uuid('student_id');
            $table->integer('max_devices')->default(1);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_device_limits');
        Schema::dropIfExists('user_devices');
    }
};
