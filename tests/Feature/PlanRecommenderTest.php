<?php

namespace Tests\Feature;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Illuminate\Support\Str;

/**
 * خطة مثالية — أرقام الشات (السعة، الطلاب، الفريق، الكورسات، الميزانية)
 * يجب أن تكون مطابقة تماماً لأرقام الخطط الفعلية المخزنة، وتُشتق من
 * نفس بيانات صفحة الأسعار (is_active + الميزات الحية).
 */
class PlanRecommenderTest extends TenantTestCase
{
    protected function makePlan(string $name, float $price, array $features, bool $active = true): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'monthly_price' => $price,
            'currency' => 'JOD',
            'is_active' => $active,
            'is_public' => $active,
            'sort_order' => 0,
        ]);

        foreach ($features as $key => $value) {
            PlanFeature::create([
                'id' => (string) Str::uuid(),
                'plan_id' => $plan->id,
                'feature_key' => $key,
                'feature_value' => $value,
                'feature_type' => 'string',
                'sort_order' => 0,
            ]);
        }

        return $plan;
    }

    /**
     * الثلاثي الكنسي — أرقام الخطط المعتمدة (الانطلاقة / المحترف / المتقدم):
     * 29/59/89 دولاراً · 700GB/1.5TB/3TB · 50/250/1000 طالباً · 3/7/20 كورساً.
     *
     * @return array<string, SubscriptionPlan>
     */
    private function canonicalPlans(): array
    {
        return [
            'starter' => $this->makePlan('Starter', 29.00, [
                'students_limit' => '50',
                'courses_limit' => '3',
                'storage_limit_mb' => '716800',
                'live_sessions_limit' => '5',
                'live_stream_enabled' => 'false',
                'teachers_limit' => '1',
            ]),
            'pro' => $this->makePlan('Professional', 59.00, [
                'students_limit' => '250',
                'courses_limit' => '7',
                'storage_limit_mb' => '1572864',
                'live_sessions_limit' => '50',
                'live_stream_enabled' => 'true',
                'teachers_limit' => '3',
            ]),
            'ent' => $this->makePlan('Enterprise', 89.00, [
                'students_limit' => '1000',
                'courses_limit' => '20',
                'storage_limit_mb' => '3145728',
                'live_sessions_limit' => 'unlimited',
                'live_stream_enabled' => 'true',
                'teachers_limit' => 'unlimited',
            ]),
        ];
    }

    private function questionsPayload(): array
    {
        return $this->getJson('/api/v1/teacher/plan-recommender/questions')->assertOk()->json('questions');
    }

    /**
     * ابحث عن مفتاح الخيار المطابق (بالمفتاح الثابت مثل premium/no، أو بقيمة المتطلب).
     */
    private function keyFor(array $questions, string $qKey, mixed $target): string
    {
        $q = collect($questions)->firstWhere('key', $qKey);
        $this->assertNotNull($q, "السؤال «{$qKey}» غير موجود في رد الأسئلة");

        foreach ($q['options'] as $o) {
            if ($o['key'] === $target) {
                return $o['key'];
            }
            foreach (($o['requirements'] ?? []) as $value) {
                if (is_numeric($value) && is_numeric($target) && (float) $value == (float) $target) {
                    return $o['key'];
                }
                if (! is_numeric($value) && ! is_numeric($target) && (string) $value === (string) $target) {
                    return $o['key'];
                }
            }
        }

        $this->fail("لا يوجد خيار لـ «{$qKey}» بقيمة ".json_encode($target));
    }

    /**
     * ابنِ إجابات لكل الأسئلة: رقمية تُمرَّر بحد مستهدف، والباقي بمفتاح حرفي.
     */
    private function answersFor(array $questions, array $wants): array
    {
        $answers = [];

        foreach ($questions as $q) {
            $want = $wants[$q['key']] ?? 'default';
            $answers[$q['key']] = $this->keyFor($questions, $q['key'], $want);
        }

        return $answers;
    }

    private function recommend(array $answers): array
    {
        return $this->postJson('/api/v1/teacher/plan-recommender/recommend', [
            'answers' => $answers,
        ])->assertOk()->json();
    }

    public function test_chat_numbers_exactly_match_plan_numbers(): void
    {
        $this->canonicalPlans();
        $questions = $this->questionsPayload();

        $this->assertRequirementsMatch($questions, 'storage', 'storage_limit_mb', [716800, 1572864, 3145728]);
        $this->assertRequirementsMatch($questions, 'students_count', 'students_limit', [50, 250, 1000]);
        $this->assertRequirementsMatch($questions, 'team', 'teachers_limit', [1, 3, 'unlimited']);
        $this->assertRequirementsMatch($questions, 'courses', 'courses_limit', [3, 7, 20]);
        $this->assertRequirementsMatch($questions, 'budget', 'monthly_price', [29.00, 59.00, 89.00]);

        // تسميات السعة تُعرض بوحدتها الواقعية (جيجا/تيرا) نفسها الموجودة في الخطط.
        $storageOptions = collect($questions)->firstWhere('key', 'storage')['options'];
        $this->assertSame('حتى 700 جيجا', $storageOptions[0]['label']['ar']);
        $this->assertSame('Up to 700 GB', $storageOptions[0]['label']['en']);
        $this->assertSame('Up to 1.5 TB', $storageOptions[1]['label']['en']);
        $this->assertSame('Up to 3 TB', $storageOptions[2]['label']['en']);
    }

    public function test_questions_follow_plan_edits_by_admin(): void
    {
        $this->makePlan('Basic', 20, ['students_limit' => '50', 'storage_limit_mb' => '5120']);
        $pro = $this->makePlan('Pro', 90, ['students_limit' => '200', 'storage_limit_mb' => '20480']);

        $before = $this->questionsPayload();
        $this->assertRequirementsMatch($before, 'storage', 'storage_limit_mb', [5120, 20480]);

        // الأدمن يرفع مساحة Pro من 20 إلى 40 جيجا — الشات يجب أن يتبع فوراً.
        $pro->features()->where('feature_key', 'storage_limit_mb')->update(['feature_value' => '40960']);

        $after = $this->questionsPayload();
        $this->assertRequirementsMatch($after, 'storage', 'storage_limit_mb', [5120, 40960]);
        $labels = collect(collect($after)->firstWhere('key', 'storage')['options'])->pluck('label.en')->all();
        $this->assertContains('Up to 40 GB', $labels);

        // قيم الخطط نفسها هي القيم الوحيدة المسموحة (لا أرقام مختلقة).
        $dbValues = SubscriptionPlan::where('is_active', true)
            ->with('features')->get()
            ->map(fn ($p) => (float) $p->features->firstWhere('feature_key', 'storage_limit_mb')->feature_value)
            ->sort()->values()->all();
        $optionValues = collect(collect($after)->firstWhere('key', 'storage')['options'])
            ->map(fn ($o) => (float) $o['requirements']['storage_limit_mb'])
            ->sort()->values()->all();
        $this->assertSame($dbValues, $optionValues);
    }

    public function test_small_needs_recommend_cheapest_plan(): void
    {
        $this->canonicalPlans();
        $questions = $this->questionsPayload();

        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 50,
            'storage' => 716800,
            'live_stream' => 'no',
            'team' => 1,
            'courses' => 3,
            'budget' => 'premium',
        ]));

        $this->assertSame('Starter', $res['recommended']['name']);
    }

    public function test_huge_needs_only_enterprise_satisfies(): void
    {
        $plans = $this->canonicalPlans();
        $questions = $this->questionsPayload();

        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 1000,
            'storage' => 3145728,
            'live_stream' => 'essential',
            'team' => 'unlimited',
            'courses' => 20,
            'budget' => 'premium',
        ]));

        $this->assertSame($plans['ent']->id, $res['recommended']['id']);
    }

    public function test_teacher_without_live_is_not_biased_to_live_plan(): void
    {
        $basic = $this->makePlan('Basic', 20, [
            'students_limit' => '100',
            'storage_limit_mb' => '102400',
            'live_stream_enabled' => 'false',
        ]);
        $pro = $this->makePlan('Pro', 90, [
            'students_limit' => '100',
            'storage_limit_mb' => '102400',
            'live_stream_enabled' => 'true',
        ]);
        $questions = $this->questionsPayload();

        // الإجابة "لا" على البث لا تفضّل خطة البث الأغلى — التعادل يُحسم للأرخص.
        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 100,
            'storage' => 102400,
            'live_stream' => 'no',
            'team' => 1,
            'courses' => 3,
            'budget' => 'premium',
        ]));

        $this->assertSame($basic->id, $res['recommended']['id']);
        $this->assertNotSame($pro->id, $res['recommended']['id']);
    }

    public function test_plan_capped_at_200_is_not_recommended_for_more_than_200(): void
    {
        $this->makePlan('Capped', 50, ['students_limit' => '200', 'storage_limit_mb' => '102400', 'live_stream_enabled' => 'false']);
        $enterprise = $this->makePlan('Unlimited', 150, ['students_limit' => 'unlimited', 'storage_limit_mb' => '102400', 'live_stream_enabled' => 'true', 'live_sessions_limit' => 'unlimited', 'teachers_limit' => 'unlimited', 'courses_limit' => 'unlimited']);
        $questions = $this->questionsPayload();

        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 'unlimited',
            'storage' => 102400,
            'live_stream' => 'essential',
            'team' => 'unlimited',
            'courses' => 'unlimited',
            'budget' => 'premium',
        ]));

        $this->assertSame($enterprise->id, $res['recommended']['id']);
        $this->assertNotSame('Capped', data_get($res, 'recommended.name'));
    }

    public function test_unlimited_plan_satisfies_every_numeric_requirement(): void
    {
        $unlimited = $this->makePlan('Unlimited', 150, [
            'students_limit' => 'unlimited',
            'storage_limit_mb' => 'unlimited',
            'live_stream_enabled' => 'true',
            'live_sessions_limit' => 'unlimited',
            'teachers_limit' => 'unlimited',
            'courses_limit' => 'unlimited',
        ]);
        $questions = $this->questionsPayload();

        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 'unlimited',
            'storage' => 'unlimited',
            'live_stream' => 'essential',
            'team' => 'unlimited',
            'courses' => 'unlimited',
            'budget' => 'premium',
        ]));

        $this->assertSame($unlimited->id, $res['recommended']['id']);
        $this->assertEqualsWithDelta(6.0, $res['recommended']['score'], 0.001);
        $this->assertEqualsWithDelta(6.0, $res['recommended']['max_score'], 0.001);
    }

    public function test_prices_and_names_come_live_from_db(): void
    {
        $plan = $this->makePlan('Live Cost', 77.5, ['students_limit' => '100']);
        $questions = $this->questionsPayload();

        $plan->update(['name' => 'Renamed', 'monthly_price' => 88.25]);

        // السعر الجديد يجب أن يظهر كشريحة "حتى 89 دولار" في خيارات الميزانية.
        $budgetReqs = collect(collect($this->questionsPayload())->firstWhere('key', 'budget')['options'])
            ->map(fn ($o) => $o['requirements']['monthly_price'] ?? null)->filter()->values()->all();
        $this->assertContains(88.25, $budgetReqs);

        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 100,
            'storage' => 716800,
            'live_stream' => 'sometimes',
            'team' => 3,
            'courses' => 20,
            'budget' => 'premium',
        ]));

        $found = collect($res['alternatives'] ?? [])->merge([$res['recommended']])->firstWhere('id', $plan->id);
        $this->assertNotNull($found, 'Renamed plan should appear in structured results');
        $this->assertSame('Renamed', $found['name']);
        $this->assertSame(88.25, $found['monthly_price']);
    }

    public function test_recommendation_is_subset_of_public_plans(): void
    {
        $this->canonicalPlans();
        $questions = $this->questionsPayload();

        $public = collect($this->getJson('/api/v1/public/plans')->assertOk()->json('plans'))
            ->pluck('name')->all();

        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 250,
            'storage' => 1572864,
            'live_stream' => 'sometimes',
            'team' => 3,
            'courses' => 7,
            'budget' => 59.00,
        ]));

        $proposed = collect([$res['recommended'], ...($res['alternatives'] ?? [])])->pluck('name')->unique()->all();

        foreach ($proposed as $name) {
            $this->assertContains($name, $public, "Plan {$name} must be an active, public plan");
        }
    }

    public function test_recommend_only_uses_active_plans(): void
    {
        $basic = $this->makePlan('Basic', 20, ['students_limit' => '100', 'storage_limit_mb' => '5120']);
        $hidden = $this->makePlan('Hidden', 999, ['students_limit' => 'unlimited', 'storage_limit_mb' => 'unlimited']);
        SubscriptionPlan::where('id', $hidden->id)->update(['is_active' => false]);
        $questions = $this->questionsPayload();

        // الخطة المخفية يجب ألا تظهر في خيارات الشات ولا في التوصية.
        foreach ($questions as $q) {
            foreach ($q['options'] as $o) {
                $this->assertNotSame(999, $o['requirements']['monthly_price'] ?? null);
            }
        }

        $res = $this->recommend($this->answersFor($questions, [
            'students_count' => 100,
            'storage' => 5120,
            'live_stream' => 'no',
            'team' => 1,
            'courses' => 3,
            'budget' => 'premium',
        ]));

        $this->assertSame($basic->id, $res['recommended']['id']);
    }

    public function test_questions_endpoint_exposes_option_requirements(): void
    {
        $this->canonicalPlans();
        $questions = $this->questionsPayload();

        $this->assertCount(count(config('plan-recommender.questions')), $questions);

        foreach ($questions as $q) {
            $this->assertArrayHasKey('key', $q);
            $this->assertArrayHasKey('question', $q);
            $this->assertArrayHasKey('options', $q);
            $this->assertNotEmpty($q['options']);
            foreach ($q['options'] as $o) {
                $this->assertArrayHasKey('requirements', $o, 'خيارات الأسئلة يجب أن تكشف متطلباتها لدعم الأدوات والاختبارات');
            }
        }
    }

    private function assertRequirementsMatch(array $questions, string $qKey, string $featureKey, array $expected): void
    {
        $actual = collect(collect($questions)->firstWhere('key', $qKey)['options'])
            ->map(fn ($o) => $o['requirements'][$featureKey] ?? null)
            ->filter(fn ($v) => $v !== null)
            ->map(fn ($v) => strtolower((string) $v) === 'unlimited' ? 'unlimited' : (float) $v)
            ->values()
            ->all();

        $expectedNorm = collect($expected)->map(fn ($v) => strtolower((string) $v) === 'unlimited' ? 'unlimited' : (float) $v)->all();

        $this->assertSame($expectedNorm, $actual, "قيم «{$featureKey}» في الشات غير مطابقة لقيم الخطط");
    }
}