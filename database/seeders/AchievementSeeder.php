<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;
use Symfony\Component\Uid\UuidV7;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'key' => 'first_course', 'title' => 'First Step', 'title_ar' => 'أول خطوة',
                'description' => 'Enroll in your first course', 'description_ar' => 'سجّل في أول كورس لك',
                'icon' => 'rocket', 'points' => 10, 'criteria' => ['courses_enrolled' => 1], 'sort_order' => 1,
            ],
            [
                'key' => 'first_lesson', 'title' => 'Beginner', 'title_ar' => 'بداية الرحلة',
                'description' => 'Complete your first lesson', 'description_ar' => 'أكمل أول درس لك',
                'icon' => 'play', 'points' => 10, 'criteria' => ['lessons_completed' => 1], 'sort_order' => 2,
            ],
            [
                'key' => 'lesson_10', 'title' => 'Ten Lessons', 'title_ar' => 'عشر دروس',
                'description' => 'Complete 10 lessons', 'description_ar' => 'أكمل 10 دروس',
                'icon' => 'book', 'points' => 30, 'criteria' => ['lessons_completed' => 10], 'sort_order' => 3,
            ],
            [
                'key' => 'lesson_50', 'title' => 'Fifty Lessons', 'title_ar' => 'خمسون درساً',
                'description' => 'Complete 50 lessons', 'description_ar' => 'أكمل 50 درساً',
                'icon' => 'book-open', 'points' => 100, 'criteria' => ['lessons_completed' => 50], 'sort_order' => 4,
            ],
            [
                'key' => 'first_course_done', 'title' => 'Graduate', 'title_ar' => 'خريج',
                'description' => 'Complete your first course', 'description_ar' => 'أكمل أول كورس بالكامل',
                'icon' => 'graduation', 'points' => 100, 'criteria' => ['courses_completed' => 1], 'sort_order' => 5,
            ],
            [
                'key' => 'courses_3', 'title' => 'Multi-Tasker', 'title_ar' => 'منتج',
                'description' => 'Complete 3 courses', 'description_ar' => 'أكمل 3 كورسات',
                'icon' => 'layers', 'points' => 150, 'criteria' => ['courses_completed' => 3], 'sort_order' => 6,
            ],
            [
                'key' => 'exam_pass', 'title' => 'Exam Winner', 'title_ar' => 'نجاح',
                'description' => 'Pass your first exam', 'description_ar' => 'اجتز أول اختبار لك',
                'icon' => 'check', 'points' => 50, 'criteria' => ['exams_passed' => 1], 'sort_order' => 7,
            ],
            [
                'key' => 'exam_5', 'title' => 'Exam Champion', 'title_ar' => 'بطل الاختبارات',
                'description' => 'Pass 5 exams', 'description_ar' => 'اجتز 5 اختبارات',
                'icon' => 'trophy', 'points' => 150, 'criteria' => ['exams_passed' => 5], 'sort_order' => 8,
            ],
            [
                'key' => 'perfect_score', 'title' => 'Perfect Score', 'title_ar' => 'علامة كاملة',
                'description' => 'Score 100% in an exam', 'description_ar' => 'احصل على 100% في اختبار',
                'icon' => 'star', 'points' => 100, 'criteria' => ['exams_perfect' => 1], 'sort_order' => 9,
            ],
            [
                'key' => 'points_500', 'title' => 'Point Collector', 'title_ar' => 'جامع النقاط',
                'description' => 'Reach 500 total points', 'description_ar' => 'اجمع 500 نقطة',
                'icon' => 'coins', 'points' => 50, 'criteria' => ['points_total' => 500], 'sort_order' => 10,
            ],
        ];

        foreach ($definitions as $definition) {
            $existing = Achievement::where('key', $definition['key'])->first();

            if ($existing) {
                $existing->update($definition);
            } else {
                Achievement::create(array_merge(['id' => UuidV7::generate()], $definition));
            }
        }
    }
}
