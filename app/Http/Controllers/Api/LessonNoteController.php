<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonNote;
use App\Services\LessonNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ملاحظات الطالب وعلاماتهم المرجعية — خاصة بصاحبها حصراً.
 *
 * الوصول مقصور على تسجيل الدخول؛ وكل عملية تتحقّق أن الطالب هو مالك الصف وأنه
 * يملك حق الوصول الفعلي للدرس (تسجيل فعّال + فتح الدرس + تقييد المجموعة)، فلا
 * يمكن قراءة ملاحظات طالب آخر ولا الكتابة في درس غير مسموح.
 */
class LessonNoteController extends Controller
{
    public function __construct(private readonly LessonNoteService $notes) {}

    /** قائمة ملاحظات الطالب (مع ترقيم وتصفية وبحث بسيط). */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'course_id' => ['nullable', 'string', 'max:64'],
            'lesson_id' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'in:note,bookmark'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->notes->list($request->user(), $filters));
    }

    /** شجرة الكورس ← الدرس لملاحظات الطالب (لتنظيم صفحة «ملاحظاتي»). */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['courses' => $this->notes->summary($request->user())]);
    }

    /** حفظ ملاحظة جديدة أو علامة مرجعية («احفظ هذه اللحظة»). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lesson_id' => ['required', 'string', 'max:64'],
            'type' => ['nullable', 'in:note,bookmark'],
            'body' => ['nullable', 'string', 'max:'.LessonNote::MAX_BODY_LENGTH],
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'video_id' => ['nullable', 'string', 'max:64'],
        ]);

        $lesson = $this->notes->resolveAccessibleLesson($request->user(), $data['lesson_id']);
        $note = $this->notes->create($request->user(), $lesson, $data);

        return response()->json([
            'note' => $this->notes->present(collect([$note]))[0],
        ], 201);
    }

    /** تعديل ملاحظة يملكها الطالب. */
    public function update(Request $request, string $note): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.LessonNote::MAX_BODY_LENGTH],
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $model = $this->ownNote($request, $note);
        $this->notes->update($request->user(), $model, $data);

        return response()->json(['note' => $this->notes->present(collect([$model]))[0]]);
    }

    /** حذف ملاحظة يملكها الطالب. */
    public function destroy(Request $request, string $note): JsonResponse
    {
        $model = $this->ownNote($request, $note);
        $this->notes->delete($request->user(), $model);

        return response()->json(['ok' => true]);
    }

    /**
     * يجلب الملاحظة بشرط أن يملكها الطالب؛ ملاحظة غيره لا تُكشف أصلاً (404).
     */
    private function ownNote(Request $request, string $noteId): LessonNote
    {
        return LessonNote::withoutTenant()
            ->where('user_id', $request->user()->id)
            ->findOrFail($noteId);
    }
}
