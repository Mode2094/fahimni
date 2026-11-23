<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duplicating a lesson/course reuses the SAME heavy binary asset (Bunny video /
 * private file) instead of re-uploading or copying bytes. The cloned child rows
 * are pure references: they must not be counted again toward the plan's
 * `videos` / `files` / `pdfs` counts or the `storage` bytes, otherwise a copy
 * would "consume" quota for data that does not exist.
 *
 * `is_shared_reference = true` marks such a mirror row. Defaults to false, so
 * every existing row keeps behaving exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_contents', function (Blueprint $table) {
            $table->boolean('is_shared_reference')->default(false);
            $table->index(['tenant_id', 'is_shared_reference']);
        });

        Schema::table('file_contents', function (Blueprint $table) {
            $table->boolean('is_shared_reference')->default(false);
            $table->index(['tenant_id', 'is_shared_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('video_contents', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_shared_reference']);
            $table->dropColumn('is_shared_reference');
        });

        Schema::table('file_contents', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_shared_reference']);
            $table->dropColumn('is_shared_reference');
        });
    }
};
