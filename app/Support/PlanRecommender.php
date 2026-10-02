<?php

namespace App\Support;

use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Illuminate\Support\Collection;

/**
 * Plan Recommender — محرك اختيار الخطة الأنسب.
 *
 * يقرأ الأسئلة من config/plan-recommender.php، ويحول إجابات المعلم إلى
 * "متطلبات" feature-key => قيمة مطلوبة، ثم يسجل كل خطة اشتراك نشطة حسب
 * مدى تغطيتها لهذه المتطلبات (من plan_features المخزنة في قاعدة البيانات).
 * لا يوجد أي منطق مرتبط بأسماء الخطط — كل شيء مدفوع بالميزات الفعلية.
 *
 * الأسئلة الرقمية (الطلاب، التخزين، الفريق، الكورسات، الميزانية) تُشتق
 * خياراتها في زمن التشغيل من قيم الخطط النشطة نفسها، فلا يمكن أن يختلف
 * رقم في الشات عن رقم في الخطة مهما عدّل الأدمن.
 */
class PlanRecommender
{
    /**
     * الأسئلة العامة بمفردها، وخياراتها من الخطط النشطة الحية.
     *
     * @return array<int, array{key: string, question: array{ar: string, en: string}, options: array<int, array{key: string, label: array{ar: string, en: string}, requirements?: array<string, mixed>}>}>
     */
    public function questions(?Collection $plans = null): array
    {
        $plans ??= $this->activePlans();

        return collect(config('plan-recommender.questions'))
            ->map(fn (array $q) => [
                'key' => $q['key'],
                'question' => $q['question'],
                'options' => collect($this->optionsFor($q, $plans))
                    ->map(fn (array $o) => [
                        'key' => $o['key'],
                        'label' => $o['label'],
                        'requirements' => $o['requirements'],
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * احسب أفضل خطة لإجابات المعلم.
     *
     * @param  array<string, string>  $answers  خريطة question key => option key
     * @return array{recommended: array|null, alternatives: array<int, array>, questions: array<int, array>, unanswered: array<int, string>}
     */
    public function recommend(array $answers): array
    {
        $questions = config('plan-recommender.questions');
        $unanswered = collect($questions)->pluck('key')->diff(array_keys($answers))->values()->all();

        $currentPlanId = TenantSubscription::where('tenant_id', request()->header('X-Tenant-Id'))
            ->whereIn('status', ['active', 'trialing'])
            ->latest('starts_at')
            ->value('plan_id');

        $plans = $this->activePlans();

        $scored = $plans
            ->map(fn (SubscriptionPlan $plan) => $this->score($plan, $answers, $questions, $plans))
            ->filter(fn (array $p) => $p['score'] > 0)
            ->values()
            ->sortByDesc(fn (array $p) => [$p['score'], -$p['monthly_price']])
            ->values();

        $recommended = $scored->first();

        return [
            'recommended' => $recommended ? array_merge($recommended, ['is_current' => $recommended['id'] === $currentPlanId]) : null,
            'alternatives' => $scored->slice(1, 2)->values()->map(fn (array $p) => array_merge($p, ['is_current' => $p['id'] === $currentPlanId]))->all(),
            'questions' => $this->questions($plans),
            'unanswered' => $unanswered,
        ];
    }

    /**
     * الخطط النشطة مع ميزاتها — نفس مصدر بيانات صفحة الأسعار.
     */
    private function activePlans(): Collection
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->with('features')
            ->orderBy('monthly_price')
            ->get();
    }

    /**
     * خيارات سؤال واحد. للأسئلة ذات derive_from تُبنى من قيم الخطط
     * النشطة الفعلية؛ وإلا تُستخدم الخيارات الثابتة في الإعداد.
     *
     * @param  array<string, mixed>  $question
     * @return array<int, array{key: string, label: array{ar: string, en: string}, requirements: array<string, mixed>, weight: float}>
     */
    private function optionsFor(array $question, Collection $plans): array
    {
        $derived = isset($question['derive_from'])
            ? $this->derivedOptions($question, $plans)
            : [];

        $source = $derived !== [] ? $derived : ($question['options'] ?? []);

        return collect($source)
            ->map(fn (array $o) => [
                'key' => $o['key'],
                'label' => $o['label'],
                'requirements' => $o['requirements'] ?? [],
                'weight' => (float) ($o['weight'] ?? 1),
            ])
            ->values()
            ->all();
    }

    /**
     * ابنِ خيارات أسئلة رقمية من قيم الخطط النشطة الحية: قيمة محدودة v
     * تولّد "حتى v"، وعند وجود خطة غير محدودة تُضاف شريحة أخيرة
     * "أكثر من الأكبر" لا تُلبى إلا بخطة فعلاً unlimited.
     *
     * @param  array<string, mixed>  $question
     * @return array<int, array{key: string, label: array{ar: string, en: string}, requirements: array<string, mixed>}>
     */
    private function derivedOptions(array $question, Collection $plans): array
    {
        $featureKey = $question['derive_from'];

        $values = match ($featureKey) {
            'monthly_price' => $plans
                ->pluck('monthly_price')
                ->map(fn ($v) => (float) $v)
                ->unique()
                ->sort()
                ->values(),
            default => $plans
                ->flatMap(fn (SubscriptionPlan $p) => $p->features->where('feature_key', $featureKey)->pluck('feature_value'))
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => strtolower((string) $v) === 'unlimited' ? 'unlimited' : (float) $v)
                ->unique()
                ->sortBy(fn ($v) => $v === 'unlimited' ? PHP_FLOAT_MAX : $v)
                ->values(),
        };

        if ($values->isEmpty()) {
            return [];
        }

        $options = [];
        $maxFinite = $values->filter(fn ($v) => $v !== 'unlimited')->max();

        foreach ($values as $i => $value) {
            $isUnlimited = $value === 'unlimited';

            $options[] = [
                'key' => $isUnlimited ? 'unlimited_tier' : "tier_{$i}",
                'label' => $isUnlimited
                    ? ['ar' => "أكثر من ".$this->formatLabel('ar', $featureKey, $maxFinite), 'en' => "More than ".$this->formatLabel('en', $featureKey, $maxFinite)]
                    : ['ar' => "حتى ".$this->formatLabel('ar', $featureKey, $value), 'en' => "Up to ".$this->formatLabel('en', $featureKey, $value)],
                'requirements' => [$featureKey => $isUnlimited ? 'unlimited' : $value],
            ];
        }

        // الميزانية: "الأفضل مهما كان السعر" بلا متطلبات تلائم الجميع.
        if ($featureKey === 'monthly_price') {
            $options[] = [
                'key' => 'premium',
                'label' => ['ar' => 'أريد الأفضل مهما كان السعر', 'en' => 'The best, price is not an issue'],
                'requirements' => [],
            ];
        }

        return $options;
    }

    /**
     * نسّق حد الخطة ليظهر بالشات (سعة بالجيجا، سعر بالدينار، عدد، إلخ).
     */
    private function formatLabel(string $locale, string $featureKey, mixed $value): string
    {
        $number = (float) $value;

        if ($featureKey === 'storage_limit_mb') {
            $gb = $number / 1024;
            $whole = $gb == (int) $gb
                ? (string) (int) $gb
                : rtrim(rtrim(number_format($gb, 1, '.', ''), '0'), '.');

            return $whole.' '.($locale === 'ar' ? 'جيجا' : 'GB');
        }

        if ($featureKey === 'monthly_price') {
            $whole = (string) (int) round($number);

            return $whole.' '.($locale === 'ar' ? 'دينار' : 'JOD');
        }

        $rounded = round($number);
        $whole = $rounded == $number ? (string) (int) $rounded : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $whole;
    }

    /**
     * سجّل خطة واحدة مقابل المتطلبات المستخلصة من الإجابات.
     *
     * @param  array<string, string>  $answers
     * @param  array<int, array>  $questions
     * @return array{id: string, name: string, slug: string, description: string, monthly_price: float, yearly_price: ?float, currency: string, trial_days: int, score: float, max_score: float, reasons: array<int, array{ar: string, en: string}>, highlights: array<int, array<string, mixed>>, features: array<int, array<string, mixed>>}
     */
    private function score(SubscriptionPlan $plan, array $answers, array $questions, Collection $plans): array
    {
        $features = $plan->features->keyBy('feature_key');
        $reasons = config('plan-recommender.feature_reasons');

        $requirements = collect($questions)
            ->filter(fn (array $q) => isset($answers[$q['key']]))
            ->flatMap(function (array $q) use ($answers, $plans) {
                $option = collect($this->optionsFor($q, $plans))->firstWhere('key', $answers[$q['key']]);

                return $option
                    ? collect($option['requirements'])
                        ->map(fn ($value, $feature) => [
                            'feature' => $feature,
                            'value' => $value,
                            'weight' => $option['weight'],
                        ])
                    : collect();
            });

        $score = 0.0;
        $maxScore = 0.0;
        $matchedReasons = [];

        foreach ($requirements as $requirement) {
            $maxScore += $requirement['weight'];

            if ($this->satisfied($plan, $features, $requirement['feature'], $requirement['value'])) {
                $score += $requirement['weight'];
                $matchedReasons[$requirement['feature']] = $reasons[$requirement['feature']]['satisfied'] ?? null;
            }
        }

        $highlightKeys = collect(config('plan-recommender.highlights'));
        $highlights = collect(FeatureCatalog::localizeFeatures($plan->features))
            ->filter(fn (array $f) => $highlightKeys->contains($f['key']))
            ->values()
            ->all();

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'monthly_price' => (float) $plan->monthly_price,
            'yearly_price' => $plan->annual_price !== null ? (float) $plan->annual_price : null,
            'currency' => $plan->currency ?? 'JOD',
            'trial_days' => (int) $plan->trial_days,
            'score' => round($score, 2),
            'max_score' => round($maxScore, 2),
            'reasons' => array_values(array_filter($matchedReasons)),
            'highlights' => $highlights,
            // كل الميزات مترجمة (مفعّلة ✓ / معطلة ✗) لعرضها في بطاقة النتيجة.
            'features' => FeatureCatalog::localizeFeatures($plan->features),
        ];
    }

    /**
     * هل تلبي الخطة متطلب feature معين؟
     *
     * @param  Collection<int, mixed>  $features
     */
    private function satisfied(SubscriptionPlan $plan, Collection $features, string $feature, mixed $required): bool
    {
        // سقف السعر الشهري يُقارن مع سعر الخطة مباشرة.
        if ($feature === 'monthly_price') {
            return (float) $plan->monthly_price <= (float) $required;
        }

        $stored = $features->get($feature)?->feature_value;

        if ($stored === null) {
            return false;
        }

        // طلب "غير محدود" لا يتحقق إلا بخطة فعلاً غير محدودة.
        if ($required === 'unlimited') {
            return $stored === 'unlimited';
        }

        // قيمة "unlimited" تلبي أي متطلب رقمي.
        if ($stored === 'unlimited' && ! is_bool($required)) {
            return true;
        }

        if (is_bool($required)) {
            return filter_var($stored, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $required;
        }

        return (float) $stored >= (float) $required;
    }
}