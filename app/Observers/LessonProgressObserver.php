<?php

namespace App\Observers;

use App\Models\LessonProgress;
use App\Services\NotificationService;

class LessonProgressObserver
{
    public function __construct(
        private readonly NotificationService $notifier,
    ) {}

    public function created(LessonProgress $progress): void
    {
        if ($progress->status !== 'completed') {
            return;
        }

        $lesson = $progress->lesson;
        $student = $progress->enrollment?->student;

        if (! $student || ! $lesson) {
            return;
        }

        $locale = $student->locale ?? 'ar';
        $isAr = $locale === 'ar';

        $this->notifier->notify(
            notifiable: $student,
            type: 'lesson.completed',
            title: $isAr ? "أكملت الدرس: {$lesson->title}" : "Lesson completed: {$lesson->title}",
            body: $isAr ? "أنهيت الدرس: {$lesson->title}" : "You completed the lesson: {$lesson->title}",
            tenantId: $progress->tenant_id,
            data: [
                'enrollment_id' => $progress->enrollment_id,
                'lesson_id' => $lesson->id,
                'lesson_title' => $lesson->title,
            ],
        );
    }
}
