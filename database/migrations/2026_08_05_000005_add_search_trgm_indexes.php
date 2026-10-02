<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX idx_users_name_trgm ON users USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX idx_users_email_trgm ON users USING gin (email gin_trgm_ops)');
        DB::statement('CREATE INDEX idx_text_contents_body_trgm ON text_contents USING gin (body gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('users', function ($table) {
            $table->dropIndex('idx_users_name_trgm');
            $table->dropIndex('idx_users_email_trgm');
        });

        DB::statement('DROP INDEX IF EXISTS idx_text_contents_body_trgm');
    }
};
