<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bunny Stream
    |--------------------------------------------------------------------------
    | الإعدادات البيئية (تُستخدم فقط عند عدم وجود اعتمادات في media_providers).
    | يُفضَّل إدخال الاعتمادات من لوحة الأدمن → Media Infrastructure (تُخزن
    | مشفرة AES-256 في قاعدة البيانات).
    |
    | للحصول على القيم: dashboard.bunny.net → Stream → Library Settings.
    */

    'library_id' => env('BUNNY_LIBRARY_ID', ''),

    'api_key' => env('BUNNY_API_KEY', ''),

    // مفتاح توقيع Token Authentication (Security Key) — اختياري لتفعيل
    // الروابط الموقّعة (يجب تفعيل Token Auth على نطاق البث من لوحة Bunny).
    'security_key' => env('BUNNY_SECURITY_KEY', ''),

    // تفعيل Bunny CDN Token Authentication على نطاق البث. لا تُفعّله قبل تفعيله
    // فعلاً على Pull Zone من لوحة Bunny وإلا ردّ المتصفح 404 لكل الفيديوهات.
    // عند التفعيل تُنتَج روابط HLS موقّعة (bcdn_token + expires).
    'token_auth' => env('BUNNY_TOKEN_AUTH', false),

    'webhook_secret' => env('BUNNY_WEBHOOK_SECRET', ''),

    // النطاق المخصص الذي يبث عليه (مثال: stream.yourdomain.com) — اختياري.
    'hostname' => env('BUNNY_HOSTNAME', ''),
];
