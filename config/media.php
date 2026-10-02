<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Engine configuration
    |--------------------------------------------------------------------------
    */

    // عتبات تحذير الاستخدام بالنسبة المئوية (تظهر في لوحة المعلم).
    'warning_thresholds' => [
        'warning' => (int) env('MEDIA_WARNING_PCT', 80),
        'critical' => (int) env('MEDIA_CRITICAL_PCT', 90),
        'blocked' => 100,
    ],
];
