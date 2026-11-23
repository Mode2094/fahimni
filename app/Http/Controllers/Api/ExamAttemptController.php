<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\SubmitExamRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Services\ExamPaper;
use App\Services\StudentGamificationService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class ExamAttemptController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(private readonly ExamPaper $paper) {}

    public function index(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('view', $exam);

        $user = $request->user();

        // Staff (teacher/owner/admin of the exam's tenant) review every
        // student's attempt; a student only ever sees their own. Tenant
        // isolation is enforced by `authorize('view')` above plus the global
        // tenant scope, so a foreign-tenant teacher can never reach this list.
        $isStaff = $user->is_super_admin
            || $this->isTenantStaff($request, (string) $exam->tenant_id);

        $attempts = ExamAttempt::where('exam_id', $examId)
            ->when(! $isStaff, fn ($q) => $q->where('user_id', $user->id))
            ->with([
                'student.roles',
                'exam.course',
                'exam.section',
                // Answers drive the review list (student answers, HTML
                // previews, manual-review questions and skill performance).
                // ExamAnswerResource hides the key and question payload from
                // non-staff, so a student cannot read another's answers.
                'answers.question.options',
            ])
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

    /**
     * Build the student-facing question payload straight from the LIVE bank.
     *
     * Used by the teacher preview (which should always reflect the current
     * bank) so the preview is exactly the student experience: identical
     * sanitised metadata (the answer key never leaks) and identical
     * ordering/shuffling. Real attempts serve their frozen paper instead.
     */
    private function livePayload(\Illuminate\Support\Collection $examQuestions, Exam $exam): \Illuminate\Support\Collection
    {
        return $this->paper->payload(
            ['version' => ExamPaper::VERSION, 'questions' => $examQuestions->map(fn ($eq) => [
                'exam_question_id' => $eq->id,
                'question_id' => $eq->question->id,
                'question_type' => $eq->question->question_type,
                'question_text' => $eq->question->question_text,
                'points' => (float) ($eq->points_override ?? $eq->question->points),
                'options' => collect($eq->question->options ?? [])->map(fn ($o) => [
                    'id' => $o->id,
                    'option_text' => $o->option_text,
                    'sort_order' => (int) $o->sort_order,
                    'is_correct' => (bool) $o->is_correct,
                ])->values()->all(),
                'metadata' => $eq->question->metadata ?? [],
                'image_url' => data_get($eq->question->metadata, 'image_url'),
            ])->values()->all()],
            $exam
        );
    }

    /**
     * Student payload from a frozen paper. Sandboxed questions whose display
     * payload was dropped to keep the paper small are re-served from the live
     * bank (their grading key stays frozen).
     */
    private function paperPayload(array $paper, Exam $exam): \Illuminate\Support\Collection
    {
        $liveSandbox = $this->liveSandboxQuestions($paper);

        return $this->paper->payload($paper, $exam, $liveSandbox);
    }

    /**
     * @return array<string, Question>
     */
    private function liveSandboxQuestions(array $paper): array
    {
        $ids = collect($paper['questions'] ?? [])
            ->filter(fn ($e) => ! empty($e['display_live']))
            ->pluck('question_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Question::whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * Teacher-only "preview as a student". Returns the exact student payload
     * (including unpublished exams and inside the tenant) WITHOUT creating an
     * ExamAttempt: no real student is recorded, no attempt slot is consumed, no
     * score is stored, and nothing is published. Gated by `viewQuestions`, so a
     * student can never reach it.
     */
    public function preview(string $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('viewQuestions', $exam);

        $examQuestions = ExamQuestion::where('exam_id', $exam->id)
            ->with('question.options')
            ->orderBy('sort_order')
            ->get()
            ->reject(fn ($eq) => ! $eq->question)
            ->values();

        return response()->json([
            'preview' => true,
            'attempt' => null,
            'questions' => $this->livePayload($examQuestions, $exam),
            'deadline' => $exam->duration_minutes > 0 ? now()->addMinutes($exam->duration_minutes)->toIso8601String() : null,
            'attempts_left' => null,
            'exam' => [
                'title' => $exam->title,
                'duration_minutes' => $exam->duration_minutes,
                'pass_mark_percent' => $exam->pass_mark_percent,
                'shuffle_questions' => $exam->shuffle_questions,
                // Anti-cheat is never armed in preview: it must not auto-submit
                // the teacher for inspecting their own exam.
                'anti_cheat_enabled' => false,
            ],
        ]);
    }

    /**
     * Teacher-only dry-run grading for a preview. Runs the SAME auto-grader as a
     * real submit (including the isolated interactive-html grader) so the
     * teacher can verify auto and manual review behaviour — but persists
     * nothing: no ExamAnswer rows, no scores, no gamification.
     */
    public function previewGrade(Request $request, string $examId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);

        $this->authorize('viewQuestions', $exam);

        $validated = $request->validate([
            'answers' => 'present|array',
            'answers.*.exam_question_id' => 'required|string',
            'answers.*.given_answer' => 'nullable',
        ]);

        $examQuestions = ExamQuestion::where('exam_id', $exam->id)
            ->with('question.options')
            ->get()
            ->reject(fn ($eq) => ! $eq->question)
            ->keyBy('id');

        $totalPoints = (float) $examQuestions->sum(fn ($eq) => $eq->points_override ?? $eq->question->points);
        $earned = 0.0;
        $needsReview = false;
        $perAnswer = [];

        foreach ($validated['answers'] as $ans) {
            $examQuestion = $examQuestions[$ans['exam_question_id']] ?? null;
            if (! $examQuestion) {
                continue;
            }

            $question = $examQuestion->question;
            $pointsMax = (float) ($examQuestion->points_override ?? $question->points);
            $given = $ans['given_answer'] ?? null;

            $isCorrect = $this->paper->checkAnswer($question, $given);
            $pointsEarned = $isCorrect === true ? $pointsMax : 0.0;
            $earned += $pointsEarned;

            if ($isCorrect === null) {
                $needsReview = true;
            }

            $perAnswer[] = [
                'exam_question_id' => $examQuestion->id,
                'is_correct' => $isCorrect,
                'points_earned' => $pointsEarned,
                'points_max' => $pointsMax,
            ];
        }

        $percentage = $totalPoints > 0 ? round(($earned / $totalPoints) * 100, 2) : 0;

        return response()->json([
            'preview' => true,
            'result' => [
                'score' => $earned,
                'total' => $totalPoints,
                'percentage' => $percentage,
                'passed' => ! $needsReview && $percentage >= (float) $exam->pass_mark_percent,
                'needs_review' => $needsReview,
                'pass_mark' => $exam->pass_mark_percent,
                'attempts_left' => null,
            ],
            'answers' => $perAnswer,
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

        // Course-group restriction: an exam shared to specific groups is not
        // attemptable by a student outside those groups (staff always can).
        if (! $user->is_super_admin && ! $this->isTenantStaff(request(), (string) $exam->tenant_id)) {
            if ($exam->groups()->exists()
                && ! $exam->groups()->whereHas('members', fn ($m) => $m->where('users.id', $user->id))->exists()) {
                return response()->json(['message' => 'This exam is not available for your group.'], 403);
            }
        }

        $maxAttempts = $exam->max_attempts > 0 ? $exam->max_attempts : PHP_INT_MAX;

        // Term scope: the student's current effective enrollment for this
        // exam's course decides which term's attempt budget applies.
        $termId = $this->currentTermId((string) $exam->tenant_id, (string) $exam->course_id, (string) $user->id);

        [$attempt, $resumed] = DB::transaction(function () use ($exam, $user, $maxAttempts, $termId) {
            Exam::whereKey($exam->id)->lockForUpdate()->first();

            // An in-progress attempt that has outlived its duration is a
            // dead end: auto-submit it (grade whatever exists, zero answers
            // if nothing was saved) so it can never block a retake. Scoped to
            // this term so another term's live attempt is untouched.
            $stale = $this->scopeToTerm(
                ExamAttempt::where('exam_id', $exam->id)
                    ->where('user_id', $user->id)
                    ->where('status', 'in_progress'),
                $termId
            )->where('started_at', '<', now()->subMinutes(max(1, $exam->duration_minutes)))
                ->get();

            foreach ($stale as $staleAttempt) {
                $this->autoSubmit($staleAttempt);
            }

            // Resume a still-live attempt instead of opening another one: a
            // page reload, a flaky connection or a double click must continue
            // the SAME attempt with the SAME clock, never consume an extra
            // attempt slot or reset the timer.
            $live = $this->scopeToTerm(
                ExamAttempt::where('exam_id', $exam->id)
                    ->where('user_id', $user->id)
                    ->where('status', 'in_progress'),
                $termId
            )->orderByDesc('started_at')->first();

            if ($live) {
                return [$live, true];
            }

            // Attempts are counted PER TERM against the exam's limit, so a
            // re-enrolled student starts a fresh budget without mixing results.
            $usedInTerm = $this->scopeToTerm(
                ExamAttempt::where('exam_id', $exam->id)->where('user_id', $user->id),
                $termId
            )->count();

            if ($usedInTerm >= $maxAttempts) {
                return [null, false];
            }

            // Numbering stays globally monotonic, respecting the unique key
            // (exam_id, user_id, attempt_number).
            $attemptNumber = ExamAttempt::where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->max('attempt_number') + 1;

            return [ExamAttempt::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $exam->tenant_id,
                'exam_id' => $exam->id,
                'user_id' => $user->id,
                'academic_term_id' => $termId,
                'attempt_number' => $attemptNumber,
                'status' => 'in_progress',
                'started_at' => now(),
            ]), false];
        });

        if (! $attempt) {
            return response()->json(['message' => 'Maximum attempts reached.'], 403);
        }

        $examQuestions = ExamQuestion::where('exam_id', $exam->id)
            ->with('question.options')
            ->orderBy('sort_order')
            ->get()
            // Skip soft-deleted questions so they can never break a run.
            ->reject(fn ($eq) => ! $eq->question)
            ->values();

        $metadata = $attempt->metadata ?? [];
        $paper = $this->paper->isValid($metadata[ExamPaper::KEY] ?? null)
            ? $metadata[ExamPaper::KEY]
            : null;

        if ($paper !== null) {
            // A resumed attempt serves its FROZEN paper: the exact questions,
            // options, points and grading keys it was drawn with — a later
            // edit to the question bank cannot change a live attempt.
            $questionsData = $this->paperPayload($paper, $exam);
        } else {
            // Legacy resumed attempt (started before papers existed) or a fresh
            // one. Replay the original draw from question_order when present.
            $questionOrder = $metadata['question_order'] ?? null;

            if ($resumed && is_array($questionOrder) && $questionOrder !== []) {
                $order = array_flip($questionOrder);
                $examQuestions = $examQuestions
                    ->filter(fn ($eq) => isset($order[$eq->question_id]))
                    ->sortBy(fn ($eq) => $order[$eq->question_id])
                    ->values();
            } else {
                if ($exam->random_questions_count && $exam->random_questions_count > 0 && $exam->random_questions_count < $examQuestions->count()) {
                    $examQuestions = $examQuestions->shuffle()->take($exam->random_questions_count)->values();
                }

                if ($exam->shuffle_questions) {
                    $examQuestions = $examQuestions->shuffle()->values();
                }
            }

            $paper = $this->paper->build($examQuestions);
            $questionsData = $this->paperPayload($paper, $exam);

            if (! $resumed) {
                // Freeze the paper and its order so a reload/refresh continues
                // the same, unchanged paper instead of reshuffling into a new one.
                $attempt->update([
                    'metadata' => array_merge($metadata, [
                        'question_order' => $this->paper->questionOrder($paper),
                        ExamPaper::KEY => $paper,
                    ]),
                ]);
            }
        }

        $attemptsUsed = $this->scopeToTerm(
            ExamAttempt::where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->where('status', 'submitted'),
            $termId
        )->count();
        $attemptsLeft = $exam->max_attempts > 0 ? max(0, $exam->max_attempts - $attemptsUsed) : -1;

        // The deadline always runs from when THIS attempt actually started, so
        // resuming a reloaded/reconnected attempt continues the real clock
        // instead of silently restarting it.
        $deadline = null;
        if ($exam->duration_minutes > 0) {
            $deadline = $attempt->started_at->copy()->addMinutes($exam->duration_minutes)->toIso8601String();
        }

        // Anything already saved for a resumed attempt is handed back so a
        // reconnect drops the student back exactly where they left off.
        $savedAnswers = $resumed
            ? ExamAnswer::where('exam_attempt_id', $attempt->id)
                ->get()
                ->mapWithKeys(fn (ExamAnswer $a) => [
                    (string) $a->exam_question_id => $this->paper->decodeStoredAnswer($a->given_answer),
                ])
                ->all()
            : (object) [];

        return response()->json([
            'attempt' => new ExamAttemptResource($attempt),
            'resumed' => $resumed,
            'questions' => $questionsData,
            'saved_answers' => $savedAnswers,
            'deadline' => $deadline,
            'attempts_left' => $attemptsLeft,
            'exam' => [
                'title' => $exam->title,
                'duration_minutes' => $exam->duration_minutes,
                'shuffle_questions' => $exam->shuffle_questions,
                'anti_cheat_enabled' => (bool) $exam->anti_cheat_enabled,
                'allow_resume' => true,
            ],
        ]);
    }

    /**
     * Lightweight autosave for an in-progress attempt. Only the current user's
     * own live attempt is writable, the time limit is enforced, and the payload
     * is bounded — so a flaky connection can persist progress without being a
     * way to submit twice or tamper with a graded attempt.
     */
    public function autosave(Request $request, string $examId, string $attemptId): JsonResponse
    {
        $exam = Exam::findOrFail($examId);
        $attempt = ExamAttempt::where('exam_id', $exam->id)
            ->where('id', $attemptId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        // Past the deadline: refuse the write and let the client auto-submit.
        if ($exam->duration_minutes > 0 && $attempt->started_at
            && now()->gt($attempt->started_at->copy()->addMinutes($exam->duration_minutes))) {
            return response()->json(['message' => 'Time is up.', 'expired' => true], 409);
        }

        $validated = $request->validate([
            'answers' => 'present|array|max:500',
            'answers.*.exam_question_id' => 'required|string',
            'answers.*.given_answer' => 'nullable',
        ]);

        $allowedIds = ExamQuestion::where('exam_id', $exam->id)->pluck('question_id', 'id');

        $rows = [];
        foreach ($validated['answers'] as $ans) {
            $examQuestionId = $ans['exam_question_id'] ?? null;
            if (! $examQuestionId || ! $allowedIds->has($examQuestionId)) {
                continue;
            }

            $rows[] = [
                'id' => UuidV7::generate(),
                'tenant_id' => $exam->tenant_id,
                'exam_attempt_id' => $attempt->id,
                'exam_question_id' => $examQuestionId,
                'question_id' => $allowedIds[$examQuestionId],
                // Stored ungraded: `is_correct` stays null so an autosaved
                // answer can never look graded before the real submit.
                'given_answer' => json_encode($ans['given_answer'] ?? null),
                'is_correct' => null,
                'points_earned' => 0,
                'points_max' => 0,
                'time_spent_seconds' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Replace this attempt's draft answers in one transaction. An
        // in-progress attempt has no graded answers yet, so replacing the whole
        // set is safe and keeps repeated autosaves from piling up.
        DB::transaction(function () use ($attempt, $rows) {
            ExamAnswer::where('exam_attempt_id', $attempt->id)->delete();

            if ($rows !== []) {
                ExamAnswer::insert($rows);
            }
        });

        return response()->json(['saved' => true, 'count' => count($rows)]);
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

        // Grade against the FROZEN paper the student was actually served, so a
        // teacher editing the bank mid-attempt can never change a live
        // attempt's questions, options or grading keys. A legacy attempt
        // without a stored paper falls back to the live bank.
        $attemptMetadata = $attempt->metadata ?? [];
        $paper = $this->paper->isValid($attemptMetadata[ExamPaper::KEY] ?? null)
            ? $attemptMetadata[ExamPaper::KEY]
            : null;

        if ($paper !== null) {
            $grading = collect($paper['questions'])->keyBy('exam_question_id')->map(fn (array $entry) => [
                'question' => $this->paper->gradable($entry),
                'points_max' => (float) $entry['points'],
            ]);
        } else {
            $grading = ExamQuestion::where('exam_id', $exam->id)
                ->with('question.options')
                ->get()
                ->reject(fn ($eq) => ! $eq->question)
                ->keyBy('id')
                ->map(fn ($eq) => [
                    'question' => $eq->question,
                    'points_max' => (float) ($eq->points_override ?? $eq->question->points),
                ]);
        }

        // Total points always derive from the full server-side question set,
        // never from the submitted subset — otherwise answering one easy
        // question would yield 100%.
        $totalPoints = (float) $grading->sum(fn ($g) => $g['points_max']);

        $earnedPoints = 0.0;
        $answersData = [];
        $seenExamQuestionIds = [];
        $needsReview = false;

        foreach ($request->answers as $ans) {
            $examQuestionId = $ans['exam_question_id'];
            $gradingEntry = $grading[$examQuestionId] ?? null;
            if (! $gradingEntry) {
                abort(422, 'Invalid question for this exam.');
            }

            if (isset($seenExamQuestionIds[$examQuestionId])) {
                abort(422, 'Duplicate answer for the same question.');
            }
            $seenExamQuestionIds[$examQuestionId] = true;

            $question = $gradingEntry['question'];
            $pointsMax = $gradingEntry['points_max'];

            // Writing and file answers cannot be auto-graded truthfully, and a
            // sandboxed HTML question without a decisive key must be reviewed
            // too: the attempt is flagged and only the teacher's score counts.
            if (in_array($question->question_type, ['writing', 'file_upload'], true)
                || (Question::isSandboxType($question->question_type)
                    && $this->paper->requiresManualReview($question))) {
                $needsReview = true;
            }

            // Reject an unbounded sandboxed answer blob before it is compared or
            // persisted (scoped to those types so existing answer limits for the
            // eight original types are unchanged).
            if (Question::isSandboxType($question->question_type)
                && ! $this->paper->isWithinSizeLimit($ans['given_answer'])) {
                abort(422, 'Answer is too large.');
            }

            $isCorrect = $this->paper->checkAnswer($question, $ans['given_answer']);
            $pointsEarned = $isCorrect === true ? $pointsMax : 0;
            $earnedPoints += $pointsEarned;

            $answersData[] = [
                'id' => UuidV7::generate(),
                'tenant_id' => $exam->tenant_id,
                'exam_attempt_id' => $attempt->id,
                'exam_question_id' => $examQuestionId,
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

        // Drop any autosaved drafts from this attempt before writing the final,
        // graded answer set (otherwise a reconnect-then-submit would duplicate).
        ExamAnswer::where('exam_attempt_id', $attempt->id)->delete();
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

        $attemptsUsed = $this->scopeToTerm(
            ExamAttempt::where('exam_id', $exam->id)
                ->where('user_id', $request->user()->id)
                ->where('status', 'submitted'),
            $attempt->academic_term_id !== null ? (string) $attempt->academic_term_id : null
        )->count();
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

        // Prefer the frozen paper's total so an expired attempt is scored
        // against the exact questions the student was served.
        $paper = $this->paper->isValid(($attempt->metadata ?? [])[ExamPaper::KEY] ?? null)
            ? ($attempt->metadata ?? [])[ExamPaper::KEY]
            : null;

        if ($paper !== null) {
            $totalPoints = (float) collect($paper['questions'])->sum(fn ($e) => $e['points']);
        } else {
            $examQuestions = ExamQuestion::where('exam_id', $attempt->exam_id)
                ->with('question')
                ->get()
                ->reject(fn ($eq) => ! $eq->question);

            $totalPoints = (float) $examQuestions->sum(fn ($eq) => $eq->points_override ?? $eq->question->points);
        }

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

    /**
     * The academic term that scopes this student's attempts for a course:
     * their current effective enrollment's term, or NULL when it is not
     * term-bound (legacy rows). Historical attempts from another term never
     * count against the current budget.
     */
    private function currentTermId(string $tenantId, string $courseId, string $userId): ?string
    {
        $termId = Enrollment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->effective()
            ->orderByDesc('enrolled_at')
            ->value('academic_term_id');

        return $termId !== null ? (string) $termId : null;
    }

    /**
     * Constrain an attempt query to one term scope. A NULL scope matches the
     * legacy "no term" rows only, so old results are never pulled into a
     * term-bound limit.
     */
    private function scopeToTerm(\Illuminate\Database\Eloquent\Builder $query, ?string $termId): \Illuminate\Database\Eloquent\Builder
    {
        return $termId === null
            ? $query->whereNull('academic_term_id')
            : $query->where('academic_term_id', $termId);
    }
}
