<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonNote;
use App\Models\Section;
use App\Models\User;
use App\Models\VideoContent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ملاحظات الطالب والعلامات المرجعية داخل الدروس.
 *
 * قواعد صارمة:
 *  - الملكية: الصف ملكٌ لطالب واحد؛ لا يقرأه ولا يعدّله غيره (ولا المعلم) —
 *    لا مشاركة افتراضية إطلاقاً.
 *  - الوصول: لا يُسمح بإنشاء/قراءة ملاحظة لدرس لا يستطيع الطالب فتحه فعلاً
 *    (تسجيل فعّال + فتح الدرس/Zdrip + تقييد المجموعة). نفس سلسلة صلاحيات
 *    الكورس والفيديو — لا نتجاوزها.
 *  - الزمن مرجع فقط: لا نخزّن الفيديو ولا أي جزء من محتواه؛ نخزّن ثواني فقط،
 *    ولقطة مدة لنكشف استبدال الفيديو.
 */
class LessonNoteService
{
    /** حدّ المدة الذي يُعتبر بعده الفيديو «مستبدلاً» — نفس عتبة موضع المشاهدة. */
    private function durationChanged(int $stored, int $current): bool
    {
        if ($stored <= 0 || $current <= 0) {
            return false;
        }

        return abs($stored - $current) > max(60, (int) round($current * 0.1));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** مطابقة نصية متوافقة مع المشغّل (Postgres ilike / غيره like). */
    private function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * يحلّ الدرس ويتحقّق أن الطالب يملك حق الوصول إليه فعلاً.
     * يرمي 404 إن لم يوجد، و403 إن لم يكن مسموحاً له.
     */
    public function resolveAccessibleLesson(User $user, string $lessonId): Lesson
    {
        $lesson = Lesson::withoutTenant()->with(['section'])->find($lessonId);
        abort_if(! $lesson || ! $lesson->section, 404, 'Lesson not found.');

        $course = Course::withoutTenant()->find($lesson->section->course_id);
        abort_if(! $course, 404, 'Course not found.');

        abort_if($user->is_active === false, 403, 'Your account is not active.');

        // تقييد المجموعة: الدرس المشترك لمجموعات محدّدة لا يفتحه إلا أعضاؤها.
        $groupIds = $lesson->groups()->pluck('course_groups.id')->all();
        if ($groupIds) {
            $isMember = $lesson->groups()
                ->whereHas('members', fn ($m) => $m->where('users.id', $user->id))
                ->exists();
            abort_unless($isMember, 403, 'This lesson is not available for your group.');
        }

        $enrollment = Enrollment::withoutTenant()
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->effective()
            ->first();

        abort_if(! $enrollment, 403, 'You are not enrolled in this course.');

        // نفس بوابة الدرس: منشور + فتح تدريجي (Drip) + صلاحية التسجيل.
        abort_unless(
            app(CourseAccessService::class)->lessonUnlocked($enrollment, $lesson),
            403,
            'This lesson is not available to you yet.'
        );

        return $lesson;
    }

    /**
     * ملاحظات الطالب نفسه فقط، مع ترقيم وبحث بسيط.
     *
     * @param  array<string,mixed>  $filters
     * @return array{data:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function list(User $user, array $filters): array
    {
        $query = LessonNote::withoutTenant()->where('user_id', $user->id);

        if (! empty($filters['course_id'])) {
            $query->where('course_id', $filters['course_id']);
        }
        if (! empty($filters['lesson_id'])) {
            $query->where('lesson_id', $filters['lesson_id']);
        }
        if (in_array($filters['type'] ?? null, [LessonNote::TYPE_NOTE, LessonNote::TYPE_BOOKMARK], true)) {
            $query->where('type', $filters['type']);
        }
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(fn ($w) => $w->where('body', $this->likeOperator(), $like));
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 50), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = (clone $query)->count();

        $rows = $query->orderByDesc('updated_at')->forPage($page, $perPage)->get();

        $payload = $this->present($rows);

        return [
            'data' => $payload,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'max_body_length' => LessonNote::MAX_BODY_LENGTH,
            ],
        ];
    }

    /**
     * تنظّم ملاحظات الطالب حسب الكورس ثم الدرس (لصفحة «ملاحظاتي»).
     *
     * @return array<int,array<string,mixed>>
     */
    public function summary(User $user): array
    {
        $counts = LessonNote::withoutTenant()
            ->where('user_id', $user->id)
            ->select('course_id', 'lesson_id', DB::raw('count(*) as notes_count'))
            ->groupBy('course_id', 'lesson_id')
            ->get();

        if ($counts->isEmpty()) {
            return [];
        }

        $courseIds = $counts->pluck('course_id')->unique()->values()->all();
        $lessonIds = $counts->pluck('lesson_id')->unique()->values()->all();

        $courses = Course::withoutTenant()->whereIn('id', $courseIds)->get(['id', 'title'])->keyBy('id');
        $lessons = Lesson::withoutTenant()->whereIn('id', $lessonIds)->get(['id', 'title'])->keyBy('id');

        $grouped = [];
        foreach ($counts as $row) {
            $course = $courses->get($row->course_id);
            $lesson = $lessons->get($row->lesson_id);

            // درس/كورس محذوف حذفاً ناعماً: نتجاهله في الشجرة (يبقى مفلتراً بالسلسلة).
            if (! $course || ! $lesson) {
                continue;
            }

            $grouped[$course->id] ??= [
                'course_id' => $course->id,
                'course_title' => $course->title,
                'notes_count' => 0,
                'lessons' => [],
            ];
            $grouped[$course->id]['lessons'][] = [
                'lesson_id' => $lesson->id,
                'lesson_title' => $lesson->title,
                'notes_count' => (int) $row->notes_count,
            ];
            $grouped[$course->id]['notes_count'] += (int) $row->notes_count;
        }

        return array_values($grouped);
    }

    /**
     * ينشئ ملاحظة/علامة مرجعية للطالب على درس يملك الوصول إليه.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(User $user, Lesson $lesson, array $data): LessonNote
    {
        $type = ($data['type'] ?? LessonNote::TYPE_NOTE) === LessonNote::TYPE_BOOKMARK
            ? LessonNote::TYPE_BOOKMARK
            : LessonNote::TYPE_NOTE;

        $body = $this->normalizeBody($data['body'] ?? null);
        $position = $this->normalizePosition($data['position_seconds'] ?? null);
        $duration = $this->normalizeDuration($data['duration_seconds'] ?? null);
        $videoId = $this->resolveVideoForLesson($lesson, $data['video_id'] ?? null);

        if ($type === LessonNote::TYPE_BOOKMARK && ($position === null || $videoId === null)) {
            abort(422, 'A bookmark needs a timestamp on a lesson video.');
        }
        if ($type === LessonNote::TYPE_NOTE && $body === null && $position === null) {
            abort(422, 'A note needs some text or a timestamp.');
        }
        // لا معنى لوقت بلا فيديو (درس نصي) — نُسقطه بدل تخزين مرجع معلّق.
        if ($position !== null && $videoId === null) {
            $position = null;
            $duration = null;
        }

        return LessonNote::create([
            'tenant_id' => $lesson->tenant_id,
            'user_id' => $user->id,
            'course_id' => $lesson->section->course_id,
            'lesson_id' => $lesson->id,
            'video_id' => $videoId,
            'type' => $type,
            'body' => $body,
            'position_seconds' => $position,
            'duration_seconds' => $videoId !== null ? $duration : null,
        ]);
    }

    /**
     * يعدّل ملاحظة يملكها الطالب (الملكية مضمونة من استعلام المالك في المتحكّم).
     *
     * @param  array<string,mixed>  $data
     */
    public function update(User $user, LessonNote $note, array $data): LessonNote
    {
        abort_unless($note->user_id === $user->id, 403, 'Not your note.');

        if (array_key_exists('body', $data)) {
            $note->body = $this->normalizeBody($data['body']);
        }
        if (array_key_exists('position_seconds', $data)) {
            $position = $this->normalizePosition($data['position_seconds']);
            // لا نُبقي زمنًا بلا فيديو مرجعي.
            if ($position !== null && $note->video_id === null) {
                $position = null;
            }
            $note->position_seconds = $position;
        }

        if ($note->type === LessonNote::TYPE_NOTE && $note->body === null && $note->position_seconds === null) {
            abort(422, 'A note needs some text or a timestamp.');
        }

        $note->save();

        return $note;
    }

    public function delete(User $user, LessonNote $note): void
    {
        abort_unless($note->user_id === $user->id, 403, 'Not your note.');

        $note->delete();
    }

    /**
     * يبني حمولة العرض لعرض الملاحظات مع عناوين الدرس/الكورس وحالة المرجع الزمني
     * (هل الفيديو ما زال موجوداً وبنفس المدة؟) — قراءة مجمّعة بلا N+1.
     *
     * @param  Collection<int,LessonNote>  $rows
     * @return array<int,array<string,mixed>>
     */
    public function present(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $lessons = Lesson::withoutTenant()->whereIn('id', $rows->pluck('lesson_id')->unique()->all())
            ->get(['id', 'title', 'section_id'])->keyBy('id');
        $sections = Section::withoutTenant()->whereIn('id', $lessons->pluck('section_id')->unique()->all())
            ->get(['id', 'course_id'])->keyBy('id');
        $courses = Course::withoutTenant()->whereIn('id', $sections->pluck('course_id')->unique()->all())
            ->get(['id', 'title'])->keyBy('id');

        $videoIds = $rows->pluck('video_id')->filter()->unique()->values()->all();
        $videos = $videoIds
            ? VideoContent::withoutTenant()->whereIn('id', $videoIds)->get(['id', 'duration', 'status'])->keyBy('id')
            : collect();

        return $rows->map(function (LessonNote $note) use ($lessons, $sections, $courses, $videos) {
            $lesson = $lessons->get($note->lesson_id);
            $course = $lesson ? $courses->get($sections->get($lesson->section_id)?->course_id) : null;
            $video = $note->video_id ? $videos->get($note->video_id) : null;

            [$positionValid, $positionNote] = $this->positionState($note, $video);

            return [
                'id' => $note->id,
                'type' => $note->type,
                'body' => $note->body,
                'position_seconds' => $note->position_seconds,
                'duration_seconds' => $note->duration_seconds,
                'lesson_id' => $note->lesson_id,
                'lesson_title' => $lesson?->title,
                'course_id' => $lesson?->section_id ? $sections->get($lesson->section_id)?->course_id : $note->course_id,
                'course_title' => $course?->title,
                'video_id' => $note->video_id,
                'has_position' => $note->position_seconds !== null,
                'position_valid' => $positionValid,
                'position_note' => $positionNote,
                'created_at' => optional($note->created_at)->toIso8601String(),
                'updated_at' => optional($note->updated_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * هل المرجع الزمني ما زال صالحاً؟ (الفيديو موجود ولم تُستبدل مدته)
     *
     * @return array{0:bool,1:?string}
     */
    private function positionState(LessonNote $note, ?VideoContent $video): array
    {
        if ($note->position_seconds === null) {
            return [false, null];
        }

        if ($note->video_id === null || $video === null) {
            return [false, 'video_missing'];
        }

        if ($video->status === 'error') {
            return [false, 'video_unavailable'];
        }

        if ($this->durationChanged((int) $note->duration_seconds, (int) $video->duration)) {
            return [false, 'video_changed'];
        }

        return [true, null];
    }

    private function normalizeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        // تنظيف محارف التحكّم (حماية من إساءة الاستخدام) مع احترام الأسطر الجديدة.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $body) ?? '';
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, LessonNote::MAX_BODY_LENGTH);
    }

    private function normalizePosition(mixed $position): ?int
    {
        if ($position === null || $position === '') {
            return null;
        }

        if (! is_numeric($position)) {
            return null;
        }

        return max(0, (int) $position);
    }

    private function normalizeDuration(mixed $duration): ?int
    {
        if ($duration === null || $duration === '' || ! is_numeric($duration)) {
            return null;
        }

        return max(0, (int) $duration);
    }

    /**
     * يتأكّد أن الفيديو (إن أُرسل) يخصّ هذا الدرس فعلاً، ثم يرجعه أو null.
     */
    private function resolveVideoForLesson(Lesson $lesson, mixed $videoId): ?string
    {
        if (! $videoId || ! is_string($videoId)) {
            return null;
        }

        $belongs = VideoContent::withoutTenant()
            ->where('id', $videoId)
            ->where(function ($w) use ($lesson) {
                $w->where('lesson_id', $lesson->id)
                    ->orWhereHas('lessonContent', fn ($c) => $c->where('lesson_id', $lesson->id));
            })
            ->exists();

        abort_unless($belongs, 422, 'This video does not belong to the lesson.');

        return $videoId;
    }
}
