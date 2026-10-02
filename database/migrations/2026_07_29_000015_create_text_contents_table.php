<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lesson_content_id')->unique();
            $table->uuid('tenant_id');
            $table->text('body');
            $table->string('format', 20)->default('html');
            $table->integer('word_count')->default(0);
            $table->integer('reading_time_minutes')->default(0);
            $table->timestampsTz();

            $table->foreign('lesson_content_id')->references('id')->on('lesson_contents')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_contents');
    }
};
