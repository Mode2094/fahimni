<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One teacher can only hold one booking per start time — the DB
        // enforces it so concurrent students can never double-book a slot.
        Schema::table('bookings', function (Blueprint $table) {
            $table->unique(['teacher_id', 'starts_at'], 'uniq_bookings_teacher_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('uniq_bookings_teacher_starts_at');
        });
    }
};
