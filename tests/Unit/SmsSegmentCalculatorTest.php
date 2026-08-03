<?php

namespace Tests\Unit;

use App\Services\Messaging\SmsSegmentCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmsSegmentCalculatorTest extends TestCase
{
    #[DataProvider('messages')]
    public function test_it_calculates_billable_sms_segments(string $message, int $expected): void
    {
        $this->assertSame($expected, (new SmsSegmentCalculator)->units($message));
    }

    public static function messages(): array
    {
        return [
            'empty' => ['', 0],
            'short gsm' => ['Hello from NetReverb', 1],
            'single gsm segment' => [str_repeat('a', 160), 1],
            'multipart gsm' => [str_repeat('a', 161), 2],
            'gsm extension characters count twice' => [str_repeat('^', 81), 2],
            'short unicode' => ['Your order is ready 😊', 1],
            'multipart unicode' => [str_repeat('😊', 71), 2],
        ];
    }
}
