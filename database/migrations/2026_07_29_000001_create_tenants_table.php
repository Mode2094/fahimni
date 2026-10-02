<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->uuid('owner_id')->nullable();
            $table->string('locale', 10)->default('ar');
            $table->string('timezone', 50)->default('Asia/Riyadh');
            $table->integer('max_students')->default(0);
            $table->integer('storage_limit_mb')->default(5000);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('owner_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
