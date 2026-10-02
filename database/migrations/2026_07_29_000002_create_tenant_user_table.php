<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('role', 20)->default('student');
            $table->string('status', 20)->default('active');
            $table->timestampTz('joined_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->primary(['tenant_id', 'user_id']);
            $table->index('user_id');
            $table->index(['tenant_id', 'role']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
