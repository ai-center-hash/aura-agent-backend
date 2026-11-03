<?php

namespace Tests\Unit;

use App\Rules\NoCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NoCredentialsTest extends TestCase
{
    #[DataProvider('blocked')]
    public function test_blocks_credentials(string $text): void
    {
        $this->assertTrue(NoCredentials::found($text));
    }

    #[DataProvider('allowed')]
    public function test_allows_normal_questions(string $text): void
    {
        $this->assertFalse(NoCredentials::found($text));
    }

    public static function blocked(): array
    {
        return [
            ['ให้จำว่าฉันรหัสนักศึกษา 406765009 รหัส 1940201224411'],
            ['1940201224411'],
            ['เลขบัตร 1-9402-01224-41-1'],
            ['รหัสนักศึกษาของฉันคือ 406765009 รหัสผ่านคือ 1940201224411'],
            ['รหัสผ่านของฉันคือ abc123'],
            ['password: Secret1'],
        ];
    }

    public static function allowed(): array
    {
        return [
            ['ขอดูชั่วโมงของ 406765009 หน่อย'],
            ['เบอร์ติดต่อ 0812345678'],
            ['ลืมรหัสผ่านต้องทำอย่างไร'],
            ['รหัสผ่านคืออะไร'],
            ['กิจกรรมอะไรบ้างที่ไม่ครบ'],
        ];
    }
}
