<?php

namespace App\Live;

use App\Models\LiveSession;
use App\Models\User;

/**
 * واجهة مزودي البث المباشر — كل منطق البث يمر عبر هذه الواجهة،
 * ولا يحتوي LiveSessionController على أي منطق خاص بمزود بعينه.
 *
 * المزود الوحيد المطبق: google_meet (رابط خارجي يقدمه المعلم).
 */
interface LiveProviderInterface
{
    /** معرف المزود (google_meet). */
    public function name(): string;

    /** اسم عرض المزود للوحات. */
    public function label(): string;

    /** هل المزود مفعّل من إعدادات المنصة؟ */
    public function isEnabled(): bool;

    /** هل الاعتمادات/البنية مكتملة فعلاً (لا يعرض مزوداً ناقصاً كجاهز)؟ */
    public function isConfigured(): bool;

    /** فحص صحة مع التخزين المؤقت — للمسار الإداري فقط. */
    public function health(): array;

    /** نوع المشغل للطلاب: meeting. */
    public function playerType(): string;

    /** تحضير الموارد لدى المزود عند إنشاء الجلسة. */
    public function createSession(LiveSession $session): void;

    /** بدء البث الفعلي (فحص الحصص قبل ذلك خارجياً). */
    public function startSession(LiveSession $session): void;

    /** إيقاف البث. */
    public function stopSession(LiveSession $session): void;

    /** إلغاء جلسة مجدولة (لا بث بعد). */
    public function cancelSession(LiveSession $session): void;

    /** حذف صريح. */
    public function destroySession(LiveSession $session): void;

    /**
     * تذكرة دخول (الرابط + الدور) — المضيف Host والطالب Participant.
     *
     * @return array<string, mixed>
     */
    public function joinTicket(LiveSession $session, User $user, string $role): array;

    /**
     * بيانات المشاهدة للطالب: player_type + meeting_url.
     *
     * @return array<string, mixed>
     */
    public function watchPayload(LiveSession $session): array;
}