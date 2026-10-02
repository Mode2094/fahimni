<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * إشارات WebRTC للبث المباشر الداخلي (كاميرا المعلم → الطلاب).
     * المعلم ينشر offer، الطلاب يردون بـ answer، ويتبادل الطرفان
     * مرشحات ICE — كلها عبر هذا الجدول (لا خادم وسيط خارجي).
     */
    public function up(): void
    {
        Schema::create('live_webrtc_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->string('role', 20);           // teacher | student
            $table->string('type', 20);           // offer | answer | ice
            $table->uuid('peer_id')->nullable();  // معرف الطالب (للـ answer/ice)
            $table->jsonb('payload');
            $table->timestampTz('created_at');

            $table->index(['session_id', 'type']);
            $table->index(['session_id', 'peer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_webrtc_signals');
    }
};
