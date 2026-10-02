<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تنظيف أنظمة البث المباشر المحذوفة نهائياً:
 *
 * - zoom_connections: جداول معلمي Zoom (OAuth) — الميزة أُزيلت بالكامل،
 *   وبياناتها خاصة بـ Zoom ولا تُستخدم في أي ميزة باقية → حذف آمن.
 * - live_webrtc_signals: إشارات WebRTC P2P المؤقتة — لا قيمة لها بعد الحذف
 *   (بيانات إشارات عابرة تُنظَّف دورياً أصلاً) → حذف آمن.
 * - platform_settings الموروثة لأنظمة البث المحذوفة: حذف المفاتيح غير
 *   المستخدمة، مع تثبيت المزود الافتراضي على google_meet.
 *
 * لا تمس أي بيانات للفيديوهات المسجلة (Bunny) ولا الجلسات (live_sessions).
 */
return new class extends Migration
{
    /** مفاتيح platform_settings الخاصة بأنظمة أُزيلت نهائياً. */
    private const OBSOLETE_SETTINGS = [
        'live_jitsi_enabled',
        'live_zoom_enabled',
        'live_bunny_enabled',
        'live_cloudflare_enabled',
        'live_browser_broadcast_enabled',
        'live_webrtc_p2p_enabled',
        'live_zoom_s2s_fallback',
    ];

    public function up(): void
    {
        Schema::dropIfExists('live_webrtc_signals');
        Schema::dropIfExists('zoom_connections');

        DB::table('platform_settings')
            ->whereIn('key', self::OBSOLETE_SETTINGS)
            ->delete();

        // تثبيت المزود الافتراضي على Google Meet (المزود الوحيد المتبقي) —
        // قيمة JSON scala (السجل يُعاد كتابته بلا افتراضات على صيغة قديمة).
        DB::table('platform_settings')->updateOrInsert(
            ['key' => 'live_default_provider'],
            ['value' => json_encode('google_meet'), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        // الطاولات المحذوفة لا تُعاد لأن الأنظمة أُزيلت نهائياً من الكود.
    }
};