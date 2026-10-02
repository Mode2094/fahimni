<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_lessons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('lesson_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->date('repeat_until')->nullable();
            $table->string('provider', 50)->default('custom');
            $table->string('meeting_url', 500)->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'status', 'lesson_date']);
            $table->index(['tenant_id', 'is_recurring']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Enable the "scheduled lessons" feature on every existing plan
        // (it is a core feature for teachers, mirror of the seeder default).
        $plans = DB::table('subscription_plans')->pluck('id');
        foreach ($plans as $planId) {
            DB::table('plan_features')->insertOrIgnore([
                'id' => UuidV7::generate(),
                'plan_id' => $planId,
                'feature_key' => 'scheduled_lessons_enabled',
                'feature_value' => '1',
                'feature_type' => 'boolean',
                'sort_order' => 999,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_lessons');
    }
};
