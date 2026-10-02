<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Assistant Engine (اسألني)
    |--------------------------------------------------------------------------
    |
    | مساعد المعلم الذكي — هايبرد:
    | 1) يُبنى سياق حقيقي من بيانات المعلم (الخطة/الاستخدام/الوسائط/العدّادات).
    | 2) يُمرَّر إلى LLM (Anthropic أو OpenAI) لصياغة الإجابة بالعربية.
    | 3) إن لم يتوفر مفتاح → تراجع حتمي لمطابقة قواعد من قاعدة المعرفة
    |    (بدون أي تخمين — نقول "المعلومة غير متوفرة" عندما لا نجدها).
    |
    | لإيقاف التشغيل تماماً: ASSISTANT_ENABLED=false
    */

    'enabled' => (bool) env('ASSISTANT_ENABLED', true),

    // المزود: 'anthropic' | 'openai' | 'none'  (none = قواعد فقط بلا LLM)
    'provider' => env('ASSISTANT_PROVIDER', 'anthropic'),

    // مفتاح API — يُقرأ من البيئة فقط، ولا يُخزَّن في قاعدة البيانات.
    'api_key' => env('ASSISTANT_API_KEY', ''),

    // النموذج حسب المزود.
    'model' => env('ASSISTANT_MODEL', 'claude-sonnet-4-5'),

    // الحد الأقصى لرموز الإجابة.
    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 1024),

    // مهلة طلب الـ LLM (ثوانٍ) — يجب ألا تبطئ اللوحة.
    'timeout_seconds' => (int) env('ASSISTANT_TIMEOUT_SECONDS', 30),

    // مسار إضافي لنقاط نهاية الشركات (يبقى فارغاً افتراضياً).
    'base_url_anthropic' => env('ASSISTANT_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    'base_url_openai' => env('ASSISTANT_OPENAI_BASE_URL', 'https://api.openai.com'),
];
