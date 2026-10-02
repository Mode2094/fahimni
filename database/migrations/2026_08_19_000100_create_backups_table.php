<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Human-facing unique id, e.g. SHAHEAD_BACKUP_2026-08-19_120000_a1b2c3.
            // Doubles as the package directory name inside the backup disk.
            $table->string('backup_id', 80)->unique();
            $table->string('status', 20)->default('queued'); // queued|running|verifying|completed|failed|deleted
            $table->string('stage', 60)->nullable();          // current pipeline stage
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->string('reason', 20)->default('manual');  // manual|scheduled
            $table->uuid('created_by')->nullable();           // null for scheduled runs

            // Internal artifact filenames (never exposed as real paths to clients).
            $table->string('database_dump')->nullable();
            $table->string('files_archive')->nullable();
            $table->string('config_archive')->nullable();
            $table->string('manifest')->nullable();
            $table->string('checksums')->nullable();
            $table->string('system_info')->nullable();

            $table->json('manifest_data')->nullable();        // non-secret metadata
            $table->json('checksums_data')->nullable();       // sha256 per artifact
            $table->json('verification')->nullable();         // verification report
            $table->json('tables')->nullable();               // dumped table list
            $table->json('file_list')->nullable();            // archived path list

            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('database_size_bytes')->default(0);
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedBigInteger('duration_ms')->default(0);

            $table->text('error_message')->nullable();        // technical only, never secrets
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('deleted_at')->nullable();      // soft marker for DELETED status
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};