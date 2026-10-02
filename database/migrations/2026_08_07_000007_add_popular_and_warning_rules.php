<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // شارة "الأكثر طلباً" — يتحكم بها الأدمن من محرر الخطط.
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('is_popular')->default(false)->after('is_public');
        });

        // قواعد تحذير الاستخدام — قابلة للتعديل من قاعدة البيانات
        // (لا عتبات Hardcoded 80/90/100).
        Schema::create('usage_warning_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('resource', 50);          // storage | videos | students | ...
            $table->string('level', 20);             // warning | critical | blocked
            $table->decimal('threshold', 5, 2);      // 80.00 = 80%
            $table->string('severity', 20)->default('info');
            $table->string('message_ar', 500)->nullable();
            $table->string('message_en', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['resource', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_warning_rules');
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('is_popular');
        });
    }
};
