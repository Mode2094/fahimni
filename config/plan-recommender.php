<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan Recommender Assistant
    |--------------------------------------------------------------------------
    |
    | أسئلة مساعد اختيار الخطة — مبنية على المميزات الفعلية المميِّزة بين
    | الخطط في قاعدة البيانات (الطلاب، التخزين، البث، الفريق، الكورسات،
    | الميزانية). الميزات المفعلة للجميع (الهوية، التقارير، الخدمات...)
    | لا تُسأل عنها لأنها لا تفرق بين الخطط.
    |
    | أنواع القيم في المتطلبات:
    |   - boolean   : true/false
    |   - numeric   : حد أدنى ("unlimited" تلبي أي رقم)
    |   - monthly_price : حد أعلى للسعر الشهري
    |
    | اشتقاق الخيارات من قاعدة البيانات:
    | الأسئلة التي تحمل 'derive_from' (feature_key أو 'monthly_price') تشيّد
    | خياراتها في زمن التشغيل من قيم الخطط النشطة الفعلية في قاعدة البيانات،
    | فتصبح أرقام الشات (السعة، الطلاب، الفريق، الكورسات، السعر) مطابقة
    | تماماً لأرقام الخطط المخزنة — استبدال أي خيار بعدها يعطّل الشات.
    | القيم هنا احتياطية تُستخدم فقط عند غياب خطط نشطة (تُبقيها مطابقة
    | لقيم SubscriptionPlanSeeder الكنسية).
    */

    'questions' => [

        [
            'key' => 'students_count',
            'question' => ['ar' => 'كم عدد الطلاب عندك تقريباً؟', 'en' => 'How many students do you have roughly?'],
            'derive_from' => 'students_limit',
            'options' => [
                ['key' => 'less_than_50', 'label' => ['ar' => 'حتى 50', 'en' => 'Up to 50'], 'requirements' => ['students_limit' => 50]],
                ['key' => 'up_to_200', 'label' => ['ar' => 'حتى 200', 'en' => 'Up to 200'], 'requirements' => ['students_limit' => 200]],
                ['key' => 'more_than_200', 'label' => ['ar' => 'أكثر من 200', 'en' => 'More than 200'], 'requirements' => ['students_limit' => 'unlimited']],
            ],
        ],

        [
            'key' => 'storage',
            'question' => ['ar' => 'كم سعة تخزين للملفات والفيديوهات تحتاج؟', 'en' => 'How much storage do you need?'],
            'derive_from' => 'storage_limit_mb',
            'options' => [
                ['key' => 'small_5', 'label' => ['ar' => 'حتى 5 جيجا', 'en' => 'Up to 5 GB'], 'requirements' => ['storage_limit_mb' => 5120]],
                ['key' => 'medium_20', 'label' => ['ar' => 'حتى 20 جيجا', 'en' => 'Up to 20 GB'], 'requirements' => ['storage_limit_mb' => 20480]],
                ['key' => 'large_100', 'label' => ['ar' => 'حتى 100 جيجا', 'en' => 'Up to 100 GB'], 'requirements' => ['storage_limit_mb' => 102400]],
            ],
        ],

        [
            'key' => 'live_stream',
            'question' => ['ar' => 'هل تحتاج بث مباشر (لايف) مع طلابك؟', 'en' => 'Do you need live streaming with your students?'],
            'options' => [
                ['key' => 'no', 'label' => ['ar' => 'لا', 'en' => 'No'], 'requirements' => []],
                ['key' => 'sometimes', 'label' => ['ar' => 'أحياناً (حتى 50 جلسة شهرياً)', 'en' => 'Sometimes (up to 50 sessions/month)'], 'requirements' => ['live_stream_enabled' => true, 'live_sessions_limit' => 50], 'weight' => 0.7],
                ['key' => 'essential', 'label' => ['ar' => 'نعم، كثير (بث يومي أو طويل)', 'en' => 'Yes, a lot (daily or long)'], 'requirements' => ['live_stream_enabled' => true, 'live_sessions_limit' => 'unlimited']],
            ],
        ],

        [
            'key' => 'team',
            'question' => ['ar' => 'كم عدد المعلمين الذين يديرون المنصة معك؟', 'en' => 'How many teachers manage the platform with you?'],
            'derive_from' => 'teachers_limit',
            'options' => [
                ['key' => 'alone', 'label' => ['ar' => 'أنا وحدي', 'en' => 'Just me'], 'requirements' => ['teachers_limit' => 1]],
                ['key' => 'small_team', 'label' => ['ar' => '2-3 معلمين', 'en' => '2-3 teachers'], 'requirements' => ['teachers_limit' => 3]],
                ['key' => 'big_team', 'label' => ['ar' => 'أكثر من 3', 'en' => 'More than 3'], 'requirements' => ['teachers_limit' => 'unlimited']],
            ],
        ],

        [
            'key' => 'courses',
            'question' => ['ar' => 'كم كورساً تخطط لإنشائها؟', 'en' => 'How many courses do you plan to create?'],
            'derive_from' => 'courses_limit',
            'options' => [
                ['key' => 'few', 'label' => ['ar' => 'حتى 5 كورسات', 'en' => 'Up to 5 courses'], 'requirements' => ['courses_limit' => 5]],
                ['key' => 'many', 'label' => ['ar' => 'حتى 20 كورساً', 'en' => 'Up to 20 courses'], 'requirements' => ['courses_limit' => 20]],
                ['key' => 'many_more', 'label' => ['ar' => 'أكثر من 20 كورساً', 'en' => 'More than 20 courses'], 'requirements' => ['courses_limit' => 'unlimited']],
            ],
        ],

        [
            'key' => 'budget',
            'question' => ['ar' => 'شو ميزانيتك الشهرية تقريباً؟', 'en' => 'What is your monthly budget roughly?'],
            'derive_from' => 'monthly_price',
            'options' => [
                ['key' => 'cheap', 'label' => ['ar' => 'حتى 50 دينار', 'en' => 'Up to 50 JOD'], 'requirements' => ['monthly_price' => 49.99]],
                ['key' => 'balanced', 'label' => ['ar' => 'حتى 100 دينار', 'en' => 'Up to 100 JOD'], 'requirements' => ['monthly_price' => 99.99]],
                ['key' => 'premium', 'label' => ['ar' => 'أريد الأفضل مهما كان السعر', 'en' => 'The best, price is not an issue'], 'requirements' => []],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reasons per feature
    |--------------------------------------------------------------------------
    */

    'feature_reasons' => [
        'students_limit' => [
            'satisfied' => ['ar' => 'تستوعب عدد طلابك بكل راحة', 'en' => 'Comfortably handles your number of students'],
            'unsatisfied' => ['ar' => 'حد الطلاب أقل من عدد طلابك', 'en' => 'The student limit is below your student count'],
        ],
        'storage_limit_mb' => [
            'satisfied' => ['ar' => 'مساحة تخزين كافية لملفاتك وفيديوهاتك', 'en' => 'Enough storage for your files and videos'],
            'unsatisfied' => ['ar' => 'المساحة التخزينية قد لا تكفي محتواك', 'en' => 'Storage space may not be enough for your content'],
        ],
        'live_stream_enabled' => [
            'satisfied' => ['ar' => 'بث مباشر مفتوح لجلساتك التفاعلية', 'en' => 'Live streaming unlocked for interactive sessions'],
            'unsatisfied' => ['ar' => 'البث المباشر غير متاح في هذه الخطة', 'en' => 'Live streaming is not available in this plan'],
        ],
        'live_sessions_limit' => [
            'satisfied' => ['ar' => 'عدد جلسات البث الشهرية يكفي حاجتك', 'en' => 'Monthly live sessions cover your need'],
            'unsatisfied' => ['ar' => 'عدد جلسات البث الشهرية قد لا يكفي', 'en' => 'Monthly live sessions may not be enough'],
        ],
        'teachers_limit' => [
            'satisfied' => ['ar' => 'يتسع لفريق المعلمين لديك', 'en' => 'Accommodates your teaching team'],
            'unsatisfied' => ['ar' => 'عدد المعلمين المسموح أقل من فريقك', 'en' => 'The allowed teachers count is below your team'],
        ],
        'courses_limit' => [
            'satisfied' => ['ar' => 'يتسع لعدد الكورسات التي تخطط لها', 'en' => 'Accommodates your planned courses'],
            'unsatisfied' => ['ar' => 'حد الكورسات أقل مما تخطط له', 'en' => 'The courses limit is below your plan'],
        ],
        'monthly_price' => [
            'satisfied' => ['ar' => 'مناسبة لميزانيتك الشهرية', 'en' => 'Fits your monthly budget'],
            'unsatisfied' => ['ar' => 'أعلى من ميزانيتك الشهرية', 'en' => 'Above your monthly budget'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Highlight features shown on the result card
    |--------------------------------------------------------------------------
    */

    'highlights' => [
        'students_limit',
        'courses_limit',
        'storage_limit_mb',
        'live_stream_enabled',
        'live_sessions_limit',
        'teachers_limit',
        'paid_services_enabled',
        'appointments_enabled',
        'custom_branding_enabled',
        'export_reports',
        'white_label',
        'api_access',
        'support_level',
    ],
];
