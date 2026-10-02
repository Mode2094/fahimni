<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('certificate_template', 40)->default('classic')->after('certificate_enabled');
            $table->string('certificate_accent_color', 20)->default('#2563eb')->after('certificate_template');
            $table->string('certificate_signature_name', 255)->nullable()->after('certificate_accent_color');
            $table->string('certificate_signature_title', 255)->nullable()->after('certificate_signature_name');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['certificate_template', 'certificate_accent_color', 'certificate_signature_name', 'certificate_signature_title']);
        });
    }
};
