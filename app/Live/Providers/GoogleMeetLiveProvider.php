<?php

namespace App\Live\Providers;

use App\Live\LiveProviderInterface;
use App\Models\LiveSession;
use App\Models\PlatformSetting;
use App\Models\User;
use RuntimeException;

/**
 * مزود Google Meet — رابط حصة خارجي يقدمه المعلم بنفسه.
 *
 * - لا Google API ولا حساب مشترك: المعلم يلصق رابط meet.google.com الخاص به
 *   عند الإنشاء، ويُخزن في metadata الجلسة (meeting_url = start_url).
 * - Meet لا يفصل host/join URL — الرابط نفسه للجميع، ويُخفى عن غير المسجلين.
 * - جاهز (isConfigured) دائماً منذ الإطلاق المبكر — لا اعتمادات مطلوبة.
 * - إلغاء/حذف الجلسة لا يمس أي حدث خارجي (لا Calendar API).
 */
class GoogleMeetLiveProvider implements LiveProviderInterface
{
    public function name(): string
    {
        return 'google_meet';
    }

    public function label(): string
    {
        return 'Google Meet';
    }

    public function isEnabled(): bool
    {
        return PlatformSetting::bool('live_google_meet_enabled', true);
    }

    public function isConfigured(): bool
    {
        // رابط المعلم لا يحتاج اعتمادات — جاهز دائماً.
        return true;
    }

    public function health(): array
    {
        return ['ok' => true, 'message' => 'Ready — the teacher provides the meet.google.com link.'];
    }

    public function playerType(): string
    {
        return 'meeting';
    }

    public function createSession(LiveSession $session): void
    {
        // لا Google API ولا حساب مشترك — الرابط يُخزن في metadata من التحكم
        // (لا يُستدعى في مسار الإنشاء؛ احتراز لو حُفظ المسار القديم).
        $metadata = $session->metadata ?? [];

        if (empty($metadata['meeting_url'])) {
            throw new RuntimeException('Google Meet sessions require a teacher-provided meeting_url.');
        }
    }

    public function startSession(LiveSession $session): void
    {
        // الحدث جاهز عند الإنشاء.
    }

    public function stopSession(LiveSession $session): void
    {
        // إيقاف الجلسة لا يحذف الحدث.
    }

    public function cancelSession(LiveSession $session): void
    {
        // لا حدث في Google يُحذف — الرابط خارجي يقدمه المعلم.
    }

    public function destroySession(LiveSession $session): void
    {
        // لا حدث في Google يُحذف.
    }

    public function joinTicket(LiveSession $session, User $user, string $role): array
    {
        $metadata = $session->metadata ?? [];

        return [
            'role' => $role,
            'display_name' => $user->name,
            'room' => $session->room_name,
            'jwt' => null,
            'jwt_enabled' => false,
            'player_type' => 'meeting',
            'meeting_url' => $metadata['meeting_url'] ?? null,
            // Meet لا يملك start_url منفصل — المضيف يفتح الرابط نفسه.
            'start_url' => $role === 'host' ? ($metadata['meeting_url'] ?? null) : null,
        ];
    }

    public function watchPayload(LiveSession $session): array
    {
        $metadata = $session->metadata ?? [];

        return [
            'player_type' => 'meeting',
            'stream_url' => null,
            'meeting_url' => $metadata['meeting_url'] ?? null,
        ];
    }
}
