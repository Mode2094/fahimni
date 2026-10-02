<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('group_id');
            $table->uuid('user_id');
            $table->string('role', 20)->default('member');
            $table->string('status', 20)->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['group_id', 'user_id']);
            $table->index(['tenant_id', 'group_id', 'status']);
            $table->index('user_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('community_groups')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_group_members');
    }
};
