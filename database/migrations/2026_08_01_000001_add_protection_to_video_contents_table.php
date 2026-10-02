<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_contents', function (Blueprint $table) {
            $table->string('storage_path', 1024)->nullable()->after('provider_video_id');
            $table->string('original_name', 255)->nullable()->after('storage_path');
            $table->string('mime_type', 100)->nullable()->after('original_name');
            $table->boolean('is_uploaded')->default(false)->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('video_contents', function (Blueprint $table) {
            $table->dropColumn(['storage_path', 'original_name', 'mime_type', 'is_uploaded']);
        });
    }
};
