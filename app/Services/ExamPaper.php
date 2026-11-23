<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Collection;

/**
 * ورقة الأسئلة المجمّدة للمحاولة (paper).
 *
 * تُبنى مرة واحدة عند بدء المحاولة وتُخزَّن في
 * `exam_attempts.metadata.paper`، فتبقى أسئلة الطالب وخياراتها ونقاطها
 * ومفاتيح تصحيحها كما كانت لحظة البدء حتى لو عدّل المعلم بنك الأسئلة أو
 * أعاد ترتيبه لاحقًا. النسخة تعيش على صف المحاولة (خادمية فقط) ولا تُرسَل
 * للطالب مباشرة: عرض الأسئلة يمرّ عبر نفس تنقية الحمولة المستخدمة في التشغيل.
 *
 * هذه الخدمة أيضًا الموضع الوحيد لمنطق (حمولة الطالب) و(تصحيح الإجابة) حتى
 * لا يفترق المسار الحيّ عن مسار النسخة المجمّدة.
 */
class ExamPaper
{
    /** مفتاح تخزين الورقة داخل exam_attempts.metadata. */
    public const KEY = 'paper';

    /** نسخة صيغة الورقة — تُرفع عند تغيير الشكل لتفادي قراءة قديمة خاطئة. */
    public const VERSION = 1;

    /**
     * حد أقصى لحجم الورقة الكاملة (بايت). عند تجاوزه نحذف حمولة العرض
     * المعزولة (HTML/CSS/JS) للأسئلة التفاعلية فقط ونبقي مفتاح التصحيح
     * المجمّد، فلا تتضخم قاعدة البيانات بضخامة أسئلة HTML كبيرة.
     */
    private const MAX_BYTES = 1_000_000;

    public function __construct(private readonly InteractiveHtmlGrader $grader) {}

    /**
     * ابنِ ورقة مجمّدة من أسئلة اختبار مرتّبة.
     *
     * @param  Collection<int, ExamQuestion>  $examQuestions  بعلاقة question.options محمّلة
     * @return array<string, mixed>
     */
    public function build(Collection $examQuestions): array
    {
        $entries = $examQuestions->map(function (ExamQuestion $eq) {
            $q = $eq->question;
            $metadata = is_array($q->metadata) ? $q->metadata : [];

            return [
                'exam_question_id' => $eq->id,
                'question_id' => $q->id,
                'question_type' => $q->question_type,
                'question_text' => $q->question_text,
                'points' => (float) ($eq->points_override ?? $q->points),
                'options' => collect($q->options ?? [])->map(fn (QuestionOption $o) => [
                    'id' => $o->id,
                    'option_text' => $o->option_text,
                    'sort_order' => (int) $o->sort_order,
                    'is_correct' => (bool) $o->is_correct,
                ])->values()->all(),
                'metadata' => $metadata,
                'image_url' => $metadata['image_url'] ?? null,
            ];
        })->values()->all();

        $paper = ['version' => self::VERSION, 'questions' => $entries];

        if (strlen((string) json_encode($paper)) > self::MAX_BYTES) {
            $paper = $this->compact($paper);
        }

        return $paper;
    }

    /**
     * قلّص الورقة: احذف حمولة العرض المعزولة (HTML/CSS/JS) من أسئلة
     * الرمل وأزل علامة العرض الحيّ — يُعاد بناؤها من البنك عند الاستئناف.
     */
    private function compact(array $paper): array
    {
        foreach ($paper['questions'] as &$entry) {
            if (Question::isSandboxType($entry['question_type'])) {
                unset($entry['metadata']['interactive']);
                $entry['display_live'] = true;
            }
        }
        unset($entry);

        return $paper;
    }

    /** هل هذه ورقة صالحة قابلة للقراءة؟ */
    public function isValid(?array $paper): bool
    {
        return is_array($paper)
            && ($paper['version'] ?? null) === self::VERSION
            && is_array($paper['questions'] ?? null)
            && $paper['questions'] !== [];
    }

    /** معرّفات الأسئلة بترتيب الورقة (متوافق مع question_order القديم). */
    public function questionOrder(array $paper): array
    {
        return array_values(array_map(
            fn ($e) => $e['question_id'],
            $paper['questions'] ?? []
        ));
    }

    /**
     * حمولة الطالب من ورقة مجمّدة، بنفس ترتيب التشغيل وتنقية المفاتيح.
     *
     * @param  array<string, Question>  $liveSandbox  أسئلة الرمل الحيّة (لمفاتيح العرض الحيّ فقط)
     * @return Collection<int, array<string, mixed>>
     */
    public function payload(array $paper, Exam $exam, array $liveSandbox = []): Collection
    {
        return collect($paper['questions'] ?? [])->map(function (array $entry) use ($exam, $liveSandbox) {
            $type = (string) $entry['question_type'];
            $metadata = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];

            // سؤال رملي مقرصن الحجم: خُذ حمولة العرض من البنك الحيّ (يبقى
            // مفتاح التصحيح مجمّدًا في $metadata.grading).
            if (! empty($entry['display_live']) && Question::isSandboxType($type)) {
                $live = $liveSandbox[$entry['question_id']] ?? null;
                $liveInteractive = is_array($live?->metadata['interactive'] ?? null)
                    ? $live->metadata['interactive']
                    : [];
                $metadata['interactive'] = $liveInteractive;
            }

            $options = collect($entry['options'] ?? []);
            if ($exam->shuffle_options) {
                $options = $options->shuffle();
            }

            return [
                'exam_question_id' => $entry['exam_question_id'],
                'question_id' => $entry['question_id'],
                'question_type' => $type,
                'question_text' => $entry['question_text'],
                'points' => $entry['points'],
                'options' => $options->map(fn (array $o) => [
                    'id' => $o['id'],
                    'option_text' => $o['option_text'],
                    'sort_order' => $o['sort_order'],
                ])->values(),
                'metadata' => $this->studentSafeMetadata($metadata, $type),
                'image_url' => $entry['image_url'] ?? ($metadata['image_url'] ?? null),
            ];
        })->values();
    }

    /**
     * أعِد بناء سؤال قابل للتصحيح من مدخل ورقة مجمّدة، بحيث يعمل `checkAnswer`
     * بنفس الطريقة تمامًا على المسار الحيّ وعلى المسار المجمّد.
     */
    public function gradable(array $entry): Question
    {
        $question = new Question;
        $question->forceFill([
            'id' => $entry['question_id'],
            'question_type' => $entry['question_type'],
            'question_text' => $entry['question_text'],
            'points' => $entry['points'],
            'metadata' => is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [],
        ]);
        // الحساسية: القوالب لا تُحفظ، لكن هذا الكائن في الذاكرة فقط.
        $question->exists = false;

        $questionId = $entry['question_id'];

        $question->setRelation('options', collect($entry['options'] ?? [])->map(function (array $o) use ($questionId) {
            $option = new QuestionOption;
            $option->forceFill([
                'id' => $o['id'],
                'question_id' => $questionId,
                'option_text' => $o['option_text'],
                'sort_order' => (int) ($o['sort_order'] ?? 0),
                'is_correct' => (bool) ($o['is_correct'] ?? false),
            ]);
            $option->exists = false;

            return $option;
        }));

        return $question;
    }

    /**
     * الحمولة الآمنة للطالب من بيانات السؤال الوصفية:
     *  - لا تُرسَل مفاتيح الإجابة إطلاقًا (keywords/answers)
     *  - أسئلة السحب/المطابقة تُخلط في العمودين فلا تتراصف الإجابة الصحيحة
     *  - أسئلة الترتيب تُرسَل مخلوطة (التصحيح يقارن بالترتيب المخزَّن خادميًا)
     *  - املأ الفراغ يرسل عدد الفراغات فقط
     *  - أسئلة الرمل لها قائمة سماح صريحة: الحمولة التفاعلية فقط
     */
    public function studentSafeMetadata(array $metadata, string $questionType): array
    {
        if (Question::isSandboxType($questionType)) {
            $interactive = is_array($metadata['interactive'] ?? null) ? $metadata['interactive'] : [];

            return [
                'interactive' => [
                    'html' => (string) ($interactive['html'] ?? ''),
                    'css' => (string) ($interactive['css'] ?? ''),
                    'js' => (string) ($interactive['js'] ?? ''),
                    'height' => (int) ($interactive['height'] ?? 360),
                    'auto_capture' => (bool) ($interactive['auto_capture'] ?? true),
                ],
            ];
        }

        $safe = collect($metadata)->except(['keywords', 'answers'])->all();

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
            $safe['items'] = is_array($items) && $items !== []
                ? collect($items)->shuffle()->values()->all()
                : [];
        }

        if ($questionType === 'fill_blank') {
            $safe['blanks_count'] = is_array($metadata['answers'] ?? null)
                ? count($metadata['answers'])
                : 0;
        }

        return $safe;
    }

    /**
     * صحّح إجابة الطالب مقابل سؤال (حيًّا أو مُعاد بناؤه من ورقة مجمّدة).
     * يعيد true/false، أو null عندما يلزم تصحيح يدوي.
     */
    public function checkAnswer(Question $question, mixed $givenAnswer): ?bool
    {
        if (Question::isSandboxType($question->question_type)) {
            return $this->grader->grade($question, $givenAnswer);
        }

        if ($question->question_type === 'multiple_choice') {
            $correctOptions = $question->options->where('is_correct', true)->pluck('id')->all();

            if (is_array($givenAnswer)) {
                $given = array_values($givenAnswer);
                sort($given);
                sort($correctOptions);

                return $given === $correctOptions;
            }

            return in_array($givenAnswer, $correctOptions, true);
        }

        if ($question->question_type === 'true_false') {
            $correctOption = $question->options->where('is_correct', true)->first();

            if (is_array($givenAnswer)) {
                return in_array($correctOption?->id, $givenAnswer, true);
            }

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

            if ($keywords === [] || $text === '') {
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

    /** هل يحتاج هذا السؤال تصحيحًا يدويًّا (كتابة/ملف/سؤال رملي بلا مفتاح حاسم)؟ */
    public function requiresManualReview(Question $question): bool
    {
        return $this->grader->requiresManualReview($question);
    }

    /** هل إجابة السؤال الرملي داخل الحد المقبول للحجم؟ */
    public function isWithinSizeLimit(mixed $answer): bool
    {
        return $this->grader->isWithinSizeLimit($answer);
    }

    /** أعِد فكّ JSON المستخدم عند حفظ الإجابة (نص/مصفوفة/كائن). */
    public function decodeStoredAnswer(mixed $stored): mixed
    {
        if (! is_string($stored)) {
            return $stored;
        }

        $decoded = json_decode($stored, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $stored;
    }
}
