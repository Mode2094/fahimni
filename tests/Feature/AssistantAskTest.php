<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * اختبارات "اسألني" — إثبات أن المساعد يجيب فعلياً عن أسئلة واقعية
 * باستخدام بيانات المنصة الحقيقية (تراجع حتمي بلا LLM في الاختبارات).
 */
class AssistantAskTest extends TenantTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // إجبار وضع التراجع الحتمي (لا مفتاح LLM في الاختبارات — حتمي وبلا شبكة).
        config()->set('assistant.enabled', true);
        config()->set('assistant.api_key', '');

        // Docker Desktop + sqlite: الأقفال العابرة بين الاختبارات المتتالية —
        // busy_timeout يجعلها تنتظر بدل أن تفشل فوراً، و WAL يقلل التنازع.
        try {
            DB::statement('PRAGMA busy_timeout = 8000');
            DB::statement('PRAGMA journal_mode = WAL');
        } catch (\Throwable) {
            // تجاهل — البيئة قد لا تدعمها.
        }
    }

    private function askAsTeacher(string $question, Tenant $tenant, User $user, string $page = ''): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders($this->authHeaders($user, $tenant))
            ->postJson('/api/v1/teacher/assistant/ask', [
                'question' => $question,
                'page' => $page,
            ]);
    }

    private function teacherTenant(): array
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'trialing');
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher', 'active');

        return [$tenant, $teacher];
    }

    #[DataProvider('realQuestionsProvider')]
    public function test_answers_real_questions(string $question, string $mustContain): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->askAsTeacher($question, $tenant, $teacher);

        $res->assertOk();

        $data = $res->json();
        $this->assertSame('rules', $data['mode'], 'الاختبار يجب أن يسير عبر التراجع الحتمي');
        $this->assertNotNull($data['matched'], "السؤال «{$question}» لم يطابق أي موضوع — نقص تغطية");
        $this->assertNotSame('لم أتمكن من تحديد موضوع السؤال', $data['answer'], "السؤال «{$question}» عاد كإجابة عامة رغم وجود الموضوع في المنصة");
        $this->assertStringContainsString($mustContain, $data['answer'], "إجابة «{$question}» يجب أن تذكر «{$mustContain}»");
    }

    public static function realQuestionsProvider(): array
    {
        return [
            'create_course' => ['كيف أعمل كورس؟', 'كورس'],
            'add_unit' => ['كيف أضيف وحدة للكورس؟', 'وحدات'],
            'add_lesson' => ['كيف أضيف درس؟', 'دروس'],
            'upload_video' => ['وين أضيف الفيديو؟', 'فيديو'],
            'video_not_visible' => ['ليش الفيديو مش ظاهر؟', 'الفيديو'],
            'upload_failed' => ['ما بقدر أرفع فيديو، شو المشكلة؟', 'الفيديو'],
            'live_start' => ['كيف أبدأ بث؟', 'بث'],
            'live_broken' => ['ليش البث ما اشتغل؟', 'بث'],
            'cards_create' => ['كيف أنشئ بطاقات؟', 'بطاق'],
            'cards_redeem' => ['كيف يسترد الطالب البطاقة؟', 'بطاق'],
            'cards_count' => ['كم بطاقة متبقية؟', 'بطاقات'],
            'students' => ['كيف أضيف طالب؟', 'الطلاب'],
            'exam_create' => ['كيف أعمل اختبار؟', 'اختبار'],
            'exam_questions' => ['كيف أضيف الأسئلة للاختبار؟', 'سؤال'],
            'question_bank' => ['شو هو بنك الأسئلة؟', 'سؤال'],
            'profits' => ['وين أشوف أرباحي؟', 'إيراد'],
            'storage' => ['كم باقيلي مساحة؟', 'مساحة'],
            'domain' => ['كيف أغير الدومين؟', 'دومين'],
            'teachers_count' => ['كم معلم بقدر أضيف؟', 'معلم'],
            'appointments' => ['كيف أعمل موعد للطالب؟', 'موعد'],
            'services' => ['كيف أضيف خدمة مدفوعة؟', 'خدمة'],
            'scheduled' => ['كيف أجدد درس أسبوعي؟', 'درس'],
            'blog' => ['كيف أكتب مقال في المدونة؟', 'مقال'],
            'community' => ['كيف أسوي موضوع في المجتمع؟', 'موضوع'],
            'announcement' => ['كيف أبعت إعلان للطلاب؟', 'إعلان'],
            'settings_watermark' => ['كيف أفعّل العلامة المائية للفيديو؟', 'حماية الفيديو'],
            'team' => ['كيف أضيف معلم لفريقي؟', 'فريق'],
            'assignments' => ['كيف أعمل واجب للطلاب؟', 'واجب'],
        ];
    }

    public function test_live_feature_enabled_reflects_plan(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->askAsTeacher('هل أقدر أعمل بث مباشر؟', $tenant, $teacher);

        $res->assertOk();
        $this->assertStringContainsString('مفعلة', $res->json('answer'), 'خطة الاختبار تفعّل البث المباشر');
    }

    public function test_disabled_feature_is_reported_not_fabricated(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'trialing');
        // تعطيل البث في خطة هذا المستأجر.
        PlanFeature::where('feature_key', 'live_stream_enabled')->update(['feature_value' => 'false']);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher', 'active');

        $res = $this->askAsTeacher('هل أقدر أعمل بث مباشر؟', $tenant, $teacher);

        $res->assertOk();
        $this->assertStringContainsString('غير مفعلة', $res->json('answer'));
    }

    public function test_storage_answer_uses_real_numbers(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->askAsTeacher('كم باقيلي مساحة؟', $tenant, $teacher);

        $res->assertOk();
        $answer = $res->json('answer');
        // خطة الاختبار: storage_limit_mb = 5120 → تُعرض 5120 MB.
        $this->assertStringContainsString('5120', $answer);
        $this->assertStringContainsString('متبقي', $answer);
    }

    public function test_page_link_returned_when_safe(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->askAsTeacher('وين أضيف الفيديو؟', $tenant, $teacher, '/courses/course-1');

        $res->assertOk();
        $this->assertNotNull($res->json('link'));
        $this->assertStringStartsNotWith('/admin', (string) $res->json('link'));
    }

    public function test_current_course_page_label_in_context(): void
    {
        // نحتاج مستأجراً وكورساً حقيقيين (slug مطلوب في الجدول).
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'trialing');
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher', 'active');

        $course = Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'teacher_id' => $teacher->id,
            'title' => 'كورس الاختبار',
            'slug' => 'test-course-'.Str::random(6),
            'is_published' => false,
        ]);

        $res = $this->askAsTeacher('كيف أضيف درس؟', $tenant, $teacher, '/courses/'.$course->id);

        $res->assertOk();
        // يجب أن تكون الإجابة مرتبطة بمنشئ الكورس (من السياق).
        $this->assertStringContainsString('دروس', $res->json('answer'));
    }

    public function test_tenant_isolation_teacher_b_sees_only_b(): void
    {
        // مستأجر A: لديه كورسات.
        [$tenantA, $teacherA] = $this->teacherTenant();
        Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'teacher_id' => $teacherA->id,
            'title' => 'كورس خاص بـ A',
            'slug' => 'course-a-'.Str::random(6),
            'is_published' => false,
        ]);

        // مستأجر B: بلا كورسات.
        [$tenantB, $teacherB] = $this->teacherTenant();

        $res = $this->askAsTeacher('كم عندي كورس؟', $tenantB, $teacherB);

        $res->assertOk();
        $answer = $res->json('answer');
        // B يرى صفراً وليس كورس A.
        $this->assertStringContainsString('0 /', $answer);
        $this->assertStringNotContainsString('خاص بـ A', $answer);
    }

    public function test_non_teacher_is_forbidden(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'trialing');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student', 'active');

        $res = $this->askAsTeacher('كيف أعمل كورس؟', $tenant, $student);

        $res->assertForbidden();
    }

    public function test_expired_or_missing_subscription_is_blocked(): void
    {
        $tenant = $this->makeTenant();
        // بدون اشتراك.
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher', 'active');

        $res = $this->askAsTeacher('كيف أعمل كورس؟', $tenant, $teacher);

        // بوابة الاشتراك تمنع استخدام اللوحة دون خطة نشطة.
        $this->assertTrue(in_array($res->status(), [402, 403], true));
    }

    public function test_out_of_scope_question_is_honest(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        $res = $this->askAsTeacher('ما هو أفضل علاج للصداع؟', $tenant, $teacher);

        $res->assertOk();
        $this->assertNull($res->json('matched'));
        $this->assertStringContainsString('لوحة المعلم', $res->json('answer'));
    }

    public function test_answer_does_not_leak_secrets(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        foreach (['api_key', 'secret', 'token', 'password', 'credential'] as $forbidden) {
            $res = $this->askAsTeacher('شو وضع الفيديوهات؟', $tenant, $teacher);
            $res->assertOk();
            $this->assertStringNotContainsString($forbidden, mb_strtolower($res->json('answer')));
        }
    }

    public function test_video_diagnostic_includes_real_asset_status(): void
    {
        [$tenant, $teacher] = $this->teacherTenant();

        // إنشاء أصل فيديو "عالق بالمعالجة" في مستأجر الاختبار.
        \App\Models\MediaAsset::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $teacher->id,
            'provider' => 'bunny',
            'provider_asset_id' => 'asset-1',
            'kind' => 'video',
            'status' => 'processing',
            'title' => 'شرح الدرس الأول',
            'size_bytes' => 1000,
            'storage_bytes' => 0,
        ]);

        $res = $this->askAsTeacher('ليش الفيديو مش ظاهر؟', $tenant, $teacher);

        $res->assertOk();
        $this->assertStringContainsString('شرح الدرس الأول', $res->json('answer'));
        $this->assertStringContainsString('processing', $res->json('answer'));
    }
}
