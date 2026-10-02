<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * توسيع عمود webhook_secret إلى TEXT — النص المشفر (AES-256) أطول
     * من 255 حرفاً. متوافق مع Postgres وSQLite.
     */
    public function up(): void
    {
        Schema::table('media_providers', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media_providers', function (Blueprint $table) {
            $table->string('webhook_secret', 255)->nullable()->change();
        });
    }
};
