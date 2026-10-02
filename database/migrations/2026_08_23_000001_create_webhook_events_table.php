<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('event_id', 190);
            $table->string('event_type', 100);
            $table->string('payload_hash', 64);
            $table->json('payload')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_status', 20)->default('pending'); // pending|processed|failed
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            // A provider event can never be processed twice.
            $table->unique(['provider', 'event_id']);
            $table->index(['processing_status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
