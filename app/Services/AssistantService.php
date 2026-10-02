<?php

namespace App\Services;

use App\Support\AssistantKnowledge;
use App\Support\PlatformIndex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AssistantService — محرك "اسألني" (إعادة البناء).
 *
 * المعرفة ديناميكية من PlatformIndex (مشتقة من الكود: صفحات + ميزات + موارد
 * + سير عمل + تشخيص)، والسياق ذكي من AssistantContextService (يرسل فقط ما
 * يخص السؤال)، والإجابات مربوطة ببيانات المعلم الحالية.
 *
 * - READ ONLY: لا يغيّر أي بيانات.
 * - عزل تام بالمستأجر: كل البيانات من current_tenant.
 * - لا أسرار/اعتمادات تُرسل للـ LLM.
 * - LLM إن توفر مفتاح، وإلا تراجع حتمي مرتبط بخريطة المنصة الحقيقية.
 */
class AssistantService
{
    public function __construct(private readonly AssistantContextService $context) {}

    /**
     * @return array{answer: string, mode: string, matched: ?string, topic: ?string, link: ?string}
     */
    public function answer(Request $request, string $question, string $page = ''): array
    {
        $match = PlatformIndex::match($question);
        $topic = $match['topic'];

        $base = $this->context->base($request, $page);
        $context = $this->context->sliceForTopic($base, $topic, $question);

        $link = $this->topicLink($topic, $base['page']['course_id'] ?? null);

        if (config('assistant.enabled', true) && filled(config('assistant.api_key'))) {
            try {
                $answer = $this->llmAnswer($question, $context, $topic);

                return [
                    'answer' => $answer,
                    'mode' => 'llm',
                    'matched' => $topic['key'] ?? null,
                    'topic' => $topic['label_ar'] ?? null,
                    'link' => $link,
                ];
            } catch (\Throwable $e) {
                Log::warning('assistant.llm_failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->ruleAnswer($topic, $context, $link);
    }

    // ─── LLM ─────────────────────────────────────────

    private function llmAnswer(string $question, array $context, ?array $topic): string
    {
        $provider = config('assistant.provider', 'anthropic');
        $model = config('assistant.model', 'claude-sonnet-4-5');
        $key = (string) config('assistant.api_key');

        $payload = [
            'model' => $model,
            'max_tokens' => (int) config('assistant.max_tokens', 1024),
        ];

        $system = $this->systemPrompt();
        $userPrompt = $this->userPrompt($question, $context);

        if ($provider === 'anthropic') {
            $payload['system'] = $system;
            $payload['messages'] = [['role' => 'user', 'content' => $userPrompt]];

            $response = Http::timeout((int) config('assistant.timeout_seconds', 30))
                ->withHeaders([
                    'x-api-key' => $key,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                ->post(rtrim(config('assistant.base_url_anthropic'), '/').'/v1/messages', $payload);

            $data = $response->json();
            $text = collect($data['content'] ?? [])->firstWhere('type', 'text')['text'] ?? null;

            return $text ? trim((string) $text) : 'تعذر الحصول على إجابة من المساعد.';
        }

        $payload['messages'] = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $response = Http::timeout((int) config('assistant.timeout_seconds', 30))
            ->withToken($key)
            ->post(rtrim(config('assistant.base_url_openai'), '/').'/v1/chat/completions', $payload);

        $data = $response->json();

        return trim((string) ($data['choices'][0]['message']['content'] ?? '')) ?: 'تعذر الحصول على إجابة من المساعد.';
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'أنت "اسألني" — المساعد الداخلي لمعلم منصة Tadreesly (لوحة المعلم فقط).',
            'مهمتك: فهم + شرح + تشخيص + إرشاد فقط. أنت للقراءة فقط — لا تنفّذ أي تغيير.',
            'قواعد صارمة:',
            '1) اعتمد فقط على البيانات المرفقة في "السياق" (خطة المعلم، الاستخدام، الوسائط، التشخيص، خريطة المنصة، سير العمل). لا تخترع أرقاماً أو ميزات أو صفحات غير موجودة في السياق.',
            '2) إن لم تكن المعلومة متاحة في السياق: قل بوضوح "المعلومة غير متوفرة حالياً" واقترح سؤالاً قريباً يمكنك الإجابة عنه.',
            '3) أجب بالعربية، واضحة ومختصرة، مع خطوات مرقمة عند الحاجة، وبلا مصطلحات تقنية غير ضرورية. راعِ العامية التي يكتب بها المعلم.',
            '4) عندما تُفعل الميزة في الخطة: اشرح كيف يستخدمها. عندما تكون معطلة: قل إنها غير متاحة في الخطة الحالية وكيف يرقّي.',
            '5) لا تكشف أبداً أسراراً أو اعتمادات أو مفاتيح API — لا توجد في السياق أصلاً.',
            '6) خارج نطاق المنصة (طب/قانون/سياسة عامة...): اعتذر بلطف واذكر أنك مخصص للوحة المعلم فقط.',
        ]);
    }

    private function userPrompt(string $question, array $context): string
    {
        return "سؤال المعلم: {$question}\n\n--- السياق (من بيانات المعلم الفعلية) ---\n".$this->contextText($context);
    }

    /**
     * تحويل سياق المعلم إلى نص مختصر آمن للـ LLM (شريحة السؤال فقط + أساس).
     */
    private function contextText(array $c): string
    {
        if (empty($c['available'])) {
            return 'لا يتوفر سياق.';
        }

        $lines = [];
        $lines[] = 'الصفحة الحالية: '.($c['page']['label_ar'] ?? '-').' ('.($c['page']['path'] ?? '').')';
        $lines[] = 'المعلم: '.($c['user']['name'] ?? '-');

        $plan = $c['plan'];
        $lines[] = 'الخطة: '.($plan
            ? ($plan['plan_name'].' — الحالة: '.($plan['status'] ?? '').($plan['billing_cycle'] ? ' ('.$plan['billing_cycle'].')' : ''))
            : 'لا توجد خطة نشطة');

        if (! empty($c['expiring'])) {
            $lines[] = 'تنبيه: '.$c['expiring'];
        }

        if (! empty($c['slice'])) {
            $lines[] = 'بيانات السؤال:';
            $lines[] = $this->sliceText($c['slice']);
        }

        // معلومات عامة قليلة مساعدة (لا سلسلة بيانات كاملة).
        if (! empty($c['media_summary'])) {
            $m = $c['media_summary'];
            $lines[] = 'الوسائط (ملخص): تخزين '.
                ($m['storage']['unlimited'] ?? false ? $m['storage']['used_gb'].' GB (غير محدود)' : $m['storage']['used_gb'].' / '.$m['storage']['limit_gb'].' GB').
                ' — فيديوهات '.$this->shortEntry($m['videos'] ?? null, 'videos').
                ' — تكلفة شهرية تقديرية '.($c['estimated_cost'] ?? 0).' USD';
        }

        return implode("\n", $lines);
    }

    private function sliceText(array $slice): string
    {
        if (isset($slice['resource']) || isset($slice['entry'])) {
            return $this->entryLine($slice['entry'] ?? null, $slice['label_ar'] ?? $slice['resource'] ?? 'مورد');
        }

        if (isset($slice['feature'])) {
            return "الميزة '{$slice['feature']}': ".($slice['enabled'] ? 'مفعلة' : 'غير مفعلة').' (القيمة: '.($slice['limit'] ?? '-').')';
        }

        if (isset($slice['steps_ar'])) {
            return $slice['title_ar'].': '.implode(' → ', $slice['steps_ar']);
        }

        if (isset($slice['checks_ar'])) {
            $out = $slice['title_ar']."\n";
            foreach ($slice['checks_ar'] as $i => $check) {
                $out .= ($i + 1).'. '.$check."\n";
            }

            if (isset($slice['assets'])) {
                $out .= 'حالة الأصول: '.json_encode($slice['assets']['by_status'] ?? [], JSON_UNESCAPED_UNICODE)."\n";
                foreach (($slice['assets']['recent'] ?? []) as $a) {
                    $out .= "  - {$a['title']} ({$a['status']}) [{$a['provider']}]\n";
                }
            }
            if (isset($slice['statuses'])) {
                $out .= 'حالة الجلسات: '.json_encode($slice['statuses'], JSON_UNESCAPED_UNICODE)."\n";
            }
            if (isset($slice['gate'])) {
                $out .= 'ميزة البث/الرفع: '.($slice['gate'] ? 'مفعلة' : 'غير مفعلة')."\n";
            }
            if (isset($slice['usage'])) {
                foreach ($slice['usage'] as $r => $entry) {
                    $out .= $this->entryLine($entry, AssistantKnowledge::resourceLabels()[$r] ?? $r)."\n";
                }
            }

            return trim($out);
        }

        if (isset($slice['analytics'])) {
            return 'التحليلات الحقيقية: '.json_encode($slice['analytics'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($slice['card_statuses'])) {
            return 'البطاقات حسب الحالة: '.json_encode($slice['card_statuses'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($slice['tabs'])) {
            return 'تبويبات الإعدادات: '.implode('، ', $slice['tabs']);
        }

        return json_encode($slice, JSON_UNESCAPED_UNICODE);
    }

    // ─── تراجع حتمي (بدون LLM) ───────────────────────

    /**
     * إجابة مرتبطة بخريطة المنصة الحقيقية وبيانات المعلم — لا مطابقة سطحية.
     */
    private function ruleAnswer(?array $topic, array $context, ?string $link): array
    {
        if (! $topic) {
            return [
                'answer' => $this->noMatchAnswer(),
                'mode' => 'rules',
                'matched' => null,
                'topic' => null,
                'link' => null,
            ];
        }

        $kind = $topic['kind'];
        $slice = $context['slice'] ?? [];

        $answer = match ($kind) {
            'page' => $this->pageAnswer($topic, $slice, $context),
            'resource' => $this->resourceAnswer($topic, $slice),
            'feature' => $this->featureAnswer($topic, $slice),
            'workflow' => $this->workflowAnswer($topic, $slice, $context),
            'diag' => $this->diagAnswer($topic, $slice, $context),
            default => $this->noMatchAnswer(),
        };

        return [
            'answer' => $answer,
            'mode' => 'rules',
            'matched' => $topic['key'],
            'topic' => $topic['label_ar'],
            'link' => $link,
        ];
    }

    private function pageAnswer(array $topic, array $slice, array $context): string
    {
        $data = $topic['data'];
        $out = [];

        $out[] = '**'.$data['label_ar'].'** — '.$data['desc_ar'];
        $out[] = 'المسار: '.$data['path'];

        if (! empty($data['actions_ar'])) {
            $out[] = 'أبرز الإجراءات: '.implode('، ', $data['actions_ar']).'.';
        }

        // بيانات حقيقية خاصة بالصفحة.
        $real = $this->pageRealData($topic['key'], $slice, $context);

        if ($real !== '') {
            $out[] = $real;
        }

        $gate = $data['gate'] ?? null;
        if ($gate && isset($context['gates'][$gate])) {
            $out[] = 'حالة الميزة في خطتك: '.($context['gates'][$gate] ? 'مفعلة ✅' : 'غير مفعلة في خطتك الحالية ❌ — لتفعيلها رقِّ خطتك من صفحة الخطط.');
        }

        if (in_array($topic['key'], ['page:cards', 'page:live', 'page:analytics', 'page:storage', 'page:students', 'page:courses'], true)) {
            $out[] = 'يمكنك فتح الصفحة من رابط "افتح الصفحة" بالأسفل.';
        }

        return implode("\n\n", $out);
    }

    private function pageRealData(string $pageKey, array $slice, array $context): string
    {
        $lines = [];

        if (isset($slice['courses'])) {
            $lines[] = $this->entryLine($slice['courses'], 'الكورسات').($slice['published'] !== null ? ' (منشور: '.$slice['published'].')' : '');
        }

        if (isset($slice['students'])) {
            $lines[] = $this->entryLine($slice['students'], 'الطلاب');
        }

        if (isset($slice['cards'])) {
            $lines[] = $this->entryLine($slice['cards'], 'البطاقات');
            if (! empty($slice['card_statuses'])) {
                $parts = [];
                foreach ($slice['card_statuses'] as $status => $count) {
                    $parts[] = $status.' ('.$count.')';
                }
                $lines[] = 'حالة البطاقات: '.implode('، ', $parts).'.';
            }
        }

        if (isset($slice['live'])) {
            $lines[] = $this->entryLine($slice['live'], 'جلسات البث');
            if (! empty($slice['statuses'])) {
                $parts = [];
                foreach ($slice['statuses'] as $status => $count) {
                    $parts[] = $status.' ('.$count.')';
                }
                $lines[] = 'حالة الجلسات: '.implode('، ', $parts).'.';
            }
        }

        if (isset($slice['storage'])) {
            $lines[] = $this->entryLine($slice['storage'], 'مساحة التخزين');
        }

        if (isset($slice['teachers'])) {
            $lines[] = $this->entryLine($slice['teachers'], 'المعلمون (فريق العمل)');
        }

        if (isset($slice['exams'])) {
            $lines[] = $this->entryLine($slice['exams'], 'الاختبارات');
            if (($slice['needs_review'] ?? 0) > 0) {
                $lines[] = '🔔 يوجد '.$slice['needs_review'].' محاولة بانتظار تصحيحك.';
            }
        }

        if (isset($slice['questions'])) {
            $lines[] = $this->entryLine($slice['questions'], 'الأسئلة');
        }

        if (isset($slice['appointments'])) {
            $lines[] = $this->entryLine($slice['appointments'], 'المواعيد');
        }

        if (isset($slice['services'])) {
            $lines[] = $this->entryLine($slice['services'], 'الخدمات المدفوعة');
        }

        if (isset($slice['blog_posts'])) {
            $lines[] = $this->entryLine($slice['blog_posts'], 'مقالات المدونة');
        }

        if (isset($slice['assignments'])) {
            $lines[] = $this->entryLine($slice['assignments'], 'الواجبات');
        }

        if (isset($slice['scheduled_lessons'])) {
            $lines[] = 'الدروس المجدولة الحالية: '.$slice['scheduled_lessons'];
        }

        if (isset($slice['analytics'])) {
            $a = $slice['analytics'];
            $lines[] = 'بياناتك الحالية: '.$a['students'].' طالب · '.$a['courses'].' كورس · '.$a['enrollments'].' تسجيل · '.$a['attempts'].' محاولة (نجاح '.$a['pass_rate'].'%)';
            $lines[] = 'الإيراد الكلي: '.$a['revenue'].' · إيراد آخر 30 يوم: '.$a['period_revenue'].' · ساعات المشاهدة: '.$a['watch_hours'];
        }

        return implode("\n", $lines);
    }

    private function resourceAnswer(array $topic, array $slice): string
    {
        $data = $topic['data'];
        $resource = $data['resource'] ?? null;
        $label = $slice['label_ar'] ?? $data['label_ar'] ?? $resource;
        $entry = $slice['entry'] ?? null;

        if (! $entry) {
            return "لا تتوفر بيانات استخدام لـ **{$label}** حالياً.";
        }

        $out = "استخدامك الحالي لـ **{$label}**:\n";
        $out .= $this->entryLine($entry, $label);

        if (! empty($entry['unlimited'])) {
            $out .= "\n\nحدّك غير محدود في خطتك الحالية ✅.";
        } else {
            $pct = (int) ($entry['pct'] ?? 0);
            if ($pct >= 90) {
                $out .= "\n\n⚠️ وصلت إلى ".$pct."% من الحد — رقِّ خطتك لاستيعاب المزيد.";
            } elseif ($pct >= 80) {
                $out .= "\n\n⚠️ اقتربت من الحد (".$pct."%) — انتبه للاستخدام.";
            } else {
                $out .= "\n\nلا يزال لديك متسع ضمن خطتك.";
            }
        }

        return $out;
    }

    private function featureAnswer(array $topic, array $slice): string
    {
        $data = $topic['data'];
        $enabled = $slice['enabled'] ?? false;
        $featureKey = $data['key'] ?? null;

        $out = '**'.$data['ar'].'** — '.($data['desc_ar'] ?? '');

        if ($enabled) {
            $out .= "\n\n✅ هذه الميزة **مفعلة** في خطتك الحالية.";
        } else {
            $out .= "\n\n❌ هذه الميزة **غير مفعلة** في خطتك الحالية. لتفعيلها رقِّ خطتك من صفحة الخطط (pricing).";
        }

        return $out;
    }

    private function workflowAnswer(array $topic, array $slice, array $context): string
    {
        $out = '**'.$slice['title_ar'].'**'."\n\n";

        foreach (($slice['steps_ar'] ?? []) as $i => $step) {
            $out .= ($i + 1).'. '.$step."\n";
        }

        // ميزات قد تمنع السير العمل — من الواقع.
        $blocking = $this->workflowBlockingGates($topic['key'], $context);

        if ($blocking !== '') {
            $out .= "\n⚠️ ".$blocking;
        }

        return trim($out);
    }

    private function workflowBlockingGates(string $workflowKey, array $context): string
    {
        $gates = $context['gates'] ?? [];

        return match ($workflowKey) {
            'workflow:upload_video' => $gates['video_upload_enabled'] ?? false
                ? 'ميزة رفع الفيديو مفعلة في خطتك ✅.'
                : 'ميزة رفع الفيديو غير مفعلة في خطتك — لن تتمكن من الرفع حتى ترقّي.',
            'workflow:live_stream' => $gates['live_stream_enabled'] ?? false
                ? 'ميزة البث المباشر مفعلة في خطتك ✅.'
                : 'ميزة البث المباشر غير مفعلة في خطتك — لن تتمكن من البث حتى ترقّي.',
            'workflow:registration_card' => $gates['cards_enabled'] ?? false
                ? 'ميزة البطاقات مفعلة في خطتك ✅.'
                : 'ميزة بطاقات التسجيل غير مفعلة في خطتك — لن تتمكن من توليد البطاقات حتى ترقّي.',
            'workflow:team' => $gates['multi_teacher_enabled'] ?? false
                ? 'ميزة تعدد المعلمين مفعلة في خطتك ✅.'
                : 'ميزة تعدد المعلمين غير مفعلة في خطتك — لن تتمكن من دعوة معلمين حتى ترقّي.',
            default => '',
        };
    }

    private function diagAnswer(array $topic, array $slice, array $context): string
    {
        $out = '**'.$slice['title_ar'].'**'."\n\n";
        $out .= 'إليك ما يمكن فحصه (مع بياناتك الحالية):'."\n";

        foreach (($slice['checks_ar'] ?? []) as $i => $check) {
            $out .= ($i + 1).'. '.$check."\n";
        }

        $real = $this->diagRealData($topic['key'], $slice);

        if ($real !== '') {
            $out .= "\nبياناتك الحالية:\n".$real;
        }

        return trim($out);
    }

    private function diagRealData(string $diagKey, array $slice): string
    {
        $lines = [];

        if (in_array($diagKey, ['diag:video_not_visible', 'diag:upload_failed', 'diag:media_provider'], true)) {
            if (! empty($slice['assets']['by_status'])) {
                $parts = [];
                foreach ($slice['assets']['by_status'] as $status => $count) {
                    $parts[] = $status.' ('.$count.')';
                }
                $lines[] = 'أصول الفيديو: '.implode('، ', $parts).'.';
            }

            foreach (($slice['assets']['recent'] ?? []) as $a) {
                $lines[] = "  • {$a['title']} → {$a['status']} [{$a['provider']}]";
            }

            if (isset($slice['videos'])) {
                $lines[] = $this->entryLine($slice['videos'], 'الفيديوهات');
            }
            if (isset($slice['storage'])) {
                $lines[] = $this->entryLine($slice['storage'], 'التخزين');
            }
            if (isset($slice['gate'])) {
                $lines[] = 'ميزة رفع الفيديو: '.($slice['gate'] ? 'مفعلة' : 'غير مفعلة في خطتك');
            }
        }

        if ($diagKey === 'diag:live_not_working') {
            if (! empty($slice['statuses'])) {
                $parts = [];
                foreach ($slice['statuses'] as $status => $count) {
                    $parts[] = $status.' ('.$count.')';
                }
                $lines[] = 'حالة جلساتك: '.implode('، ', $parts).'.';
            }
            if (isset($slice['gate'])) {
                $lines[] = 'ميزة البث: '.($slice['gate'] ? 'مفعلة' : 'غير مفعلة في خطتك');
            }
        }

        if ($diagKey === 'diag:student_cannot_see_lesson') {
            if (isset($slice['enrollments'])) {
                $lines[] = 'عدد التسجيلات الحالي: '.$slice['enrollments'];
            }
            if (isset($slice['courses'])) {
                $lines[] = 'الكورسات المنشورة: '.$slice['courses'];
            }
        }

        if (in_array($diagKey, ['diag:quota_reached', 'diag:plan_upgrade'], true)) {
            if (isset($slice['plan'])) {
                $lines[] = 'خطتك الحالية: '.($slice['plan']['plan_name'] ?? 'لا توجد خطة نشطة');
            }
            foreach (($slice['usage'] ?? []) as $r => $entry) {
                $lines[] = $this->entryLine($entry, AssistantKnowledge::resourceLabels()[$r] ?? $r);
            }
        }

        if ($diagKey === 'diag:feature_hidden') {
            $off = [];
            foreach (($slice['gates'] ?? []) as $k => $v) {
                if (! $v) {
                    $off[] = $k;
                }
            }
            $lines[] = $off ? 'الميزات غير المفعلة في خطتك: '.implode('، ', $off).'.' : 'كل الميزات الأساسية مفعلة في خطتك ✅.';
        }

        return implode("\n", $lines);
    }

    private function noMatchAnswer(): string
    {
        $out = [];
        $out[] = 'لم أتمكن من تحديد موضوع السؤال بدقة (قد يكون خارج نطاق لوحة المعلم أو بصيغة غير واضحة).';
        $out[] = '';
        $out[] = 'يمكنني مساعدتك في مواضيع مثل:';
        $out[] = '• الكورسات والدروس ورفع الفيديو والاختبارات والواجبات.';
        $out[] = '• الطلاب، البطاقات، المواعيد، الخدمات، البث المباشر، فريق العمل.';
        $out[] = '• حدود خطتك واستخدامك والتخزين والترقية.';
        $out[] = '• المدونة، المجتمع، الإعلانات، التحليلات والتقارير، الإعدادات، الدعم.';
        $out[] = '';
        $out[] = 'أعد صياغة السؤال أو اكتبه بشكل أبسط، وسأجيبك بناءً على بيانات منصتك الفعلية.';

        return implode("\n", $out);
    }

    // ─── أدوات تنسيق ─────────────────────────────────

    private function entryLine(array $entry, string $label): string
    {
        if (($entry['unlimited'] ?? false)) {
            return "{$label}: {$entry['used']} (غير محدود)";
        }

        if (($entry['unit'] ?? 'count') === 'bytes') {
            $usedMb = round((float) ($entry['used'] ?? 0) / 1024 / 1024, 1);
            $limitMb = round((float) ($entry['limit'] ?? 0) / 1024 / 1024, 1);
            $remMb = round(max(0, $limitMb - $usedMb), 1);

            return "{$label}: {$usedMb} / {$limitMb} MB (متبقي {$remMb} MB)";
        }

        return "{$label}: {$entry['used']} / {$entry['limit']} (متبقي {$entry['remaining']})";
    }

    private function shortEntry(?array $entry, string $label): string
    {
        if (! $entry) {
            return $label.'؟';
        }

        return ($entry['unlimited'] ?? false) ? $entry['used'].' (∞)' : $entry['used'].'/'.$entry['limit'];
    }

    private function topicLink(?array $topic, ?string $courseId): ?string
    {
        if (! $topic) {
            return null;
        }

        $path = $topic['data']['path'] ?? null;

        if (! $path) {
            return null;
        }

        // روابط داخل لوحة المعلم فقط — آمنة (لا روابط خارجية/إدارية).
        if (str_contains($path, '{id}') && $courseId) {
            return str_replace('{id}', $courseId, $path);
        }

        return $path;
    }
}
