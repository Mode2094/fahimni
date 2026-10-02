<?php

namespace App\Observers;

use App\Models\ExamAttempt;
use App\Services\NotificationService;

class ExamAttemptObserver
{
    public function __construct(
        private readonly NotificationService $notifier,
    ) {}

    public function updated(ExamAttempt $attempt): void
    {
        if ($attempt->wasChanged('status') && $attempt->status === 'submitted') {
            $student = $attempt->student;
            $exam = $attempt->exam;

            if (! $student || ! $exam) {
                return;
            }

            $status = $attempt->passed ? 'passed' : 'failed';
            $locale = $student->locale ?? 'ar';
            $isAr = $locale === 'ar';

            $this->notifier->notify(
                notifiable: $student,
                type: 'exam.graded',
                title: $isAr
                    ? ($attempt->passed ? "نجحت في الاختبار: {$exam->title}" : "لم تنجح في الاختبار: {$exam->title}")
                    : "Exam {$status}: {$exam->title}",
                body: $isAr
                    ? "تم تصحيح اختبارك '{$exam->title}'. نتيجتك: {$attempt->percentage}%"
                    : "Your exam '{$exam->title}' has been graded. Score: {$attempt->percentage}%",
                tenantId: $attempt->tenant_id,
                data: [
                    'attempt_id' => $attempt->id,
                    'exam_id' => $exam->id,
                    'exam_title' => $exam->title,
                    'score' => $attempt->score,
                    'percentage' => $attempt->percentage,
                    'passed' => $attempt->passed,
                ],
            );
        }
    }
}
