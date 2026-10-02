<?php

use App\Services\TeacherCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every teacher tenant gets a single, unique, non-sequential join code
     * used by the official Student Mobile App. Codes are generated once and
     * stay stable across sessions (they are not derived from the exposed UUID).
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('teacher_code', 20)->nullable()->unique()->after('slug');
            $table->timestampTz('teacher_code_generated_at')->nullable()->after('teacher_code');
        });

        // Backfill existing tenants so every teacher already has a code when
        // the feature ships. Unique-code collisions are retried internally.
        $generator = new TeacherCodeService;

        foreach (DB::table('tenants')->whereNull('teacher_code')->whereNull('deleted_at')->get() as $tenant) {
            DB::table('tenants')
                ->where('id', $tenant->id)
                ->update([
                    'teacher_code' => $generator->generate(),
                    'teacher_code_generated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['teacher_code']);
            $table->dropColumn(['teacher_code', 'teacher_code_generated_at']);
        });
    }
};
