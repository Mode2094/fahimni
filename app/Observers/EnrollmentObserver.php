<?php

namespace App\Observers;

use App\Models\Enrollment;
use App\Services\NotificationService;

class EnrollmentObserver
{
    public function __construct(
        private readonly NotificationService $notifier,
    ) {}

    public function created(Enrollment $enrollment): void
    {
        $student = $enrollment->student;
        $course = $enrollment->course;

        if (! $student || ! $course) {
            return;
        }

        $locale = $student->locale ?? 'ar';
        $isAr = $locale === 'ar';

        $this->notifier->notify(
            notifiable: $student,
            type: 'enrollment.created',
            title: $isAr ? "تم تسجيلك في: {$course->title}" : "Enrolled in {$course->title}",
            body: $isAr ? "تم تسجيلك في الكورس: {$course->title}" : "You have been enrolled in the course: {$course->title}",
            tenantId: $enrollment->tenant_id,
            data: [
                'enrollment_id' => $enrollment->id,
                'course_id' => $course->id,
                'course_title' => $course->title,
            ],
        );
    }
}
