<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->string('feature_key', 100);
            $table->string('feature_value');
            $table->string('feature_type', 20)->default('string');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['plan_id', 'feature_key']);
            $table->index('feature_key');

            $table->foreign('plan_id')->references('id')->on('subscription_plans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
