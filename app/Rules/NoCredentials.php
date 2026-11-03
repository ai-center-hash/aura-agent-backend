<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects chat messages carrying a Thai national ID or a password, so they are
 * never saved, logged or sent on to AURA-AGENT / the LLM.
 */
class NoCredentials implements ValidationRule
{
    public const MESSAGE = 'กรุณาอย่าส่งรหัสผ่านหรือเลขบัตรประชาชนในแชท ระบบรู้ตัวตนของคุณจากการเข้าสู่ระบบ YRU Passport แล้ว';

    // 13 digits in a row, or written 1-2345-67890-12-3. Student codes (9) and phone numbers (10) don't match.
    private const NATIONAL_ID = '/(?<!\d)(?:\d{13}|\d-\d{4}-\d{5}-\d{2}-\d)(?!\d)/u';

    // "รหัสผ่านคือ abc123", "password: xyz". The value must hold a Latin letter or digit,
    // so questions like "ลืมรหัสผ่านคืออะไร" still go through.
    private const PASSWORD = '/(?:รหัสผ่าน|password|passwd)\s*(?:ของ(?:ฉัน|ผม)\s*)?(?:คือ|เป็น|:|=)\s*\S*[A-Za-z0-9]/iu';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::found($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function found(string $text): bool
    {
        return preg_match(self::NATIONAL_ID, $text) === 1 || preg_match(self::PASSWORD, $text) === 1;
    }
}
