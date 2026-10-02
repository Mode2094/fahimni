<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\SubmitExamRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Services\StudentGamificationService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class ExamAttemptController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * Metadata keys that encode the answer key. Never serialized to students
     * in their raw form (writing keywords, fill-blank answers, canonical
     * ordering).
     */
    private const ANSWER_KEY_METADATA_KEYS = ['keywords', 'answers'];

    /**
     * Build the student-safe metadata payload for a question:
     *  - image_url is always visible
     *  - drag_drop/matching pairs are rendered with both columns shuffled so
     *    the correct mapping is not aligned (the pairing is inherent to the
     *    interaction type)
     *  - ordering items are sent shuffled; grading compares against the
     *    canonical order stored server-side
     *  - fill_blank sends only the number of blanks
     */
    private function studentSafeMetadata(array $metadata, string $questionType): array
    {
        $safe = collect($metadata)->except(self::ANSWER_KEY_METADATA_KEYS)->all();

        if ($questionType === 'drag_drop' || $questionType === 'matching') {
            $pairs = $metadata['pairs'] ?? [];

            if (is_array($pairs) && $pairs !== []) {
                $left = collect($pairs)->pluck('left')->shuffle()->values()->all();
                $right = collect($pairs)->pluck('right')->shuffle()->values()->all();

                $safe['pairs'] = array_map(fn ($l, $i) => [
                    'left' => $l,
                    'right' => $right[$i] ?? null,
                ], $left, array_keys($left));
            } else {
                $safe['pairs'] = [];
            }
        }

        if ($questionType === 'ordering') {
            $items = $metadata['items'] ?? [];

            if (is_array($items) && $items !== []) {
                $safe['items'] = collect($items)->shuffle()->values()->all();
            } else {
                $safe['items'] = [];
            }
        }

        if ($questionType === 'fill_blank') {
            $safe['blanks_count'] = is_array($metadata['answers'] ?? null)
                ? count($metadata['answers'])
                : 0;
        }

        return $safe;
    }

    public function index(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('view', $exam);

        $attempts = ExamAttempt::where('exam_id', $examId)
            ->with('student.roles')
            ->when(
                ! $request->user()->is_super_admin,
                fn ($q) => $q->where('user_id', $request->user()->id)
            )
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'attempts' => ExamAttemptResource::collection($attempts->items()),
            'pagination' => [
                'current_page' => $attempts->currentPage(),
                'last_page' => $attempts->lastPage(),
                'per_page' => $attempts->perPage(),
                'total' => $attempts->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $attempt = ExamAttempt::with(['exam', 'student', 'answers.question.options'])->findOrFail($id);

        $user = request()->user();
        $isStaff = false;

        if (! $user->is_super_admin && $attempt->user_id !== $user->id) {
            $isStaff = $this->isTenantStaff(request(), $attempt->tenant_id);

            if (! $isStaff) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        // Students only see the answer key when the exam allows it and the
        // attempt is over (result must also be shown per exam flags).
        $viewerIsStudent = ! $user->is_super_admin && $attempt->user_id === $user->id;
        $attempt->loadMissing(['exam']);

        if ($viewerIsStudent) {
            $exam = $attempt->exam;
            $canSeeKey = $attempt->status === 'submitted'
                && $exam->show_correct_answers
                && ($exam->show_result_immediately || $attempt->percentage !== null);

            if (! $canSeeKey) {
                $attempt->setRelation('answers', $attempt->answers->map(function (ExamAnswer $answer) {
                    $answer->makeHidden(['is_correct']);
                    $answer->unsetRelation('question');

                    return $answer;
                }));
            }
        }

        return response()->json([
            'attempt' => new ExamAttemptResource($attempt),
        ]);
    }

    public function start(string $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('attempt', $exam);

        if ($exam->starts_at && now()->lt($exam->starts_at)) {
            return response()->json(['message' => 'This exam has not started yet.'], 422);
        }

        if ($exam->ends_at && now()->gt($exam->ends_at)) {
            return response()->json(['message' => 'This exam has ended.'], 422);
        }

        $user = request()->user();

        $maxAttempts = $exam->max_attempts > 0 ? $exam->max_attempts : PHP_INT_MAX;

        $attempt = DB::transaction(function () use ($exam, $user, $maxAttempts) {
            Exam::whereKey($exam->id)->lockForUpdate()->first();

            // An in-progress attempt that has outlived its duration is a
            // dead end: auto-submit it (grade whatever exists, zero answers
            // if nothing was saved) so it can never block a retake.
            ExamAttempt::where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->where('status', 'in_progress')
                ->where('started_at', '<', now()->subMinutes(max(1, $exam->duration_minutes)))
                ->each(function (ExamAttempt $stale) {
                    $this->autoSubmit($stale);
                });

            $attemptNumber = ExamAttempt::where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->max('attempt_number') + 1;

            if ($attemptNumber > $maxAttempts) {
                return null;
            }

            return ExamAttempt::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $exam->tenant_id,
                'exam_id' => $exam->id,
                'user_id' => $user->id,
                'attempt_number' => $attemptNumber,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
        });

        if (! $attempt) {
            return response()->json(['message' => 'Maximum attempts reached.'], 403);
        }

        $examQuestions = ExamQuestion::where('exam_id', $exam->id)
            ->with('question.options')
            ->get()
            // Skip soft-deleted questions so they can never break a run.
            ->reject(fn ($eq) => ! $eq->question)
            ->values();

        if ($exam->random_questions_count && $exam->random_questions_count > 0 && $exam->random_questions_count < $examQuestions->count()) {
            $examQuestions = $examQuestions->shuffle()->take($exam->random_questions_count);
            $attempt->update([
                'metadata' => array_merge($attempt->metadata ?? [], [
                    'random_draw' => $examQuestions->pluck('question_id')->values()->toArray(),
                ]),
            ]);
        }

        if ($exam->shuffle_questions) {
            $examQuestions = $examQuestions->shuffle();
        }

        $questionsData = $examQuestions->map(function ($eq) use ($exam) {
            $q = $eq->question;
            $metadata = $q->metadata ?? [];

            $data = [
                'exam_question_id' => $eq->id,
                'question_id' => $q->id,
                'question_type' => $q->question_type,
                'question_text' => $q->question_text,
                'points' => $eq->points_override ?? $q->points,
            ];

            if ($exam->shuffle_options && $q->options) {
                $q->options = $q->options->shuffle();
            }

            $data['options'] = $q->options->map(fn ($o) => [
                'id' => $o->id,
                'option_text' => $o->option_text,
                'sort_order' => $o->sort_order,
            ]);

            // Never leak the answer key: grading metadata is replaced by a
            // student-safe presentation (shuffled items, blank counts).
            $data['metadata'] = $this->studentSafeMetadata($metadata, $q->question_type);
            $data['image_url'] = $metadata['image_url'] ?? null;

            return $data;
        });

        $attemptsUsed = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $user->id)
            ->where('status', 'submitted')
            ->count();
        $attemptsLeft = $exam->max_attempts > 0 ? max(0, $exam->max_attempts - $attemptsUsed) : -1;

        return response()->json([
            'attempt' => new ExamAttemptResource($attempt),
            'questions' => $questionsData,
            'deadline' => $exam->duration_minutes > 0 ? now()->addMinutes($exam->duration_minutes)->toIso8601String() : null,
            'attempts_left' => $attemptsLeft,
            'exam' => [
                'title' => $exam->title,
                'duration_minutes' => $exam->duration_minutes,
                'shuffle_questions' => $exam->shuffle_questions,
                'anti_cheat_enabled' => (bool) $exam->anti_cheat_enabled,
            ],
        ]);
    }

    public function submit(SubmitExamRequest $request, string $examId, string $attemptId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);
        $attempt = ExamAttempt::where('exam_id', $exam->id)
            ->where('id', $attemptId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        if ($exam->starts_at && now()->lt($exam->starts_at)) {
            return response()->json(['message' => 'This exam has not started yet.'], 422);
        }

        if ($exam->ends_at && now()->gt($exam->ends_at)) {
            return response()->json(['message' => 'This exam has ended.'], 422);
        }

        $autoSubmitted = false;
        if ($exam->duration_minutes > 0 && $attempt->started_at && now()->gt($attempt->started_at->copy()->addMinutes($exam->duration_minutes))) {
            // Time is up: grade whatever the student answered instead of
            // leaving a dead attempt that burns an attempt slot.
            $autoSubmitted = true;
        }

        // Total points always derive from the full server-side question set,
        // never from the submitted subset — otherwise answering one easy
        // question would yield 100%.
        $examQuestions = ExamQuestion::where('exam_id', $exam->id)
            ->with('question.options')
            ->get()
            ->reject(fn ($eq) => ! $eq->question)
            ->keyBy('id');

        $totalPoints = (float) $examQuestions->sum(
            fn ($eq) => $eq->points_override ?? $eq->question->points
        );

        $earnedPoints = 0.0;
        $answersData = [];
        $seenExamQuestionIds = [];
        $needsReview = false;

        foreach ($request->answers as $ans) {
            $examQuestion = $examQuestions[$ans['exam_question_id']] ?? null;
            if (! $examQuestion) {
                abort(422, 'Invalid question for this exam.');
            }

            if (isset($seenExamQuestionIds[$examQuestion->id])) {
                abort(422, 'Duplicate answer for the same question.');
            }
            $seenExamQuestionIds[$examQuestion->id] = true;

            $question = $examQuestion->question;
            $pointsMax = (float) ($examQuestion->points_override ?? $question->points);

            // Writing and file answers cannot be auto-graded truthfully: the
            // attempt is flagged for manual review and only the teacher's
            // score counts.
            if (in_array($question->question_type, ['writing', 'file_upload'], true)) {
                $needsReview = true;
            }

            $isCorrect = $this->checkAnswer($question, $ans['given_answer']);
            $pointsEarned = $isCorrect ? $pointsMax : 0;
            $earnedPoints += $pointsEarned;

            $answersData[] = [
                'id' => UuidV7::generate(),
                'tenant_id' => $exam->tenant_id,
                'exam_attempt_id' => $attempt->id,
                'exam_question_id' => $examQuestion->id,
                'question_id' => $question->id,
                'given_answer' => json_encode($ans['given_answer']),
                'is_correct' => $isCorrect,
                'points_earned' => $pointsEarned,
                'points_max' => $pointsMax,
                'time_spent_seconds' => (int) ($ans['time_spent_seconds'] ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ExamAnswer::insert($answersData);

        $percentage = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 2) : 0;

        // Attempts containing essay/file answers are never auto-passed: the
        // auto-graded score is provisional until the teacher reviews.
        $passed = ! $needsReview && $percentage >= (float) $exam->pass_mark_percent;

        $metadata = $attempt->metadata ?? [];
        // Anti-cheat violations are client-declared; store them as advisory
        // metadata only — never as a policy input.
        $metadata['anti_cheat_violations'] = (int) $request->input('anti_cheat_violations', 0);
        if ($autoSubmitted) {
            $metadata['auto_submitted'] = true;
        }

        $attempt->update([
            'status' => 'submitted',
            'score' => $earnedPoints,
            'total_points' => $totalPoints,
            'percentage' => $percentage,
            'passed' => $passed,
            'needs_review' => $needsReview,
            'submitted_at' => now(),
            'time_spent_seconds' => (int) $request->input('time_spent_seconds', 0),
            'metadata' => $metadata,
        ]);

        $attemptsUsed = ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'submitted')
            ->count();
        $attemptsLeft = $exam->max_attempts > 0 ? max(0, $exam->max_attempts - $attemptsUsed) : -1;

        if ($passed) {
            app(StudentGamificationService::class)->award(
                $request->user(),
                $exam->tenant_id,
                StudentGamificationService::POINTS_EXAM_PASS,
                'exam_passed',
                'exam_attempt',
                $attempt->id,
                'اجتاز الاختبار: '.($exam->title ?? '')
            );
        }

        return response()->json([
            'attempt' => new ExamAttemptResource($attempt->fresh(['answers'])),
            'result' => [
                'score' => $earnedPoints,
                'total' => $totalPoints,
                'percentage' => $percentage,
                'passed' => $passed,
                'needs_review' => $needsReview,
                'pass_mark' => $exam->pass_mark_percent,
                'attempts_left' => $attemptsLeft,
            ],
        ]);
    }

    /**
     * Teacher-only manual grading for attempts containing essay/file
     * answers. Overrides the auto-graded score per answer and recomputes
     * the attempt result.
     */
    public function grade(Request $request, string $examId, string $attemptId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);
        $this->authorize('viewQuestions', $exam);

        $attempt = ExamAttempt::with(['answers'])
            ->where('exam_id', $exam->id)
            ->where('id', $attemptId)
            ->where('status', 'submitted')
            ->firstOrFail();

        $validated = $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.exam_answer_id' => 'required|string',
            'answers.*.points_earned' => 'required|numeric|min:0',
        ]);

        $answersById = $attempt->answers->keyBy('id');

        // Compute the new total BEFORE mutating rows: untouched answers keep
        // their stored auto-graded scores.
        $manual = [];
        foreach ($validated['answers'] as $grade) {
            $answer = $answersById[$grade['exam_answer_id']] ?? null;

            if (! $answer) {
                return response()->json(['message' => 'Answer does not belong to this attempt.'], 422);
            }

            $max = (float) $answer->points_max;

            if ((float) $grade['points_earned'] > $max) {
                return response()->json([
                    'message' => "Score for answer {$answer->id} exceeds its max ({$max}).",
                ], 422);
            }

            $manual[$answer->id] = (float) $grade['points_earned'];
        }

        $newScore = 0.0;
        foreach ($attempt->answers as $answer) {
            $newScore += $manual[$answer->id] ?? (float) $answer->points_earned;
        }

        foreach ($manual as $answerId => $points) {
            $answer = $answersById[$answerId];
            $answer->update([
                'points_earned' => $points,
                'is_correct' => $points >= (float) $answer->points_max,
                'metadata' => array_merge($answer->metadata ?? [], ['manually_graded' => true]),
            ]);
        }

        $percentage = $attempt->total_points > 0
            ? round(($newScore / (float) $attempt->total_points) * 100, 2)
            : 0;
        $passed = $percentage >= (float) $exam->pass_mark_percent;

        $attempt->update([
            'score' => $newScore,
            'percentage' => $percentage,
            'passed' => $passed,
            'needs_review' => false,
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        if ($passed) {
            app(StudentGamificationService::class)->award(
                $attempt->student,
                $exam->tenant_id,
                StudentGamificationService::POINTS_EXAM_PASS,
                'exam_passed',
                'exam_attempt',
                $attempt->id,
                'اجتاز الاختبار: '.($exam->title ?? '')
            );
        }

        app(\App\Services\NotificationService::class)->notify(
            notifiable: $attempt->student,
            type: 'exam.graded',
            title: 'Your exam has been reviewed',
            body: 'Your teacher reviewed your exam: '.($exam->title ?? '').'.',
            tenantId: $exam->tenant_id,
            data: ['exam_id' => $exam->id, 'attempt_id' => $attempt->id, 'percentage' => $percentage],
        );

        return response()->json([
            'attempt' => new ExamAttemptResource($attempt->fresh(['answers'])),
        ]);
    }

    /**
     * Finalize an expired in-progress attempt with a zero answer set so it
     * can never block new attempts or leak as an open record.
     */
    private function autoSubmit(ExamAttempt $attempt): void
    {
        $exam = $attempt->exam()->first();

        $examQuestions = ExamQuestion::where('exam_id', $attempt->exam_id)
            ->with('question')
            ->get()
            ->reject(fn ($eq) => ! $eq->question);

        $totalPoints = (float) $examQuestions->sum(fn ($eq) => $eq->points_override ?? $eq->question->points);
        $percentage = $totalPoints > 0 ? 0 : 0;

        $attempt->update([
            'status' => 'submitted',
            'score' => 0,
            'total_points' => $totalPoints,
            'percentage' => $percentage,
            'passed' => false,
            'submitted_at' => now(),
            'metadata' => array_merge($attempt->metadata ?? [], [
                'auto_submitted' => true,
                'reason' => 'expired',
            ]),
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    private function checkAnswer(Question $question, mixed $givenAnswer): bool
    {
        if ($question->question_type === 'multiple_choice') {
            $correctOptions = $question->options->where('is_correct', true)->pluck('id')->toArray();

            if (is_array($givenAnswer)) {
                sort($givenAnswer);
                sort($correctOptions);

                return $givenAnswer === $correctOptions;
            }

            return in_array($givenAnswer, $correctOptions);
        }

        if ($question->question_type === 'true_false') {
            $correctOption = $question->options->where('is_correct', true)->first();

            if (is_array($givenAnswer)) {
                return in_array($correctOption?->id, $givenAnswer);
            }

            // Students answer with the literal 'true'/'false' (the option
            // list itself is hidden), or with the option id/text from
            // teacher-facing tools.
            if (is_string($givenAnswer)) {
                $normalized = strtolower(trim($givenAnswer));
                $optionText = strtolower(trim((string) ($correctOption?->option_text ?? '')));

                if ($normalized === 'true' || $normalized === '1') {
                    return in_array($optionText, ['true', 'صحيح', 'صح', '1'], true);
                }
                if ($normalized === 'false' || $normalized === '0') {
                    return in_array($optionText, ['false', 'خطأ', '0'], true);
                }
            }

            return $givenAnswer === $correctOption?->id || $givenAnswer === $correctOption?->option_text;
        }

        $metadata = $question->metadata ?? [];

        if ($question->question_type === 'drag_drop' || $question->question_type === 'matching') {
            $pairs = $metadata['pairs'] ?? [];
            $given = is_array($givenAnswer) ? $givenAnswer : (json_decode((string) $givenAnswer, true) ?? []);

            $correct = [];
            foreach ($pairs as $pair) {
                $correct[trim((string) ($pair['left'] ?? ''))] = trim((string) ($pair['right'] ?? ''));
            }

            $givenMap = [];
            foreach ($given as $g) {
                if (is_array($g) && isset($g['left'], $g['right'])) {
                    $givenMap[trim((string) $g['left'])] = trim((string) $g['right']);
                }
            }

            if (count($givenMap) !== count($correct)) {
                return false;
            }

            foreach ($correct as $left => $right) {
                if (($givenMap[$left] ?? null) !== $right) {
                    return false;
                }
            }

            return true;
        }

        if ($question->question_type === 'ordering') {
            $correctOrder = array_map(fn ($i) => trim((string) $i), $metadata['items'] ?? []);
            $given = is_array($givenAnswer) ? $givenAnswer : (json_decode((string) $givenAnswer, true) ?? []);

            if (count($given) !== count($correctOrder)) {
                return false;
            }

            foreach ($correctOrder as $i => $item) {
                if (trim((string) ($given[$i] ?? '')) !== $item) {
                    return false;
                }
            }

            return true;
        }

        if ($question->question_type === 'fill_blank') {
            $correctAnswers = array_map(fn ($a) => strtolower(trim((string) $a)), $metadata['answers'] ?? []);
            $given = is_array($givenAnswer) ? $givenAnswer : (json_decode((string) $givenAnswer, true) ?? []);

            if (count($given) !== count($correctAnswers)) {
                return false;
            }

            foreach ($correctAnswers as $i => $answer) {
                if (strtolower(trim((string) ($given[$i] ?? ''))) !== $answer) {
                    return false;
                }
            }

            return true;
        }

        if ($question->question_type === 'file_upload') {
            return trim((string) $givenAnswer) !== '';
        }

        if ($question->question_type === 'writing') {
            $keywords = array_map(fn ($k) => strtolower(trim((string) $k)), $metadata['keywords'] ?? []);
            $text = strtolower(trim((string) $givenAnswer));

            if (empty($keywords) || $text === '') {
                return false;
            }

            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
