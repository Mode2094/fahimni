<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts a phone number that can be normalized to valid E.164. Ownership is
 * NOT proven here (no OTP); only a well-formed international number passes.
 */
class ValidPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isValid($value)) {
            $fail('يجب إدخال رقم هاتف صحيح مع رمز الدولة (مثال: ‎+962791234567).');
        }
    }
}
