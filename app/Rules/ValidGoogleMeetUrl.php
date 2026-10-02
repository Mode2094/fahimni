<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * التحقق من رابط حصة Google Meet يقدمه المعلم بنفسه.
 *
 * - عنوان صالح (https + نطاق meet.google.com تحديداً).
 * - مسار الحصة: /xxx[-xxx[-xxx]] برموز 3-4 أحرف لكل جزء.
 */
class ValidGoogleMeetUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('يجب أن يكون رابط الـ Google Meet عنوانًا صحيحًا (https://meet.google.com/...).');

            return;
        }

        if (strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
            $fail('يجب أن يستخدم رابط الـ Google Meet بروتوكول https.');

            return;
        }

        if (strtolower((string) parse_url($value, PHP_URL_HOST)) !== 'meet.google.com') {
            $fail('يجب أن يكون الرابط من النطاق meet.google.com.');

            return;
        }

        $path = (string) parse_url($value, PHP_URL_PATH);

        if (! preg_match('#^/[a-z0-9]{3,4}(-[a-z0-9]{3,4}){2}$#i', $path)) {
            $fail('رابط الـ Google Meet غير صالح (رمز الحصة ناقص أو خاطئ).');
        }
    }
}